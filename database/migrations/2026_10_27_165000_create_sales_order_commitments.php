<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE sales_orders DROP CONSTRAINT sales_order_draft_state;
ALTER TABLE sales_orders ADD CONSTRAINT sales_order_draft_state CHECK(status IN ('draft','pending_approval','cancelled','approved'));
ALTER TABLE sales_order_reviews DROP CONSTRAINT sales_order_review_state;
ALTER TABLE sales_order_reviews ADD CONSTRAINT sales_order_review_state CHECK(status IN ('pending','withdrawn','rejected','approved'));
CREATE OR REPLACE FUNCTION guard_sales_order_review_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Sales Order review history is immutable'; END IF;
 IF OLD.status<>'pending' OR NEW.status NOT IN ('withdrawn','rejected','approved') THEN RAISE EXCEPTION 'Sales Order review decision is immutable'; END IF;
 IF ROW(NEW.sales_order_id,NEW.target_version,NEW.content_hash,NEW.snapshot,NEW.requested_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.sales_order_id,OLD.target_version,OLD.content_hash,OLD.snapshot,OLD.requested_by,OLD.created_at) THEN RAISE EXCEPTION 'Sales Order review snapshot is immutable'; END IF;
 IF NEW.decided_by IS NULL OR NEW.decided_at IS NULL OR (NEW.status='approved' AND NEW.decided_by=NEW.requested_by) THEN RAISE EXCEPTION 'Independent review decision requires attribution'; END IF;
 RETURN NEW;
END $$;
CREATE TABLE sales_order_issues (
 order_id bigint PRIMARY KEY REFERENCES sales_orders(id), review_id bigint NOT NULL UNIQUE REFERENCES sales_order_reviews(id),
 document_id uuid NOT NULL UNIQUE REFERENCES system_official_documents(id), commercial_snapshot jsonb NOT NULL, tax_snapshot jsonb NOT NULL,
 approved_by bigint NOT NULL REFERENCES users(id), approved_at timestamptz NOT NULL
);
CREATE TABLE sales_order_allocations (
 line_id bigint PRIMARY KEY REFERENCES sales_order_lines(id), order_id bigint NOT NULL REFERENCES sales_orders(id),
 base_quantity numeric(24,8) NOT NULL CHECK(base_quantity>0), warehouse_id bigint NOT NULL REFERENCES inventory_warehouses(id),
 reservation_id bigint NOT NULL UNIQUE REFERENCES inventory_reservations(id)
);
CREATE TRIGGER sales_order_issue_immutable BEFORE UPDATE OR DELETE ON sales_order_issues FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER sales_order_allocation_immutable BEFORE UPDATE OR DELETE ON sales_order_allocations FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION sales_guard_approved_order() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' AND EXISTS(SELECT 1 FROM sales_order_issues WHERE order_id=OLD.id) THEN RAISE EXCEPTION 'Approved order immutable'; END IF;
 IF TG_OP='UPDATE' AND OLD.status='approved' AND to_jsonb(NEW)-ARRAY['version','updated_at','updated_by'] IS DISTINCT FROM to_jsonb(OLD)-ARRAY['version','updated_at','updated_by'] THEN RAISE EXCEPTION 'Approved order commercial content immutable'; END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER sales_approved_order_guard BEFORE UPDATE OR DELETE ON sales_orders FOR EACH ROW EXECUTE FUNCTION sales_guard_approved_order();
CREATE OR REPLACE FUNCTION sales_require_order_commitments() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status='approved' AND (NOT EXISTS(SELECT 1 FROM sales_order_issues i JOIN sales_order_reviews r ON r.id=i.review_id WHERE i.order_id=NEW.id AND r.sales_order_id=NEW.id AND r.status='approved' AND r.decided_by=i.approved_by AND r.requested_by<>i.approved_by)
 OR EXISTS(SELECT 1 FROM sales_order_lines l WHERE l.sales_order_id=NEW.id AND NOT EXISTS(SELECT 1 FROM sales_order_allocations a JOIN inventory_reservations r ON r.id=a.reservation_id WHERE a.line_id=l.id AND a.order_id=NEW.id AND r.source_module='sales' AND r.source_id=NEW.id::text AND r.source_line=l.id::text AND r.product_id=l.product_id AND r.requested=a.base_quantity AND r.warehouse_id=a.warehouse_id))) THEN RAISE EXCEPTION 'Approved order requires immutable review and real Inventory commitments'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER sales_order_commitments AFTER INSERT OR UPDATE ON sales_orders DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sales_require_order_commitments();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Approved commercial records require forward migrations.');
    }
};
