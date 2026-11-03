<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_reconcile_fifo_correction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a inventory_fifo_allocations%ROWTYPE; q numeric; raw numeric; value numeric; m inventory_movements%ROWTYPE;
BEGIN
 SELECT * INTO m FROM inventory_movements WHERE id=NEW.movement_id;
 IF m.quantity>0 THEN
  SELECT * INTO a FROM inventory_fifo_allocations WHERE id=NEW.allocation_id;
  SELECT sum(quantity),sum(raw_value),sum(amount) INTO q,raw,value FROM inventory_fifo_corrections WHERE allocation_id=NEW.allocation_id;
  IF a.id IS NULL OR a.movement_id<>NEW.original_movement_id OR a.layer_id<>NEW.source_layer_id OR q>a.quantity OR raw>a.amount OR value>a.amount
   OR (q=a.quantity AND (raw<>a.amount OR value<>a.amount)) THEN RAISE EXCEPTION 'Correction must exhaust exact original FIFO allocation value'; END IF;
 ELSE
  IF NEW.allocation_id IS NOT NULL OR NOT EXISTS(SELECT 1 FROM inventory_fifo_allocations WHERE movement_id=NEW.movement_id AND layer_id=NEW.source_layer_id AND quantity=NEW.quantity AND raw_value=NEW.raw_value AND amount=NEW.amount) THEN RAISE EXCEPTION 'Correction FIFO consumption evidence mismatch'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_fifo_correction_reconcile AFTER INSERT ON inventory_fifo_corrections DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_reconcile_fifo_correction();
CREATE OR REPLACE FUNCTION sales_guard_issued_quote_number() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.number IS NOT NULL AND NEW.number IS DISTINCT FROM OLD.number THEN RAISE EXCEPTION 'Issued quotation number immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_issued_quote_number BEFORE UPDATE ON sales_quotations FOR EACH ROW EXECUTE FUNCTION sales_guard_issued_quote_number();
CREATE OR REPLACE FUNCTION sales_require_complete_order_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM sales_orders WHERE id=NEW.order_id AND status='approved')
 OR NOT EXISTS(SELECT 1 FROM sales_order_lines WHERE sales_order_id=NEW.order_id) THEN RAISE EXCEPTION 'Official order requires approved nonempty commercial source'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER sales_order_complete_evidence AFTER INSERT ON sales_order_issues DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sales_require_complete_order_evidence();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Source evidence protection requires forward migrations.');
    }
};
