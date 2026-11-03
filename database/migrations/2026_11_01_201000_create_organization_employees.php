<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE organization_employees (
 id bigserial PRIMARY KEY, code varchar(50) NOT NULL UNIQUE, name varchar(200) NOT NULL,
 branch_id bigint NOT NULL REFERENCES branches(id), user_id bigint UNIQUE REFERENCES users(id),
 starts_on date NOT NULL, ends_on date, active boolean NOT NULL DEFAULT true, job_title varchar(200),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK(ends_on IS NULL OR ends_on>=starts_on)
);
CREATE INDEX organization_employee_register ON organization_employees(branch_id,active,code);
CREATE OR REPLACE FUNCTION organization_guard_employee() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Employee history must be retained'; END IF;
 IF ROW(NEW.code,NEW.branch_id) IS DISTINCT FROM ROW(OLD.code,OLD.branch_id) THEN RAISE EXCEPTION 'Employee identity/branch immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER organization_employee_guard BEFORE UPDATE OR DELETE ON organization_employees FOR EACH ROW EXECUTE FUNCTION organization_guard_employee();
SQL);
        foreach (['M01.employee_view', 'M01.employee_manage'] as $key) {
            [$label, $scope] = PermissionCatalog::DEFINITIONS[$key];
            DB::table('permission_catalog')->updateOrInsert(['code' => $key], ['label' => $label, 'scope' => $scope,
                'module' => 'M01 · التنظيم', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Employee identities may be referenced by time/service history; use forward migrations.');
    }
};
