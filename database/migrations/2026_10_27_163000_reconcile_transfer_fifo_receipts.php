<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_transfer_receipt_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE physical numeric; missing_qty numeric; accepted_qty numeric; damaged_qty numeric; d record; prefix text;
BEGIN
 SELECT l.quantity,doc.id,doc.destination_id,l.product_id INTO d FROM inventory_document_lines l JOIN inventory_documents doc ON doc.id=l.document_id WHERE l.id=NEW.line_id AND doc.kind='transfer';
 IF d.id IS NULL THEN RAISE EXCEPTION 'Transfer line required'; END IF;
 SELECT sum(accepted+damaged),sum(missing-recovered_missing) INTO physical,missing_qty FROM inventory_transfer_receipt_lines WHERE line_id=NEW.line_id;
 IF physical+missing_qty>d.quantity OR missing_qty<0 THEN RAISE EXCEPTION 'Transfer receipt exceeds unresolved dispatch'; END IF;
 IF NOT EXISTS(SELECT 1 FROM inventory_transfer_receipts WHERE id=NEW.receipt_id AND document_id=d.id) THEN RAISE EXCEPTION 'Receipt source mismatch'; END IF;
 prefix:='transfer-receipt:'||NEW.receipt_id||':'||NEW.line_id||':';
 SELECT coalesce(sum(m.quantity) FILTER(WHERE m.idempotency_key=prefix||'accepted' OR m.idempotency_key=prefix||'accepted:lot:'||a.lot_id),0),
        coalesce(sum(m.quantity) FILTER(WHERE m.idempotency_key=prefix||'damaged' OR m.idempotency_key=prefix||'damaged:lot:'||a.lot_id),0)
 INTO accepted_qty,damaged_qty
 FROM inventory_movements m LEFT JOIN inventory_transfer_allocations a ON a.movement_id=m.id
 WHERE m.operation='transfer_receive' AND m.warehouse_id=d.destination_id AND m.product_id=d.product_id AND m.document_id=d.id
 AND m.source_module='Inventory' AND m.source_id=d.id::text AND m.source_line=NEW.line_id::text;
 IF accepted_qty<>NEW.accepted OR damaged_qty<>NEW.damaged THEN RAISE EXCEPTION 'Transfer physical receipt must match immutable ledger by condition and FIFO lot'; END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Transfer evidence guards require forward migrations.');
    }
};
