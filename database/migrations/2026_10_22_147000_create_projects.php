<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE projects (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, code varchar(60) NOT NULL UNIQUE,
 name varchar(200) NOT NULL, branch_id bigint NOT NULL REFERENCES branches(id), customer_id bigint NOT NULL REFERENCES customers(id),
 customer_name varchar(200) NOT NULL, manager_id bigint NOT NULL REFERENCES users(id), created_by bigint NOT NULL REFERENCES users(id),
 status varchar(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','planned','active','on_hold','completed','closed','cancelled')),
 start_date date NOT NULL, end_date date NOT NULL, description text NOT NULL DEFAULT '', notes text NOT NULL DEFAULT '',
 version integer NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 CHECK(end_date>=start_date)
);
CREATE INDEX projects_branch_status ON projects(branch_id,status);
CREATE TABLE project_members (id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), user_id bigint NOT NULL REFERENCES users(id), UNIQUE(project_id,user_id));
CREATE TABLE project_phases (id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), title varchar(200) NOT NULL, start_date date NOT NULL, end_date date NOT NULL, cancelled boolean NOT NULL DEFAULT false, CHECK(end_date>=start_date));
CREATE TABLE project_tasks (id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), phase_id bigint NOT NULL REFERENCES project_phases(id), title varchar(200) NOT NULL, assigned_to bigint NOT NULL REFERENCES users(id), start_date date NOT NULL, end_date date NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','in_progress','completed','cancelled')), notes text NOT NULL DEFAULT '', completed_by bigint REFERENCES users(id), completed_at timestamptz, version integer NOT NULL DEFAULT 1, CHECK(end_date>=start_date), CHECK((status='completed')=(completed_at IS NOT NULL AND completed_by IS NOT NULL)));
CREATE TABLE project_activities (id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), actor_id bigint NOT NULL REFERENCES users(id), action varchar(80) NOT NULL, before_snapshot jsonb, after_snapshot jsonb NOT NULL, occurred_at timestamptz NOT NULL);
CREATE FUNCTION protect_project_activity() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Project history is append only'; END $$;
CREATE TRIGGER project_activity_immutable BEFORE UPDATE OR DELETE ON project_activities FOR EACH ROW EXECUTE FUNCTION protect_project_activity();
CREATE FUNCTION guard_project_task_phase() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NOT EXISTS(SELECT 1 FROM project_phases WHERE id=NEW.phase_id AND project_id=NEW.project_id) THEN RAISE EXCEPTION 'Task phase project mismatch'; END IF; RETURN NEW; END $$;
CREATE TRIGGER project_task_phase_guard BEFORE INSERT OR UPDATE ON project_tasks FOR EACH ROW EXECUTE FUNCTION guard_project_task_phase();
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label,$scope]) {
            if (str_starts_with($code, 'M07.')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M07 · المشروعات', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
