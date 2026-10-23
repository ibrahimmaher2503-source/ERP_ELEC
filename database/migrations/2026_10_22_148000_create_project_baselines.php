<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE project_baselines (id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), revision integer NOT NULL, currency char(3) NOT NULL, total numeric(24,8) NOT NULL CHECK(total>=0), snapshot jsonb NOT NULL, approved_by bigint NOT NULL REFERENCES users(id), approved_at timestamptz NOT NULL, UNIQUE(project_id,revision));
CREATE TABLE project_changes (id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), creation_key uuid NOT NULL UNIQUE, base_revision integer NOT NULL, reason text NOT NULL, proposal jsonb NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')), created_by bigint NOT NULL REFERENCES users(id), decided_by bigint REFERENCES users(id), decided_at timestamptz, decision_reason text, created_at timestamptz NOT NULL);
CREATE UNIQUE INDEX project_change_pending ON project_changes(project_id) WHERE status='pending';
CREATE FUNCTION protect_project_baseline() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Approved project baselines are immutable'; END $$;
CREATE TRIGGER project_baseline_immutable BEFORE UPDATE OR DELETE ON project_baselines FOR EACH ROW EXECUTE FUNCTION protect_project_baseline();
CREATE FUNCTION guard_project_change() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Project change history cannot be deleted'; END IF; IF OLD.status<>'pending' OR NEW.project_id<>OLD.project_id OR NEW.proposal<>OLD.proposal OR NEW.base_revision<>OLD.base_revision OR NEW.created_by<>OLD.created_by OR NEW.reason<>OLD.reason OR NEW.creation_key<>OLD.creation_key THEN RAISE EXCEPTION 'Project change proposal is immutable'; END IF; RETURN NEW; END $$;
CREATE TRIGGER project_change_immutable BEFORE UPDATE OR DELETE ON project_changes FOR EACH ROW EXECUTE FUNCTION guard_project_change();
SQL);
    }

    public function down(): void {}
};
