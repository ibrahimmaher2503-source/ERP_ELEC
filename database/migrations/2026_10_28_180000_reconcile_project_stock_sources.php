<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION require_project_issue_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m record; v numeric; q numeric;
BEGIN
 IF TG_TABLE_NAME='project_material_issues' THEN
  IF NOT EXISTS(SELECT 1 FROM project_material_issues i JOIN inventory_movements x ON x.idempotency_key='reservation:'||i.issue_key WHERE i.id=NEW.id AND i.status='posted' AND x.operation='project_issue' AND x.source_module='Projects' AND x.source_id=i.project_id::text AND x.source_line=i.requirement_id::text AND x.quantity=-i.quantity) THEN RAISE EXCEPTION 'Project issue must commit with real stock issue'; END IF;
 ELSE
  IF NEW.operation<>'project_issue' THEN RETURN NEW; END IF;
  SELECT coalesce(sum(amount),0),coalesce(sum(quantity),0) INTO v,q FROM inventory_fifo_allocations WHERE movement_id=NEW.id;
  IF q<>-NEW.quantity OR NOT EXISTS(SELECT 1 FROM project_material_issues i JOIN inventory_reservations r ON r.id=i.reservation_id JOIN inventory_reservation_events e ON e.reservation_id=r.id AND e.idempotency_key=i.issue_key AND e.action='fulfilled' AND e.quantity=i.quantity JOIN project_requirements req ON req.id=i.requirement_id WHERE i.issue_key::text=substring(NEW.idempotency_key from 13) AND i.status='posted' AND i.project_id::text=NEW.source_id AND i.requirement_id::text=NEW.source_line AND NEW.source_module='Projects' AND i.quantity=-NEW.quantity AND r.product_id=NEW.product_id AND r.warehouse_id=NEW.warehouse_id AND req.project_id=i.project_id)
  OR NOT EXISTS(SELECT 1 FROM inventory_movement_journals x LEFT JOIN accounting_journals j ON j.id=x.journal_id LEFT JOIN accounting_project_dimensions d ON d.journal_id=j.id WHERE x.movement_id=NEW.id AND x.amount=v AND ((v=0 AND x.journal_id IS NULL) OR (v>0 AND j.status='posted' AND d.project_id::text=NEW.source_id AND j.source_key='inventory-movement:'||NEW.id AND v=(SELECT sum(debit) FROM accounting_journal_lines WHERE journal_id=j.id) AND v=(SELECT sum(credit) FROM accounting_journal_lines WHERE journal_id=j.id)))) THEN RAISE EXCEPTION 'Project stock issue requires source, FIFO and project GL'; END IF;
 END IF; RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION guard_project_reservation_commit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.source_module='projects' AND NOT EXISTS(SELECT 1 FROM project_material_reservations l JOIN project_requirements r ON r.id=l.requirement_id WHERE l.reservation_id=NEW.id AND l.project_id::text=NEW.source_id AND l.requirement_id::text=NEW.source_line AND r.project_id=l.project_id AND r.product_id=NEW.product_id) THEN RAISE EXCEPTION 'Project reservation must commit with matching owner demand'; END IF; RETURN NEW; END $$;
CREATE CONSTRAINT TRIGGER inventory_project_reservation_commit AFTER INSERT OR UPDATE ON inventory_reservations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION guard_project_reservation_commit();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Project source evidence requires forward migrations.');
    }
};
