<?php

use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE rp_credit_notes ADD COLUMN return_source varchar(60) UNIQUE;
CREATE TABLE rp_credit_note_return_lines (
 id bigserial PRIMARY KEY, credit_note_id bigint NOT NULL REFERENCES rp_credit_notes(id), invoice_line_id bigint NOT NULL, source_line_id bigint NOT NULL,
 quantity numeric(24,8) NOT NULL CHECK(quantity>0), net_amount numeric(20,2) NOT NULL CHECK(net_amount>=0), tax_amount numeric(20,2) NOT NULL CHECK(tax_amount>=0),
 UNIQUE(credit_note_id,invoice_line_id,source_line_id)
);
CREATE INDEX rp_credit_return_invoice_line ON rp_credit_note_return_lines(invoice_line_id,source_line_id);
CREATE TRIGGER rp_credit_return_line_history BEFORE UPDATE OR DELETE ON rp_credit_note_return_lines FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();

CREATE TABLE sales_returns (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, payload_hash varchar(64) NOT NULL, branch_id bigint NOT NULL REFERENCES branches(id),
 order_id bigint NOT NULL REFERENCES sales_orders(id), delivery_id bigint NOT NULL REFERENCES sales_delivery_instructions(id), customer_id bigint NOT NULL, customer_name varchar(255) NOT NULL,
 reason_code varchar(30) NOT NULL CHECK(reason_code IN ('defective','wrong_item','customer_refused','excess_quantity','other')), reason text NOT NULL,
 status varchar(15) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','posting','posted','rejected')),
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), rejected_by bigint REFERENCES users(id), rejection_reason text, posted_at timestamptz,
 official_document_id uuid UNIQUE REFERENCES system_official_documents(id), official_number varchar(80) UNIQUE, credit_notes jsonb NOT NULL DEFAULT '[]'::jsonb,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK(approved_by IS NULL OR approved_by<>created_by), CHECK(rejected_by IS NULL OR rejected_by<>created_by),
 CHECK((status='posted')=(posted_at IS NOT NULL AND official_document_id IS NOT NULL AND official_number IS NOT NULL))
);
CREATE INDEX sales_returns_branch_status ON sales_returns(branch_id,status);
CREATE TABLE sales_return_lines (
 id bigserial PRIMARY KEY, return_id bigint NOT NULL REFERENCES sales_returns(id), delivery_line_id bigint NOT NULL REFERENCES sales_delivery_instruction_lines(id),
 line_id bigint NOT NULL REFERENCES sales_order_lines(id), product_id bigint NOT NULL, description text NOT NULL, quantity numeric(24,8) NOT NULL CHECK(quantity>0),
 condition varchar(10) NOT NULL CHECK(condition IN ('sellable','damaged')), serials jsonb NOT NULL DEFAULT '[]'::jsonb CHECK(jsonb_typeof(serials)='array'), stock_key uuid NOT NULL UNIQUE,
 UNIQUE(return_id,delivery_line_id)
);
CREATE INDEX sales_return_lines_source ON sales_return_lines(delivery_line_id);
CREATE TABLE sales_return_postings (
 id bigserial PRIMARY KEY, line_id bigint NOT NULL REFERENCES sales_return_lines(id), movement_id bigint NOT NULL UNIQUE REFERENCES inventory_movements(id),
 amount numeric(20,2) NOT NULL CHECK(amount>=0), isolation_id bigint REFERENCES inventory_isolations(id), created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE purchasing_returns (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, payload_hash varchar(64) NOT NULL, branch_id bigint NOT NULL REFERENCES branches(id),
 receipt_id bigint NOT NULL REFERENCES purchasing_receipts(id), order_id bigint NOT NULL REFERENCES purchasing_orders(id), supplier_id bigint NOT NULL, supplier_name varchar(255) NOT NULL,
 reason_code varchar(30) NOT NULL CHECK(reason_code IN ('defective','wrong_item','excess_quantity','expired','other')), reason text NOT NULL,
 status varchar(15) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','posting','posted','rejected')),
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), rejected_by bigint REFERENCES users(id), rejection_reason text, posted_at timestamptz,
 official_document_id uuid UNIQUE REFERENCES system_official_documents(id), official_number varchar(80) UNIQUE, credit_notes jsonb NOT NULL DEFAULT '[]'::jsonb,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK(approved_by IS NULL OR approved_by<>created_by), CHECK(rejected_by IS NULL OR rejected_by<>created_by),
 CHECK((status='posted')=(posted_at IS NOT NULL AND official_document_id IS NOT NULL AND official_number IS NOT NULL))
);
CREATE INDEX purchasing_returns_branch_status ON purchasing_returns(branch_id,status);
CREATE TABLE purchasing_return_lines (
 id bigserial PRIMARY KEY, return_id bigint NOT NULL REFERENCES purchasing_returns(id), receipt_line_id bigint NOT NULL REFERENCES purchasing_receipt_lines(id),
 product_id bigint NOT NULL, description text NOT NULL, quantity numeric(24,8) NOT NULL CHECK(quantity>0),
 serials jsonb NOT NULL DEFAULT '[]'::jsonb CHECK(jsonb_typeof(serials)='array'), stock_key uuid NOT NULL UNIQUE, UNIQUE(return_id,receipt_line_id)
);
CREATE INDEX purchasing_return_lines_source ON purchasing_return_lines(receipt_line_id);
CREATE TABLE purchasing_return_postings (
 id bigserial PRIMARY KEY, line_id bigint NOT NULL REFERENCES purchasing_return_lines(id), movement_id bigint NOT NULL UNIQUE REFERENCES inventory_movements(id),
 amount numeric(20,2) NOT NULL CHECK(amount>=0), created_at timestamptz NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION trade_return_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Trade return history cannot be deleted'; END IF;
 IF OLD.status IN ('posted','rejected') THEN RAISE EXCEPTION 'Finished trade return history is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_return_history BEFORE UPDATE OR DELETE ON sales_returns FOR EACH ROW EXECUTE FUNCTION trade_return_history_guard();
CREATE TRIGGER purchasing_return_history BEFORE UPDATE OR DELETE ON purchasing_returns FOR EACH ROW EXECUTE FUNCTION trade_return_history_guard();
CREATE TRIGGER sales_return_line_history BEFORE UPDATE OR DELETE ON sales_return_lines FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER sales_return_posting_history BEFORE UPDATE OR DELETE ON sales_return_postings FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER purchasing_return_line_history BEFORE UPDATE OR DELETE ON purchasing_return_lines FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER purchasing_return_posting_history BEFORE UPDATE OR DELETE ON purchasing_return_postings FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();

CREATE OR REPLACE FUNCTION inventory_require_trade_return() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o inventory_movements%ROWTYPE; c inventory_fifo_corrections%ROWTYPE; v inventory_movement_journals%ROWTYPE;
BEGIN
 IF NEW.operation NOT IN ('sales_return','supplier_return') THEN RETURN NEW; END IF;
 SELECT * INTO o FROM inventory_movements WHERE id=NEW.reversal_id;
 SELECT * INTO c FROM inventory_fifo_corrections WHERE movement_id=NEW.id;
 SELECT * INTO v FROM inventory_movement_journals WHERE movement_id=NEW.id;
 IF o.id IS NULL OR c.movement_id IS NULL OR v.movement_id IS NULL OR c.original_movement_id<>o.id OR NEW.warehouse_id<>o.warehouse_id
  OR NEW.product_id<>o.product_id OR abs(NEW.quantity)<>c.quantity OR v.amount<>c.amount THEN RAISE EXCEPTION 'Trade return must keep its original stock source and cost'; END IF;
 IF NEW.operation='sales_return' THEN
  IF NEW.quantity<=0 OR o.operation<>'reservation_issue' OR o.source_module<>'Sales'
   OR NOT EXISTS(SELECT 1 FROM sales_return_postings p JOIN sales_return_lines l ON l.id=p.line_id JOIN sales_returns r ON r.id=l.return_id
    WHERE p.movement_id=NEW.id AND p.amount=c.amount AND r.status='posted' AND r.order_id::text=o.source_id AND l.line_id::text=o.source_line)
   OR NOT EXISTS(SELECT 1 FROM inventory_fifo_layers f JOIN inventory_fifo_layers s ON s.id=c.source_layer_id
    WHERE f.movement_id=NEW.id AND f.original_quantity=c.quantity AND f.original_value=c.amount AND f.received_on=s.received_on AND f.unit_cost IS NOT DISTINCT FROM s.unit_cost)
  THEN RAISE EXCEPTION 'Customer return requires its posted return document and original FIFO cost'; END IF;
 ELSE
  IF NEW.quantity>=0 OR o.operation<>'purchase_receipt' OR o.source_module<>'Purchasing'
   OR NOT EXISTS(SELECT 1 FROM purchasing_return_postings p JOIN purchasing_return_lines l ON l.id=p.line_id JOIN purchasing_returns r ON r.id=l.return_id
    WHERE p.movement_id=NEW.id AND p.amount=c.amount AND r.status='posted' AND r.receipt_id::text=o.source_id AND l.receipt_line_id::text=o.source_line)
   OR EXISTS(SELECT 1 FROM inventory_fifo_allocations WHERE movement_id=NEW.id AND layer_id<>c.source_layer_id)
  THEN RAISE EXCEPTION 'Supplier return requires its posted return document and original receipt layer'; END IF;
 END IF;
 IF c.amount>0 AND NOT EXISTS(SELECT 1 FROM accounting_journals j WHERE j.id=v.journal_id AND j.status='posted' AND j.source_key='inventory-movement:'||NEW.id
  AND c.amount=(SELECT sum(debit) FROM accounting_journal_lines WHERE journal_id=j.id) AND c.amount=(SELECT sum(credit) FROM accounting_journal_lines WHERE journal_id=j.id))
 THEN RAISE EXCEPTION 'Trade return requires its balanced stock journal'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_trade_return_commit AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_require_trade_return();
SQL);
        // Returned serial cost keeps the same reconciliation as service returns.
        $definition = DB::selectOne("SELECT pg_get_functiondef('inventory_require_serial_fifo()'::regprocedure) AS body")->body;
        $list = "'workshop_return'";
        abort_unless(str_contains($definition, $list), 500, 'Serial FIFO guard changed unexpectedly.');
        DB::unprepared(str_replace($list, $list.",'sales_return'", $definition));
        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        // Retain posted trade-return documents, credit lines and stock evidence; correct through new documents instead.
        throw new RuntimeException('Retain posted financial evidence: this migration cannot be rolled back.');
    }
};
