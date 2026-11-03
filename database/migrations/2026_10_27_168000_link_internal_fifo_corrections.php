<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE inventory_fifo_corrections (
 movement_id bigint PRIMARY KEY REFERENCES inventory_movements(id), original_movement_id bigint NOT NULL REFERENCES inventory_movements(id),
 allocation_id bigint REFERENCES inventory_fifo_allocations(id), source_layer_id bigint NOT NULL REFERENCES inventory_fifo_layers(id),
 quantity numeric(24,8) NOT NULL CHECK(quantity>0), raw_value numeric(36,16) NOT NULL CHECK(raw_value>=0), amount numeric(20,2) NOT NULL CHECK(amount>=0)
);
CREATE TRIGGER inventory_fifo_correction_immutable BEFORE UPDATE OR DELETE ON inventory_fifo_corrections FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION inventory_require_correction_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c inventory_fifo_corrections%ROWTYPE; o inventory_movements%ROWTYPE; a inventory_fifo_allocations%ROWTYPE; d inventory_documents%ROWTYPE; v numeric; q numeric;
BEGIN
 IF NEW.operation<>'correction' THEN RETURN NEW; END IF;
 SELECT * INTO c FROM inventory_fifo_corrections WHERE movement_id=NEW.id;
 SELECT * INTO o FROM inventory_movements WHERE id=NEW.reversal_id;
 SELECT * INTO d FROM inventory_documents WHERE id=o.document_id;
 IF c.movement_id IS NULL OR o.id IS NULL OR c.original_movement_id<>o.id OR o.reversal_id IS NOT NULL OR d.status<>'posted' OR d.kind NOT IN ('issue','adjustment')
 OR d.approved_by IS NULL OR d.approved_by=d.created_by OR o.source_module<>'Inventory' OR o.operation NOT IN ('issue_post','adjustment_post') OR NEW.source_module<>'Inventory'
 OR NEW.source_id<>o.id::text OR NEW.source_line<>o.id::text OR NEW.warehouse_id<>o.warehouse_id OR NEW.product_id<>o.product_id OR NEW.quantity*o.quantity>=0
 OR abs(NEW.quantity)<>c.quantity THEN RAISE EXCEPTION 'Correction requires original approved source and exact FIFO link'; END IF;
 IF (SELECT coalesce(sum(quantity),0) FROM inventory_fifo_corrections WHERE original_movement_id=o.id)>abs(o.quantity) THEN RAISE EXCEPTION 'Correction exceeds original quantity'; END IF;
 IF NEW.quantity>0 THEN
  SELECT * INTO a FROM inventory_fifo_allocations WHERE id=c.allocation_id;
  IF a.movement_id<>o.id OR a.layer_id<>c.source_layer_id OR (SELECT coalesce(sum(quantity),0) FROM inventory_fifo_corrections WHERE allocation_id=a.id)>a.quantity
  OR (SELECT coalesce(sum(amount),0) FROM inventory_fifo_corrections WHERE allocation_id=a.id)>a.amount
  OR NOT EXISTS(SELECT 1 FROM inventory_fifo_layers f JOIN inventory_fifo_layers s ON s.id=c.source_layer_id WHERE f.movement_id=NEW.id AND f.original_quantity=c.quantity AND f.original_value=c.amount AND f.received_on=s.received_on) THEN RAISE EXCEPTION 'Correction receipt must restore original FIFO cost/date'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM inventory_fifo_layers WHERE id=c.source_layer_id AND movement_id=o.id)
  OR EXISTS(SELECT 1 FROM inventory_fifo_allocations WHERE movement_id=NEW.id AND layer_id<>c.source_layer_id) THEN RAISE EXCEPTION 'Correction must consume its original receipt layer'; END IF;
  SELECT coalesce(sum(amount),0),coalesce(sum(quantity),0) INTO v,q FROM inventory_fifo_allocations WHERE movement_id=NEW.id;
  IF v<>c.amount OR q<>c.quantity THEN RAISE EXCEPTION 'Correction consumption mismatch'; END IF;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM inventory_corrections WHERE correcting_movement_id=NEW.id AND movement_id=o.id AND status='posted' AND quantity=c.quantity)
 OR NOT EXISTS(SELECT 1 FROM inventory_movement_journals x LEFT JOIN accounting_journals j ON j.id=x.journal_id WHERE x.movement_id=NEW.id AND x.amount=c.amount
 AND ((c.amount=0 AND x.journal_id IS NULL) OR (c.amount>0 AND j.status='posted' AND j.source_key='inventory-movement:'||NEW.id AND c.amount=(SELECT sum(debit) FROM accounting_journal_lines WHERE journal_id=j.id) AND c.amount=(SELECT sum(credit) FROM accounting_journal_lines WHERE journal_id=j.id)))) THEN RAISE EXCEPTION 'Correction must commit with source evidence and balanced GL'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_correction_commit AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_require_correction_commit();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Correction history requires forward migrations.');
    }
};
