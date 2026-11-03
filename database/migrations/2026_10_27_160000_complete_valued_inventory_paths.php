<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE inventory_document_lines ADD COLUMN unit_cost numeric(36,16) CHECK(unit_cost>=0);
ALTER TABLE inventory_document_lines ADD COLUMN cost_reference varchar(500);
CREATE TABLE inventory_transfer_lots (
 id bigserial PRIMARY KEY, document_id bigint NOT NULL REFERENCES inventory_documents(id),
 line_id bigint NOT NULL REFERENCES inventory_document_lines(id), allocation_id bigint NOT NULL UNIQUE REFERENCES inventory_fifo_allocations(id),
 received_on date NOT NULL, original_quantity numeric(24,8) NOT NULL CHECK(original_quantity>0),
 remaining_quantity numeric(24,8) NOT NULL CHECK(remaining_quantity>=0 AND remaining_quantity<=original_quantity),
 original_value numeric(20,2) NOT NULL CHECK(original_value>=0), remaining_value numeric(36,16) NOT NULL CHECK(remaining_value>=0),
 allocated_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(allocated_amount>=0 AND allocated_amount<=original_value),
 CHECK(remaining_quantity<>0 OR remaining_value=0)
);
CREATE INDEX inventory_transfer_lot_queue ON inventory_transfer_lots(document_id,line_id,received_on,id) WHERE remaining_quantity>0;
CREATE TABLE inventory_transfer_allocations (
 id bigserial PRIMARY KEY, lot_id bigint NOT NULL REFERENCES inventory_transfer_lots(id),
 movement_id bigint NOT NULL UNIQUE REFERENCES inventory_movements(id), quantity numeric(24,8) NOT NULL CHECK(quantity>0),
 raw_value numeric(36,16) NOT NULL CHECK(raw_value>=0), amount numeric(20,2) NOT NULL CHECK(amount>=0)
);
CREATE TRIGGER inventory_transfer_allocation_immutable BEFORE UPDATE OR DELETE ON inventory_transfer_allocations FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION inventory_guard_transit_lot() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Transit history immutable'; END IF;
 IF TG_OP='UPDATE' AND ROW(NEW.document_id,NEW.line_id,NEW.allocation_id,NEW.received_on,NEW.original_quantity,NEW.original_value) IS DISTINCT FROM ROW(OLD.document_id,OLD.line_id,OLD.allocation_id,OLD.received_on,OLD.original_quantity,OLD.original_value) THEN RAISE EXCEPTION 'Transit source immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER inventory_transit_source_guard BEFORE UPDATE OR DELETE ON inventory_transfer_lots FOR EACH ROW EXECUTE FUNCTION inventory_guard_transit_lot();
CREATE OR REPLACE FUNCTION inventory_reconcile_transit_lot() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE lid bigint; l inventory_transfer_lots%ROWTYPE; a inventory_fifo_allocations%ROWTYPE; m inventory_movements%ROWTYPE; q numeric; raw numeric; value numeric;
BEGIN
 IF TG_TABLE_NAME='inventory_transfer_lots' THEN lid:=NEW.id; ELSE lid:=NEW.lot_id; END IF;
 SELECT * INTO l FROM inventory_transfer_lots WHERE id=lid;
 SELECT * INTO a FROM inventory_fifo_allocations WHERE id=l.allocation_id;
 SELECT * INTO m FROM inventory_movements WHERE id=a.movement_id;
 SELECT coalesce(sum(quantity),0),coalesce(sum(raw_value),0),coalesce(sum(amount),0) INTO q,raw,value FROM inventory_transfer_allocations WHERE lot_id=lid;
 IF m.operation<>'transfer_dispatch' OR m.document_id<>l.document_id OR m.source_line<>l.line_id::text OR a.quantity<>l.original_quantity OR a.amount<>l.original_value
 OR l.original_quantity-l.remaining_quantity<>q OR l.original_value-l.remaining_value<>raw OR l.allocated_amount<>value THEN RAISE EXCEPTION 'Transit FIFO reconciliation failed'; END IF;
 IF EXISTS(SELECT 1 FROM inventory_transfer_allocations x JOIN inventory_movements r ON r.id=x.movement_id JOIN inventory_fifo_layers f ON f.movement_id=r.id WHERE x.lot_id=lid AND (r.operation<>'transfer_receive' OR r.document_id<>l.document_id OR r.quantity<>x.quantity OR f.original_value<>x.amount OR f.received_on<>l.received_on)) THEN RAISE EXCEPTION 'Transit receipt value/date mismatch'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_transit_lot_reconcile AFTER INSERT OR UPDATE ON inventory_transfer_lots DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_reconcile_transit_lot();
CREATE CONSTRAINT TRIGGER inventory_transit_allocation_reconcile AFTER INSERT ON inventory_transfer_allocations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_reconcile_transit_lot();
CREATE OR REPLACE FUNCTION inventory_require_valued_extension_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE value numeric; qty numeric; v inventory_movement_journals%ROWTYPE; doc inventory_documents%ROWTYPE;
BEGIN
 IF NEW.operation NOT IN ('transfer_dispatch','transfer_receive','adjustment_post') THEN RETURN NEW; END IF;
 SELECT * INTO doc FROM inventory_documents WHERE id=NEW.document_id;
 IF doc.id IS NULL OR doc.approved_by IS NULL OR (NEW.operation='transfer_dispatch' AND doc.status NOT IN ('dispatched','received')) OR (NEW.operation='transfer_receive' AND doc.status NOT IN ('dispatched','received')) OR (NEW.operation='adjustment_post' AND doc.status<>'posted') THEN RAISE EXCEPTION 'Approved posted stock document required'; END IF;
 IF NEW.quantity>0 THEN
  SELECT original_value,original_quantity INTO value,qty FROM inventory_fifo_layers WHERE movement_id=NEW.id;
 ELSE
  SELECT coalesce(sum(amount),0),coalesce(sum(quantity),0) INTO value,qty FROM inventory_fifo_allocations WHERE movement_id=NEW.id;
 END IF;
 SELECT * INTO v FROM inventory_movement_journals WHERE movement_id=NEW.id;
 IF value IS NULL OR qty<>abs(NEW.quantity) OR v.movement_id IS NULL OR v.amount<>value THEN RAISE EXCEPTION 'Stock/FIFO/journal value mismatch'; END IF;
 IF value>0 AND NOT EXISTS(SELECT 1 FROM accounting_journals j WHERE j.id=v.journal_id AND j.status='posted' AND j.source_key='inventory-movement:'||NEW.id AND value=(SELECT sum(debit) FROM accounting_journal_lines WHERE journal_id=j.id) AND value=(SELECT sum(credit) FROM accounting_journal_lines WHERE journal_id=j.id)) THEN RAISE EXCEPTION 'Valued stock journal required'; END IF;
 IF NEW.operation='transfer_dispatch' AND (SELECT coalesce(sum(original_quantity),0) FROM inventory_transfer_lots l JOIN inventory_fifo_allocations a ON a.id=l.allocation_id WHERE a.movement_id=NEW.id)<>-NEW.quantity THEN RAISE EXCEPTION 'Dispatch transit lots missing'; END IF;
 IF NEW.operation='transfer_receive' AND NOT EXISTS(SELECT 1 FROM inventory_transfer_allocations WHERE movement_id=NEW.id) THEN RAISE EXCEPTION 'Receipt transit allocation missing'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_valued_extension_commit AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_require_valued_extension_commit();
SQL);
    }

    public function down(): void {}
};
