<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_guard_approved_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM sales_order_issues WHERE order_id=CASE WHEN TG_OP='DELETE' THEN OLD.sales_order_id ELSE NEW.sales_order_id END)
 OR (TG_OP='UPDATE' AND EXISTS(SELECT 1 FROM sales_order_issues WHERE order_id=OLD.sales_order_id)) THEN RAISE EXCEPTION 'Approved order lines immutable'; END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER sales_approved_line_guard BEFORE INSERT OR UPDATE OR DELETE ON sales_order_lines FOR EACH ROW EXECUTE FUNCTION sales_guard_approved_line();
CREATE TABLE sales_delivery_instructions (
 id bigserial PRIMARY KEY, order_id bigint NOT NULL REFERENCES sales_orders(id), creation_key uuid NOT NULL UNIQUE, payload_hash char(64) NOT NULL,
 reference varchar(500) NOT NULL, status varchar(12) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','posting','confirmed')),
 document_id uuid UNIQUE REFERENCES system_official_documents(id), created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL,
 confirmed_by bigint REFERENCES users(id), confirmed_at timestamptz,
 CHECK((status='confirmed')=(confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL AND document_id IS NOT NULL))
);
CREATE TABLE sales_delivery_instruction_lines (
 id bigserial PRIMARY KEY, instruction_id bigint NOT NULL REFERENCES sales_delivery_instructions(id), line_id bigint NOT NULL REFERENCES sales_order_lines(id),
 reservation_id bigint NOT NULL REFERENCES inventory_reservations(id), quantity numeric(24,8) NOT NULL CHECK(quantity>0), issue_key uuid NOT NULL UNIQUE,
 UNIQUE(instruction_id,line_id)
);
CREATE TRIGGER sales_delivery_line_immutable BEFORE UPDATE OR DELETE ON sales_delivery_instruction_lines FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION sales_guard_delivery_instruction() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Delivery instruction history immutable'; END IF;
 IF OLD.status='confirmed' OR (OLD.status='draft' AND NEW.status<>'posting') OR (OLD.status='posting' AND NEW.status<>'confirmed')
 OR ROW(NEW.order_id,NEW.creation_key,NEW.payload_hash,NEW.reference,NEW.created_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.order_id,OLD.creation_key,OLD.payload_hash,OLD.reference,OLD.created_by,OLD.created_at) THEN RAISE EXCEPTION 'Delivery source immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_delivery_instruction_guard BEFORE UPDATE OR DELETE ON sales_delivery_instructions FOR EACH ROW EXECUTE FUNCTION sales_guard_delivery_instruction();
CREATE OR REPLACE FUNCTION sales_require_delivery_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d sales_delivery_instructions%ROWTYPE; n bigint;
BEGIN
 SELECT * INTO d FROM sales_delivery_instructions WHERE id=NEW.id;
 IF d.status='posting' THEN RAISE EXCEPTION 'Delivery cannot commit partially'; END IF;
 IF d.status='confirmed' THEN
  SELECT count(*) INTO n FROM sales_delivery_instruction_lines WHERE instruction_id=d.id;
  IF n=0 OR EXISTS(SELECT 1 FROM sales_delivery_instruction_lines l WHERE l.instruction_id=d.id AND NOT EXISTS(
   SELECT 1 FROM inventory_movements m JOIN inventory_reservation_events e ON e.idempotency_key=l.issue_key
   WHERE e.reservation_id=l.reservation_id AND e.action='fulfilled' AND e.quantity=l.quantity AND m.idempotency_key='reservation:'||l.issue_key
   AND m.source_module='Sales' AND m.source_id=d.order_id::text AND m.source_line=l.line_id::text AND m.operation='reservation_issue' AND m.quantity=-l.quantity
  )) THEN RAISE EXCEPTION 'Confirmed delivery requires real Inventory issue'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER sales_delivery_commit AFTER INSERT OR UPDATE ON sales_delivery_instructions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sales_require_delivery_commit();
CREATE OR REPLACE FUNCTION inventory_require_sales_issue_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v numeric; qty numeric;
BEGIN
 IF NEW.operation<>'reservation_issue' THEN RETURN NEW; END IF;
 SELECT coalesce(sum(amount),0),coalesce(sum(quantity),0) INTO v,qty FROM inventory_fifo_allocations WHERE movement_id=NEW.id;
 IF qty<>-NEW.quantity OR NOT EXISTS(SELECT 1 FROM sales_delivery_instruction_lines l JOIN sales_delivery_instructions d ON d.id=l.instruction_id
 JOIN inventory_reservations r ON r.id=l.reservation_id WHERE d.status='confirmed' AND d.order_id::text=NEW.source_id AND l.line_id::text=NEW.source_line
 AND NEW.source_module='Sales' AND NEW.idempotency_key='reservation:'||l.issue_key AND NEW.quantity=-l.quantity AND r.warehouse_id=NEW.warehouse_id AND r.product_id=NEW.product_id)
 OR NOT EXISTS(SELECT 1 FROM inventory_movement_journals x LEFT JOIN accounting_journals j ON j.id=x.journal_id WHERE x.movement_id=NEW.id AND x.amount=v
 AND ((v=0 AND x.journal_id IS NULL) OR (v>0 AND j.status='posted' AND j.source_key='inventory-movement:'||NEW.id AND v=(SELECT sum(debit) FROM accounting_journal_lines WHERE journal_id=j.id) AND v=(SELECT sum(credit) FROM accounting_journal_lines WHERE journal_id=j.id)))) THEN RAISE EXCEPTION 'Sales stock issue requires confirmed source, FIFO and GL'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_sales_issue_commit AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_require_sales_issue_commit();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Delivery and valuation evidence requires forward migrations.');
    }
};
