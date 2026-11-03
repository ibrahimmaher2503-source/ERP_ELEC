<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION workshop_source_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m inventory_movements%ROWTYPE; q numeric; v numeric;
BEGIN
 IF TG_TABLE_NAME='workshop_part_issues' THEN
  SELECT * INTO m FROM inventory_movements WHERE idempotency_key='reservation:'||NEW.issue_key;
  IF m.id IS NULL OR NOT EXISTS(SELECT 1 FROM workshop_part_issues WHERE id=NEW.id AND movement_id=m.id AND amount IS NOT NULL) THEN RAISE EXCEPTION 'Maintenance issue requires posted source'; END IF;
 ELSE
  IF NEW.operation NOT IN ('workshop_issue','workshop_return') THEN RETURN NEW; END IF;
  m:=NEW;
 END IF;
 IF m.operation='workshop_issue' THEN
  SELECT coalesce(sum(quantity),0),coalesce(sum(amount),0) INTO q,v FROM inventory_fifo_allocations WHERE movement_id=m.id;
  IF q<>-m.quantity OR NOT EXISTS(SELECT 1 FROM workshop_part_issues i JOIN workshop_part_requirements r ON r.id=i.requirement_id JOIN workshop_jobs w ON w.id=r.job_id JOIN inventory_reservations s ON s.id=i.reservation_id JOIN inventory_reservation_events e ON e.reservation_id=s.id AND e.idempotency_key=i.issue_key AND e.action='fulfilled'
   WHERE i.movement_id=m.id AND i.issue_key::text=substring(m.idempotency_key FROM 13) AND i.quantity=-m.quantity AND i.amount=v AND m.source_module='WorkshopRepairs' AND m.source_id=w.id::text AND m.source_line=r.id::text AND m.product_id=r.product_id AND m.warehouse_id=r.warehouse_id AND s.source_module='workshop' AND e.quantity=i.quantity) THEN RAISE EXCEPTION 'Maintenance source/FIFO mismatch'; END IF;
 ELSE
  SELECT original_quantity,original_value INTO q,v FROM inventory_fifo_layers WHERE movement_id=m.id;
  IF q IS NULL OR q<>m.quantity OR NOT EXISTS(SELECT 1 FROM inventory_fifo_corrections c JOIN inventory_fifo_allocations a ON a.id=c.allocation_id JOIN inventory_fifo_layers original ON original.id=c.source_layer_id JOIN inventory_fifo_layers restored ON restored.movement_id=m.id JOIN inventory_movements o ON o.id=c.original_movement_id JOIN workshop_part_issues i ON i.movement_id=o.id JOIN workshop_part_returns r ON r.issue_id=i.id AND r.movement_id=m.id
   WHERE c.movement_id=m.id AND m.reversal_id=o.id AND o.operation='workshop_issue' AND m.quantity=c.quantity AND r.quantity=c.quantity AND r.amount=c.amount AND a.movement_id=o.id AND a.layer_id=c.source_layer_id AND restored.original_value=c.amount AND restored.received_on=original.received_on
   AND (SELECT sum(quantity) FROM inventory_fifo_corrections WHERE allocation_id=a.id)<=a.quantity AND (SELECT sum(amount) FROM inventory_fifo_corrections WHERE allocation_id=a.id)<=a.amount
   AND EXISTS(SELECT 1 FROM inventory_corrections WHERE correcting_movement_id=m.id AND movement_id=o.id AND status='posted')) THEN RAISE EXCEPTION 'Maintenance return needs original exact FIFO source'; END IF;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM inventory_movement_journals x LEFT JOIN accounting_journals j ON j.id=x.journal_id WHERE x.movement_id=m.id AND x.amount=v AND ((v=0 AND x.journal_id IS NULL) OR (v>0 AND j.status='posted' AND j.source_key='inventory-movement:'||m.id AND v=(SELECT sum(debit) FROM accounting_journal_lines WHERE journal_id=j.id) AND v=(SELECT sum(credit) FROM accounting_journal_lines WHERE journal_id=j.id)))) THEN RAISE EXCEPTION 'Maintenance stock requires balanced GL'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER workshop_issue_commit AFTER INSERT ON workshop_part_issues DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_source_commit();
CREATE CONSTRAINT TRIGGER inventory_workshop_commit AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_source_commit();
CREATE OR REPLACE FUNCTION workshop_reservation_commit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.source_module='workshop' AND NOT EXISTS(SELECT 1 FROM workshop_part_reservations l JOIN workshop_part_requirements r ON r.id=l.requirement_id WHERE l.reservation_id=NEW.id AND r.job_id::text=NEW.source_id AND r.id::text=NEW.source_line AND r.product_id=NEW.product_id AND r.warehouse_id=NEW.warehouse_id) THEN RAISE EXCEPTION 'Maintenance reservation requires owner link'; END IF;
 RETURN NEW; END $$;
CREATE CONSTRAINT TRIGGER inventory_workshop_reservation_commit AFTER INSERT OR UPDATE ON inventory_reservations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_reservation_commit();
CREATE OR REPLACE FUNCTION workshop_guard_issue_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.movement_id IS NOT NULL THEN RAISE EXCEPTION 'Posted workshop issue evidence is immutable'; END IF;
 IF ROW(NEW.requirement_id,NEW.reservation_id,NEW.issue_key,NEW.quantity,NEW.created_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.requirement_id,OLD.reservation_id,OLD.issue_key,OLD.quantity,OLD.created_by,OLD.created_at) THEN RAISE EXCEPTION 'Maintenance issue identity is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER workshop_issue_history BEFORE UPDATE OR DELETE ON workshop_part_issues FOR EACH ROW EXECUTE FUNCTION workshop_guard_issue_history();

SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only workshop sources.');
    }
};
