<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE accounting_opening_lines ADD COLUMN serials jsonb NOT NULL DEFAULT '[]'::jsonb CHECK(jsonb_typeof(serials)='array');
CREATE OR REPLACE FUNCTION inventory_require_serial_fifo() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m inventory_movements%ROWTYPE; allocation inventory_fifo_allocations%ROWTYPE; original_allocation bigint;
BEGIN
 IF TG_TABLE_NAME='inventory_movements' THEN m:=NEW; ELSE SELECT * INTO m FROM inventory_movements WHERE id=NEW.movement_id; END IF;
 IF NOT EXISTS(SELECT 1 FROM inventory_balances WHERE warehouse_id=m.warehouse_id AND product_id=m.product_id AND tracking='serial') THEN RETURN NEW; END IF;
 IF abs(m.quantity)<>(SELECT count(*) FROM inventory_serial_events WHERE movement_id=m.id) THEN RAISE EXCEPTION 'Serial movement must match exact physical identities'; END IF;
 IF m.quantity<0 THEN
  IF abs(m.quantity)<>(SELECT count(*) FROM inventory_fifo_serial_allocations WHERE movement_id=m.id) THEN RAISE EXCEPTION 'Each issued serial requires its actual FIFO allocation'; END IF;
  FOR allocation IN SELECT * FROM inventory_fifo_allocations WHERE movement_id=m.id LOOP
   IF allocation.quantity<>(SELECT count(*) FROM inventory_fifo_serial_allocations WHERE allocation_id=allocation.id AND movement_id=m.id)
    OR allocation.amount<>coalesce((SELECT sum(amount) FROM inventory_fifo_serial_allocations WHERE allocation_id=allocation.id AND movement_id=m.id),0)
   THEN RAISE EXCEPTION 'Serial allocated quantities and cents must reconcile to FIFO'; END IF;
  END LOOP;
  IF EXISTS(SELECT 1 FROM inventory_fifo_serial_allocations s JOIN inventory_fifo_allocations a ON a.id=s.allocation_id
   WHERE s.movement_id=m.id AND (a.movement_id<>m.id OR NOT EXISTS(SELECT 1 FROM inventory_serial_events e WHERE e.serial_id=s.serial_id AND e.movement_id=m.id)))
  THEN RAISE EXCEPTION 'Serial allocation source mismatch'; END IF;
 ELSIF m.operation IN ('transfer_receive','correction','maintenance_return','workshop_return') THEN
  IF m.operation='transfer_receive' THEN
   SELECT l.allocation_id INTO original_allocation FROM inventory_transfer_allocations a JOIN inventory_transfer_lots l ON l.id=a.lot_id WHERE a.movement_id=m.id;
  ELSE
   SELECT allocation_id INTO original_allocation FROM inventory_fifo_corrections WHERE movement_id=m.id;
  END IF;
  IF original_allocation IS NULL OR abs(m.quantity)<>(SELECT count(*) FROM inventory_fifo_serial_allocations WHERE movement_id=m.id)
   OR (SELECT original_value FROM inventory_fifo_layers WHERE movement_id=m.id)<>coalesce((SELECT sum(amount) FROM inventory_fifo_serial_allocations WHERE movement_id=m.id),0)
  THEN RAISE EXCEPTION 'Returned serial costs must reconcile to the original issue and new FIFO layer'; END IF;
  IF EXISTS(SELECT 1 FROM inventory_fifo_serial_allocations r JOIN inventory_fifo_allocations a ON a.id=r.allocation_id
   LEFT JOIN inventory_fifo_serial_allocations s ON s.allocation_id=a.id AND s.movement_id=a.movement_id AND s.serial_id=r.serial_id
   WHERE r.movement_id=m.id AND (r.allocation_id<>original_allocation OR s.id IS NULL OR s.amount<>r.amount
    OR NOT EXISTS(SELECT 1 FROM inventory_serial_events e WHERE e.serial_id=r.serial_id AND e.movement_id=m.id)))
  THEN RAISE EXCEPTION 'Returned serial identity or allocated cents changed'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain serialized opening and source-linked return evidence.');
    }
};
