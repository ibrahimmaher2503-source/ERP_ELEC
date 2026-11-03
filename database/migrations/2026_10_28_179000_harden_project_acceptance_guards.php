<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_accounting_project_dimension() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM accounting_journals j JOIN projects p ON p.id=NEW.project_id WHERE j.id=NEW.journal_id AND j.status='draft' AND j.branch_id=p.branch_id AND p.status IN ('active','in_progress','completed')) THEN RAISE EXCEPTION 'Project dimension must be frozen before posting in an eligible project branch'; END IF; RETURN NEW; END $$;
CREATE OR REPLACE FUNCTION guard_project_independent_decision() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.status IN ('approved','rejected') AND (NEW.decided_by IS NULL OR NEW.decided_by=NEW.created_by) THEN RAISE EXCEPTION 'Independent project budget decision required'; END IF; RETURN NEW; END $$;
CREATE TRIGGER project_budget_independent BEFORE UPDATE ON project_changes FOR EACH ROW EXECUTE FUNCTION guard_project_independent_decision();
CREATE OR REPLACE FUNCTION guard_project_baseline_currency() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.currency<>'EGP' THEN RAISE EXCEPTION 'Project budgets use EGP'; END IF; RETURN NEW; END $$;
CREATE TRIGGER project_baseline_currency BEFORE INSERT ON project_baselines FOR EACH ROW EXECUTE FUNCTION guard_project_baseline_currency();
CREATE OR REPLACE FUNCTION guard_project_dependency() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM project_tasks a JOIN project_tasks b ON b.project_id=a.project_id WHERE a.id=NEW.task_id AND b.id=NEW.depends_on_id) THEN RAISE EXCEPTION 'Dependencies must share project'; END IF; RETURN NEW; END $$;
CREATE TRIGGER project_dependency_guard BEFORE INSERT OR UPDATE ON project_task_dependencies FOR EACH ROW EXECUTE FUNCTION guard_project_dependency();
CREATE OR REPLACE FUNCTION guard_project_record() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Project history cannot be deleted'; END IF;
 IF OLD.status='closed' AND (NEW.status<>'active' OR (to_jsonb(NEW)-'status'-'version'-'actual_end'-'updated_at') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'version'-'actual_end'-'updated_at')) THEN RAISE EXCEPTION 'Closed project requires explicit reopening without editing history'; END IF;
 IF NEW.status<>OLD.status AND (NEW.version<>OLD.version+1 OR NOT EXISTS(SELECT 1 FROM project_activities a WHERE a.project_id=OLD.id AND a.action='lifecycle.requested' AND (a.after_snapshot->>'version')::integer=NEW.version AND a.after_snapshot->>'status'=NEW.status)) THEN RAISE EXCEPTION 'Project status requires reasoned versioned transition evidence'; END IF;
 RETURN NEW; END $$;
CREATE OR REPLACE FUNCTION guard_project_child_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE project bigint;
BEGIN
 project:=coalesce(NEW.project_id,OLD.project_id);
 IF EXISTS(SELECT 1 FROM projects WHERE id=project AND status IN ('closed','cancelled')) THEN RAISE EXCEPTION 'Closed project is read only'; END IF; RETURN NEW;
END $$;
CREATE TRIGGER project_phase_closed BEFORE INSERT OR UPDATE OR DELETE ON project_phases FOR EACH ROW EXECUTE FUNCTION guard_project_child_write();
CREATE TRIGGER project_task_closed BEFORE INSERT OR UPDATE OR DELETE ON project_tasks FOR EACH ROW EXECUTE FUNCTION guard_project_child_write();
CREATE TRIGGER project_milestone_closed BEFORE INSERT OR UPDATE OR DELETE ON project_milestones FOR EACH ROW EXECUTE FUNCTION guard_project_child_write();
CREATE TRIGGER project_requirement_closed BEFORE INSERT OR UPDATE OR DELETE ON project_requirements FOR EACH ROW EXECUTE FUNCTION guard_project_child_write();
CREATE TRIGGER project_link_closed BEFORE INSERT ON project_source_links FOR EACH ROW EXECUTE FUNCTION guard_project_child_write();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Project evidence requires forward migrations.');
    }
};
