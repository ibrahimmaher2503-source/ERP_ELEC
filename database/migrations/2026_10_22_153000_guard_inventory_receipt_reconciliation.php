<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfer_receipts',fn(Blueprint $t)=>$t->string('financial_status',30)->default('not_required'));
        DB::statement("ALTER TABLE inventory_transfer_receipts ADD CONSTRAINT inventory_transfer_finance_status CHECK (financial_status IN ('not_required','pending_financial'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_transfer_receipt_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE dispatched numeric; physical numeric; missing_qty numeric; row_physical numeric; posted numeric; d record;
BEGIN
 SELECT l.quantity,doc.id,doc.destination_id,l.product_id INTO d FROM inventory_document_lines l JOIN inventory_documents doc ON doc.id=l.document_id WHERE l.id=NEW.line_id AND doc.kind='transfer';
 IF d.id IS NULL THEN RAISE EXCEPTION 'Transfer line required'; END IF;
 SELECT sum(accepted+damaged),sum(missing-recovered_missing) INTO physical,missing_qty FROM inventory_transfer_receipt_lines WHERE line_id=NEW.line_id;
 IF physical+missing_qty>d.quantity OR missing_qty<0 THEN RAISE EXCEPTION 'Transfer receipt exceeds unresolved dispatch'; END IF;
 IF NOT EXISTS(SELECT 1 FROM inventory_transfer_receipts WHERE id=NEW.receipt_id AND document_id=d.id) THEN RAISE EXCEPTION 'Receipt source mismatch'; END IF;
 SELECT coalesce(sum(quantity),0) INTO posted FROM inventory_movements WHERE idempotency_key IN ('transfer-receipt:'||NEW.receipt_id||':'||NEW.line_id||':accepted','transfer-receipt:'||NEW.receipt_id||':'||NEW.line_id||':damaged') AND operation='transfer_receive' AND warehouse_id=d.destination_id AND product_id=d.product_id AND document_id=d.id;
 IF posted<>NEW.accepted+NEW.damaged THEN RAISE EXCEPTION 'Transfer physical receipt must match immutable ledger'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_transfer_receipt_quantity AFTER INSERT ON inventory_transfer_receipt_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_transfer_receipt_check();
SQL);
    }
    public function down(): void { /* Retain receipt evidence and guards. */ }
};
