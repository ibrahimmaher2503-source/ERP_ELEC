<?php

use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE projects DROP CONSTRAINT projects_status_check;
ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK(status IN ('draft','planned','active','in_progress','on_hold','completed','closed','cancelled'));
ALTER TABLE projects ADD COLUMN project_type varchar(100) NOT NULL DEFAULT '', ADD COLUMN priority varchar(10) NOT NULL DEFAULT 'normal' CHECK(priority IN ('low','normal','high','urgent')), ADD COLUMN actual_start date, ADD COLUMN actual_end date;
ALTER TABLE project_tasks DROP CONSTRAINT project_tasks_status_check;
ALTER TABLE project_tasks ADD CONSTRAINT project_tasks_status_check CHECK(status IN ('pending','in_progress','blocked','completed','cancelled'));
ALTER TABLE project_tasks ADD COLUMN priority varchar(10) NOT NULL DEFAULT 'normal' CHECK(priority IN ('low','normal','high','urgent')), ADD COLUMN progress integer NOT NULL DEFAULT 0 CHECK(progress BETWEEN 0 AND 100), ADD COLUMN blocked_reason text NOT NULL DEFAULT '', ADD COLUMN required boolean NOT NULL DEFAULT true;
UPDATE project_tasks SET progress=100 WHERE status='completed';
CREATE TABLE project_task_dependencies(task_id bigint NOT NULL REFERENCES project_tasks(id), depends_on_id bigint NOT NULL REFERENCES project_tasks(id), PRIMARY KEY(task_id,depends_on_id), CHECK(task_id<>depends_on_id));
CREATE INDEX project_dependency_parent ON project_task_dependencies(depends_on_id);
CREATE TABLE project_task_comments(id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), task_id bigint NOT NULL REFERENCES project_tasks(id), actor_id bigint NOT NULL REFERENCES users(id), body text NOT NULL, created_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX project_comments_task ON project_task_comments(project_id,task_id);
CREATE TRIGGER project_comment_immutable BEFORE UPDATE OR DELETE ON project_task_comments FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TABLE project_milestones(id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), title varchar(200) NOT NULL, target_date date NOT NULL, responsible_id bigint NOT NULL REFERENCES users(id), required boolean NOT NULL DEFAULT true, status varchar(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','completed','waived')), evidence text, completed_at timestamptz, completed_by bigint REFERENCES users(id), version integer NOT NULL DEFAULT 1 CHECK(version>0), CHECK((status<>'pending')=(completed_at IS NOT NULL AND completed_by IS NOT NULL AND length(trim(evidence))>=5)));
CREATE INDEX project_milestones_project ON project_milestones(project_id,status);
CREATE TABLE project_handovers(id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), evidence text NOT NULL CHECK(length(trim(evidence))>=5), recorded_by bigint NOT NULL REFERENCES users(id), recorded_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX project_handovers_project ON project_handovers(project_id);
CREATE TRIGGER project_handover_immutable BEFORE UPDATE OR DELETE ON project_handovers FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TABLE project_material_reservations(id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), requirement_id bigint NOT NULL REFERENCES project_requirements(id), reservation_id bigint NOT NULL UNIQUE REFERENCES inventory_reservations(id), created_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX project_material_reservations_project ON project_material_reservations(project_id,requirement_id);
CREATE TRIGGER project_reservation_link_immutable BEFORE UPDATE OR DELETE ON project_material_reservations FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TABLE project_material_issues(id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), requirement_id bigint NOT NULL REFERENCES project_requirements(id), reservation_id bigint NOT NULL REFERENCES inventory_reservations(id), issue_key uuid NOT NULL UNIQUE, quantity numeric(24,8) NOT NULL CHECK(quantity>0), status varchar(10) NOT NULL DEFAULT 'prepared' CHECK(status IN ('prepared','posted')), created_by bigint NOT NULL REFERENCES users(id), posted_at timestamptz, CHECK((status='posted')=(posted_at IS NOT NULL)));
CREATE INDEX project_material_issues_project ON project_material_issues(project_id,requirement_id);
CREATE TABLE accounting_project_dimensions(journal_id bigint PRIMARY KEY REFERENCES accounting_journals(id), project_id bigint NOT NULL REFERENCES projects(id), created_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX accounting_project_dimension_project ON accounting_project_dimensions(project_id);
CREATE TRIGGER accounting_project_dimension_immutable BEFORE UPDATE OR DELETE ON accounting_project_dimensions FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION guard_accounting_project_dimension() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM accounting_journals j JOIN projects p ON p.id=NEW.project_id WHERE j.id=NEW.journal_id AND j.status='draft' AND j.branch_id=p.branch_id AND p.status IN ('active','in_progress')) THEN RAISE EXCEPTION 'Project dimension must be frozen before posting in an active project branch'; END IF; RETURN NEW; END $$;
CREATE TRIGGER accounting_project_dimension_guard BEFORE INSERT ON accounting_project_dimensions FOR EACH ROW EXECUTE FUNCTION guard_accounting_project_dimension();
ALTER TABLE rp_supplier_invoices ADD COLUMN project_id bigint REFERENCES projects(id);
ALTER TABLE rp_customer_invoices ADD COLUMN project_id bigint REFERENCES projects(id);
CREATE INDEX rp_supplier_project ON rp_supplier_invoices(project_id);
CREATE INDEX rp_customer_project ON rp_customer_invoices(project_id);
CREATE OR REPLACE FUNCTION guard_rp_project_identity() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.project_id IS DISTINCT FROM OLD.project_id THEN RAISE EXCEPTION 'Invoice project attribution is frozen at creation'; END IF; RETURN NEW; END $$;
CREATE TRIGGER rp_supplier_project_identity BEFORE UPDATE ON rp_supplier_invoices FOR EACH ROW EXECUTE FUNCTION guard_rp_project_identity();
CREATE TRIGGER rp_customer_project_identity BEFORE UPDATE ON rp_customer_invoices FOR EACH ROW EXECUTE FUNCTION guard_rp_project_identity();
CREATE OR REPLACE FUNCTION guard_project_issue_history() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR OLD.status='posted' OR (to_jsonb(NEW)-'status'-'posted_at') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'posted_at') THEN RAISE EXCEPTION 'Project issue evidence immutable'; END IF; RETURN NEW; END $$;
CREATE TRIGGER project_issue_immutable BEFORE UPDATE OR DELETE ON project_material_issues FOR EACH ROW EXECUTE FUNCTION guard_project_issue_history();
CREATE OR REPLACE FUNCTION require_project_issue_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m record; v numeric; q numeric;
BEGIN
 IF TG_TABLE_NAME='project_material_issues' THEN
  IF NOT EXISTS(SELECT 1 FROM project_material_issues i JOIN inventory_movements x ON x.idempotency_key='reservation:'||i.issue_key WHERE i.id=NEW.id AND i.status='posted' AND x.operation='project_issue' AND x.source_module='Projects' AND x.source_id=i.project_id::text AND x.source_line=i.requirement_id::text AND x.quantity=-i.quantity) THEN RAISE EXCEPTION 'Project issue must commit with real stock issue'; END IF;
 ELSE
  IF NEW.operation<>'project_issue' THEN RETURN NEW; END IF;
  SELECT coalesce(sum(amount),0),coalesce(sum(quantity),0) INTO v,q FROM inventory_fifo_allocations WHERE movement_id=NEW.id;
  IF q<>-NEW.quantity OR NOT EXISTS(SELECT 1 FROM project_material_issues i JOIN inventory_reservations r ON r.id=i.reservation_id JOIN project_requirements req ON req.id=i.requirement_id WHERE i.issue_key::text=substring(NEW.idempotency_key from 13) AND i.status='posted' AND i.project_id::text=NEW.source_id AND i.requirement_id::text=NEW.source_line AND NEW.source_module='Projects' AND i.quantity=-NEW.quantity AND r.product_id=NEW.product_id AND r.warehouse_id=NEW.warehouse_id AND req.project_id=i.project_id)
  OR NOT EXISTS(SELECT 1 FROM inventory_movement_journals x LEFT JOIN accounting_journals j ON j.id=x.journal_id LEFT JOIN accounting_project_dimensions d ON d.journal_id=j.id WHERE x.movement_id=NEW.id AND x.amount=v AND ((v=0 AND x.journal_id IS NULL) OR (v>0 AND j.status='posted' AND d.project_id::text=NEW.source_id AND j.source_key='inventory-movement:'||NEW.id AND v=(SELECT sum(debit) FROM accounting_journal_lines WHERE journal_id=j.id) AND v=(SELECT sum(credit) FROM accounting_journal_lines WHERE journal_id=j.id)))) THEN RAISE EXCEPTION 'Project stock issue requires source, FIFO and project GL'; END IF;
 END IF; RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER project_issue_commit AFTER INSERT OR UPDATE ON project_material_issues DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_project_issue_commit();
CREATE CONSTRAINT TRIGGER inventory_project_issue_commit AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_project_issue_commit();
CREATE OR REPLACE FUNCTION guard_project_record() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Project history cannot be deleted'; END IF;
 IF OLD.status='closed' AND NEW.status<>'active' THEN RAISE EXCEPTION 'Closed project requires explicit reopening'; END IF;
 RETURN NEW; END $$;
CREATE TRIGGER project_record_guard BEFORE UPDATE OR DELETE ON projects FOR EACH ROW EXECUTE FUNCTION guard_project_record();
CREATE TRIGGER project_source_link_immutable BEFORE UPDATE OR DELETE ON project_source_links FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE INDEX project_tasks_project_phase ON project_tasks(project_id,phase_id);
CREATE INDEX project_activity_project ON project_activities(project_id,id);
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M07.')) {
                DB::table('permission_catalog')->updateOrInsert(['code' => $code], ['label' => $label, 'scope' => $scope, 'module' => 'M07 · المشروعات', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        throw new RuntimeException('Project evidence requires forward migrations.');
    }
};
