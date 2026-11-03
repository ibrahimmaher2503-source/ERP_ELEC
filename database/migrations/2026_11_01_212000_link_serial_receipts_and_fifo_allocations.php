<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE purchasing_receipt_lines ADD COLUMN serials jsonb NOT NULL DEFAULT '[]'::jsonb CHECK(jsonb_typeof(serials)='array');
CREATE TABLE inventory_fifo_serial_allocations (
 id bigserial PRIMARY KEY, serial_id bigint NOT NULL REFERENCES inventory_serials(id), movement_id bigint NOT NULL REFERENCES inventory_movements(id),
 allocation_id bigint NOT NULL REFERENCES inventory_fifo_allocations(id), amount numeric(20,2) NOT NULL CHECK(amount>=0),
 UNIQUE(serial_id,movement_id)
);
CREATE TRIGGER inventory_fifo_serial_allocation_history BEFORE UPDATE OR DELETE ON inventory_fifo_serial_allocations FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION inventory_require_serial_fifo() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m inventory_movements%ROWTYPE; allocation inventory_fifo_allocations%ROWTYPE;
BEGIN
 IF TG_TABLE_NAME='inventory_movements' THEN m:=NEW; ELSE SELECT * INTO m FROM inventory_movements WHERE id=NEW.movement_id; END IF;
 IF NOT EXISTS(SELECT 1 FROM inventory_balances WHERE warehouse_id=m.warehouse_id AND product_id=m.product_id AND tracking='serial') THEN RETURN NEW; END IF;
 IF abs(m.quantity)<>(SELECT count(*) FROM inventory_serial_events WHERE movement_id=m.id) THEN RAISE EXCEPTION 'Serial movement must match exact physical identities'; END IF;
 IF m.quantity<0 THEN
  IF abs(m.quantity)<>(SELECT count(*) FROM inventory_fifo_serial_allocations WHERE movement_id=m.id) THEN RAISE EXCEPTION 'Each issued serial requires its actual FIFO allocation'; END IF;
  FOR allocation IN SELECT * FROM inventory_fifo_allocations WHERE movement_id=m.id LOOP
   IF allocation.quantity<>(SELECT count(*) FROM inventory_fifo_serial_allocations WHERE allocation_id=allocation.id)
    OR allocation.amount<>coalesce((SELECT sum(amount) FROM inventory_fifo_serial_allocations WHERE allocation_id=allocation.id),0)
   THEN RAISE EXCEPTION 'Serial allocated quantities and cents must reconcile to FIFO'; END IF;
  END LOOP;
  IF EXISTS(SELECT 1 FROM inventory_fifo_serial_allocations s JOIN inventory_fifo_allocations a ON a.id=s.allocation_id
   WHERE s.movement_id=m.id AND (a.movement_id<>m.id OR NOT EXISTS(SELECT 1 FROM inventory_serial_events e WHERE e.serial_id=s.serial_id AND e.movement_id=m.id)))
  THEN RAISE EXCEPTION 'Serial allocation source mismatch'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_serial_fifo_movement AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_require_serial_fifo();
CREATE CONSTRAINT TRIGGER inventory_serial_fifo_mapping AFTER INSERT ON inventory_fifo_serial_allocations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_require_serial_fifo();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain original serial receipt and FIFO allocation evidence.');
    }
};
