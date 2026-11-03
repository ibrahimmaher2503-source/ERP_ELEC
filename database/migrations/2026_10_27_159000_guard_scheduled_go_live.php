<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION accounting_financial_posting_gate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE setup accounting_activation%ROWTYPE;
BEGIN
 IF NEW.status='posted' THEN
  SELECT * INTO setup FROM accounting_activation WHERE id=1 FOR SHARE;
  IF setup.go_live_date IS NULL OR NEW.journal_date<setup.go_live_date THEN RAISE EXCEPTION 'Before company Go-Live'; END IF;
  IF NEW.opening_batch_id IS NULL AND (setup.activated_at IS NULL OR (now() AT TIME ZONE 'Africa/Cairo')::date<setup.go_live_date) THEN RAISE EXCEPTION 'Financial posting is disabled or Go-Live has not arrived'; END IF;
  IF NEW.opening_batch_id IS NOT NULL AND (setup.activated_at IS NOT NULL OR NEW.journal_date<>setup.go_live_date OR NOT EXISTS(SELECT 1 FROM accounting_opening_batches b WHERE b.id=NEW.opening_batch_id AND b.status='approved' AND b.configuration_version=setup.version)) THEN RAISE EXCEPTION 'Invalid opening posting'; END IF;
 END IF;
 RETURN NEW;
END $$;
ALTER TABLE purchasing_receipts ADD COLUMN capitalized_amount numeric(20,2) CHECK(capitalized_amount>=0);
ALTER TABLE purchasing_receipts DROP CONSTRAINT receipt_financial_state;
ALTER TABLE purchasing_receipts ADD CONSTRAINT receipt_financial_state CHECK(
 (status IN ('draft','integration_pending') AND inventory_document_id IS NULL AND journal_id IS NULL AND capitalized_amount IS NULL AND posted_at IS NULL AND posted_by IS NULL)
 OR (status='posted' AND inventory_document_id IS NULL AND capitalized_amount IS NOT NULL AND posted_at IS NOT NULL AND posted_by IS NOT NULL AND posted_by<>created_by AND ((capitalized_amount=0 AND journal_id IS NULL) OR (capitalized_amount>0 AND journal_id IS NOT NULL)))
);
CREATE OR REPLACE FUNCTION purchasing_reconcile_financial_receipt() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rid bigint; r purchasing_receipts%ROWTYPE; n integer; expected integer; value numeric; j accounting_journals%ROWTYPE; dr numeric; cr numeric;
BEGIN
 IF TG_TABLE_NAME='purchasing_receipts' THEN rid:=NEW.id; ELSE rid:=NEW.receipt_id; END IF;
 SELECT * INTO r FROM purchasing_receipts WHERE id=rid;
 IF r.status<>'posted' THEN RETURN NEW; END IF;
 SELECT count(*) INTO expected FROM purchasing_receipt_lines WHERE receipt_id=rid;
 SELECT count(*),coalesce(sum(amount),0) INTO n,value FROM purchasing_receipt_postings WHERE receipt_id=rid;
 IF expected=0 OR n<>expected OR value<>r.capitalized_amount THEN RAISE EXCEPTION 'Receipt line/value reconciliation failed'; END IF;
 IF EXISTS(SELECT 1 FROM purchasing_receipt_postings p JOIN purchasing_receipt_lines l ON l.id=p.line_id JOIN purchasing_order_lines o ON o.id=l.order_line_id JOIN inventory_movements m ON m.id=p.movement_id JOIN inventory_fifo_layers f ON f.id=p.layer_id
  WHERE p.receipt_id=rid AND (l.receipt_id<>rid OR m.quantity<>l.quantity OR m.warehouse_id<>r.warehouse_id OR m.product_id<>o.product_id OR m.base_unit_id<>o.unit_id OR m.operation<>'purchase_receipt' OR m.source_module<>'Purchasing' OR m.source_id<>rid::text OR m.source_line<>l.id::text OR f.movement_id<>m.id OR f.original_value<>p.amount)) THEN RAISE EXCEPTION 'Receipt stock/FIFO source reconciliation failed'; END IF;
 IF r.capitalized_amount>0 THEN
  SELECT * INTO j FROM accounting_journals WHERE id=r.journal_id;
  SELECT count(*),coalesce(sum(debit),0),coalesce(sum(credit),0) INTO n,dr,cr FROM accounting_journal_lines WHERE journal_id=j.id;
  IF j.id IS NULL OR j.status<>'posted' OR j.source_key IS DISTINCT FROM 'purchase-receipt:'||rid OR j.branch_id<>r.branch_id OR j.journal_date<>r.receipt_date OR j.created_by<>r.created_by OR j.approved_by<>r.posted_by OR n<>2 OR dr<>value OR cr<>value THEN RAISE EXCEPTION 'Receipt GRNI journal reconciliation failed'; END IF;
  IF NOT EXISTS(SELECT 1 FROM accounting_journal_lines l JOIN accounting_account_mappings a ON a.account_id=l.account_id WHERE l.journal_id=j.id AND a.purpose='inventory' AND l.debit=value) OR NOT EXISTS(SELECT 1 FROM accounting_journal_lines l JOIN accounting_account_mappings a ON a.account_id=l.account_id WHERE l.journal_id=j.id AND a.purpose='grni' AND l.credit=value) THEN RAISE EXCEPTION 'Receipt GRNI accounts mismatch'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER purchasing_receipt_finance_reconcile AFTER INSERT OR UPDATE ON purchasing_receipts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION purchasing_reconcile_financial_receipt();
CREATE CONSTRAINT TRIGGER purchasing_receipt_evidence_reconcile AFTER INSERT ON purchasing_receipt_postings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION purchasing_reconcile_financial_receipt();
CREATE OR REPLACE FUNCTION inventory_require_financial_commit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.operation='purchase_receipt' THEN
  IF NOT EXISTS(SELECT 1 FROM purchasing_receipt_postings p JOIN purchasing_receipts r ON r.id=p.receipt_id WHERE p.movement_id=NEW.id AND r.status='posted') THEN RAISE EXCEPTION 'Purchase stock must commit with its source/FIFO/GRNI'; END IF;
 ELSIF NEW.operation='financial_opening' THEN
  IF NOT EXISTS(SELECT 1 FROM accounting_opening_batches b JOIN accounting_opening_lines l ON l.batch_id=b.id JOIN accounting_activation a ON a.id=1 JOIN inventory_fifo_layers f ON f.movement_id=NEW.id WHERE b.id::text=NEW.source_id AND b.status='posted' AND a.activated_at IS NOT NULL AND l.kind='inventory' AND l.reference=NEW.source_line AND l.product_id=NEW.product_id AND l.warehouse_id=NEW.warehouse_id AND l.quantity=NEW.quantity AND f.original_value=l.amount) THEN RAISE EXCEPTION 'Opening stock must commit with approved company activation'; END IF;
 ELSIF NEW.operation IN ('issue_post','adjustment_post') AND NEW.quantity<0 THEN
  IF NOT EXISTS(SELECT 1 FROM inventory_movement_journals v LEFT JOIN accounting_journals j ON j.id=v.journal_id WHERE v.movement_id=NEW.id AND v.amount=(SELECT coalesce(sum(amount),0) FROM inventory_fifo_allocations WHERE movement_id=NEW.id) AND ((v.amount=0 AND v.journal_id IS NULL) OR (v.amount>0 AND j.status='posted' AND j.source_key='inventory-movement:'||NEW.id AND v.amount=(SELECT sum(debit) FROM accounting_journal_lines WHERE journal_id=j.id)))) THEN RAISE EXCEPTION 'FIFO stock issue must commit with its matching journal'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_financial_commit AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_require_financial_commit();
CREATE OR REPLACE FUNCTION accounting_require_opening_commit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM accounting_opening_batches b JOIN accounting_activation a ON a.id=1 WHERE b.id=split_part(NEW.source_key,':',2)::bigint AND b.status='posted' AND a.activated_at IS NOT NULL) THEN RAISE EXCEPTION 'Opening contributions must commit with company activation/GL'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER payable_opening_commit AFTER INSERT ON payable_opening_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION accounting_require_opening_commit();
CREATE CONSTRAINT TRIGGER treasury_opening_commit AFTER INSERT ON treasury_opening_balances DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION accounting_require_opening_commit();
SQL);
    }

    public function down(): void {}
};
