<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE payable_opening_items (
 id bigserial PRIMARY KEY, source_key varchar(200) NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches(id),
 supplier_id bigint NOT NULL, reference varchar(160) NOT NULL, opening_date date NOT NULL,
 amount numeric(20,2) NOT NULL CHECK(amount>=0), actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER payable_opening_immutable BEFORE UPDATE OR DELETE ON payable_opening_items FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TABLE treasury_opening_balances (
 id bigserial PRIMARY KEY, source_key varchar(200) NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches(id),
 kind varchar(10) NOT NULL CHECK(kind IN ('cash','bank')), reference varchar(160) NOT NULL,
 opening_date date NOT NULL, amount numeric(20,2) NOT NULL CHECK(amount>=0), actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER treasury_opening_immutable BEFORE UPDATE OR DELETE ON treasury_opening_balances FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TABLE inventory_fifo_layers (
 id bigserial PRIMARY KEY, source_key varchar(200) NOT NULL UNIQUE,
 movement_id bigint NOT NULL UNIQUE REFERENCES inventory_movements(id), warehouse_id bigint NOT NULL REFERENCES inventory_warehouses(id),
 product_id bigint NOT NULL, received_on date NOT NULL, original_quantity numeric(24,8) NOT NULL CHECK(original_quantity>0),
 remaining_quantity numeric(24,8) NOT NULL CHECK(remaining_quantity>=0 AND remaining_quantity<=original_quantity),
 original_value numeric(20,2) NOT NULL CHECK(original_value>=0), remaining_value numeric(36,16) NOT NULL CHECK(remaining_value>=0),
 allocated_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(allocated_amount>=0 AND allocated_amount<=original_value),
 unit_cost numeric(36,16) NOT NULL CHECK(unit_cost>=0), created_at timestamptz NOT NULL DEFAULT now(),
 CHECK(remaining_quantity<>0 OR remaining_value=0)
);
CREATE INDEX inventory_fifo_order ON inventory_fifo_layers(warehouse_id,product_id,received_on,id);
CREATE TABLE inventory_fifo_allocations (
 id bigserial PRIMARY KEY, movement_id bigint NOT NULL REFERENCES inventory_movements(id), layer_id bigint NOT NULL REFERENCES inventory_fifo_layers(id),
 quantity numeric(24,8) NOT NULL CHECK(quantity>0), raw_value numeric(36,16) NOT NULL CHECK(raw_value>=0),
 amount numeric(20,2) NOT NULL CHECK(amount>=0), UNIQUE(movement_id,layer_id)
);
CREATE TRIGGER inventory_fifo_allocation_immutable BEFORE UPDATE OR DELETE ON inventory_fifo_allocations FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TABLE inventory_movement_journals (
 movement_id bigint PRIMARY KEY REFERENCES inventory_movements(id), journal_id bigint UNIQUE REFERENCES accounting_journals(id),
 amount numeric(20,2) NOT NULL CHECK(amount>=0), created_at timestamptz NOT NULL DEFAULT now(), CHECK((amount=0)=(journal_id IS NULL))
);
CREATE TRIGGER inventory_movement_journal_immutable BEFORE UPDATE OR DELETE ON inventory_movement_journals FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TABLE purchasing_order_issues (
 id bigserial PRIMARY KEY, order_id bigint NOT NULL UNIQUE REFERENCES purchasing_orders(id), source_version integer NOT NULL,
 document_id uuid NOT NULL UNIQUE REFERENCES system_official_documents(id), official_number varchar(100) NOT NULL UNIQUE,
 tax_snapshot jsonb NOT NULL, issued_by bigint NOT NULL REFERENCES users(id), issued_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER purchasing_order_issue_immutable BEFORE UPDATE OR DELETE ON purchasing_order_issues FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
ALTER TABLE purchasing_orders ADD COLUMN tax_mode varchar(10) NOT NULL DEFAULT 'exclusive' CHECK(tax_mode IN ('inclusive','exclusive'));
CREATE OR REPLACE FUNCTION purchasing_guard_tax_mode() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.status<>'draft' AND OLD.tax_mode<>NEW.tax_mode THEN RAISE EXCEPTION 'Reviewed tax mode immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchasing_tax_mode_guard BEFORE UPDATE ON purchasing_orders FOR EACH ROW EXECUTE FUNCTION purchasing_guard_tax_mode();
ALTER TABLE purchasing_receipts DROP CONSTRAINT receipt_unposted_only;
ALTER TABLE purchasing_receipts ADD COLUMN journal_id bigint UNIQUE REFERENCES accounting_journals(id);
ALTER TABLE purchasing_receipts ADD COLUMN posted_by bigint REFERENCES users(id);
ALTER TABLE purchasing_receipts ADD COLUMN posted_at timestamptz;
ALTER TABLE purchasing_receipts ADD CONSTRAINT receipt_financial_state CHECK(
 (status IN ('draft','integration_pending') AND inventory_document_id IS NULL AND journal_id IS NULL AND posted_at IS NULL AND posted_by IS NULL)
 OR (status='posted' AND inventory_document_id IS NULL AND journal_id IS NOT NULL AND posted_at IS NOT NULL AND posted_by IS NOT NULL AND posted_by<>created_by)
);
CREATE TABLE purchasing_receipt_postings (
 id bigserial PRIMARY KEY, receipt_id bigint NOT NULL REFERENCES purchasing_receipts(id), line_id bigint NOT NULL UNIQUE REFERENCES purchasing_receipt_lines(id),
 movement_id bigint NOT NULL UNIQUE REFERENCES inventory_movements(id), layer_id bigint NOT NULL UNIQUE REFERENCES inventory_fifo_layers(id),
 amount numeric(20,2) NOT NULL CHECK(amount>=0), posted_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER purchasing_receipt_posting_immutable BEFORE UPDATE OR DELETE ON purchasing_receipt_postings FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION guard_purchasing_receipt() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status='posted' THEN RAISE EXCEPTION 'Posted receiving immutable'; END IF;
 IF ROW(NEW.order_id,NEW.branch_id,NEW.warehouse_id,NEW.created_by,NEW.creation_key) IS DISTINCT FROM ROW(OLD.order_id,OLD.branch_id,OLD.warehouse_id,OLD.created_by,OLD.creation_key) THEN RAISE EXCEPTION 'Receipt source identity immutable'; END IF;
 IF OLD.status<>'draft' AND ROW(NEW.receipt_date,NEW.delivery_reference,NEW.notes) IS DISTINCT FROM ROW(OLD.receipt_date,OLD.delivery_reference,OLD.notes) THEN RAISE EXCEPTION 'Submitted receiving content immutable'; END IF;
 IF OLD.status<>NEW.status AND NOT ((OLD.status='draft' AND NEW.status='integration_pending') OR (OLD.status='integration_pending' AND NEW.status='posted')) THEN RAISE EXCEPTION 'Invalid receipt lifecycle'; END IF;
 IF NEW.status='posted' AND NOT EXISTS(SELECT 1 FROM purchasing_order_issues WHERE order_id=NEW.order_id) THEN RAISE EXCEPTION 'Official PO required'; END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void {}
};
