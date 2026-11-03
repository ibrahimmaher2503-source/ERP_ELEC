<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE attendance_shifts (
 id bigserial PRIMARY KEY, branch_id bigint NOT NULL REFERENCES branches(id), code varchar(50) NOT NULL UNIQUE, name varchar(200) NOT NULL,
 start_time time NOT NULL, end_time time NOT NULL, break_minutes integer NOT NULL CHECK(break_minutes>=0 AND break_minutes<1440),
 grace_minutes integer NOT NULL CHECK(grace_minutes>=0 AND grace_minutes<1440), version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), CHECK(start_time<>end_time)
);
CREATE TABLE attendance_schedules (
 id bigserial PRIMARY KEY, employee_id bigint NOT NULL REFERENCES organization_employees(id), branch_id bigint NOT NULL REFERENCES branches(id),
 shift_id bigint NOT NULL REFERENCES attendance_shifts(id), work_date date NOT NULL, starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL,
 shift_snapshot jsonb NOT NULL, creation_key uuid NOT NULL UNIQUE, created_by bigint NOT NULL REFERENCES users(id),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(employee_id,work_date), CHECK(ends_at>starts_at AND ends_at<=starts_at+interval '25 hours')
);
CREATE INDEX attendance_schedule_intervals ON attendance_schedules(employee_id,starts_at,ends_at);
CREATE TABLE attendance_punches (
 id bigserial PRIMARY KEY, schedule_id bigint NOT NULL REFERENCES attendance_schedules(id), employee_id bigint NOT NULL REFERENCES organization_employees(id),
 branch_id bigint NOT NULL REFERENCES branches(id), occurred_at timestamptz NOT NULL, kind varchar(12) NOT NULL CHECK(kind IN ('in','out','break_start','break_end')),
 creation_key uuid NOT NULL UNIQUE, created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(employee_id,occurred_at)
);
CREATE INDEX attendance_punch_sequence ON attendance_punches(employee_id,occurred_at);
CREATE TABLE attendance_adjustments (
 id bigserial PRIMARY KEY, schedule_id bigint NOT NULL REFERENCES attendance_schedules(id), kind varchar(12) NOT NULL CHECK(kind IN ('correction','leave','overtime')),
 data jsonb NOT NULL, status varchar(12) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
 creation_key uuid NOT NULL UNIQUE, created_by bigint NOT NULL REFERENCES users(id), reviewed_by bigint REFERENCES users(id),
 reason text, version integer NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamptz NOT NULL DEFAULT now(), reviewed_at timestamptz,
 CHECK(reviewed_by IS NULL OR reviewed_by<>created_by),
 CHECK((status='pending' AND reviewed_by IS NULL AND reviewed_at IS NULL) OR (status<>'pending' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND reason IS NOT NULL))
);
CREATE INDEX attendance_approved_facts ON attendance_adjustments(schedule_id,kind,id) WHERE status='approved';
CREATE TABLE attendance_timesheets (
 id bigserial PRIMARY KEY, employee_id bigint NOT NULL REFERENCES organization_employees(id), branch_id bigint NOT NULL REFERENCES branches(id),
 month date NOT NULL CHECK(extract(day from month)=1), snapshot jsonb NOT NULL, sha256 char(64) NOT NULL,
 locked_by bigint NOT NULL REFERENCES users(id), reason text NOT NULL, locked_at timestamptz NOT NULL DEFAULT now(), UNIQUE(employee_id,month)
);
CREATE TABLE attendance_requests (
 creation_key uuid PRIMARY KEY, type varchar(20) NOT NULL, payload_hash char(64) NOT NULL, source_id bigint NOT NULL,
 created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE OR REPLACE FUNCTION attendance_guard_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR OLD.status<>'pending' THEN RAISE EXCEPTION 'Attendance decisions are immutable'; END IF;
 IF ROW(NEW.schedule_id,NEW.kind,NEW.data,NEW.creation_key,NEW.created_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.schedule_id,OLD.kind,OLD.data,OLD.creation_key,OLD.created_by,OLD.created_at) THEN RAISE EXCEPTION 'Attendance request evidence is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER attendance_adjustment_history BEFORE UPDATE OR DELETE ON attendance_adjustments FOR EACH ROW EXECUTE FUNCTION attendance_guard_adjustment();
CREATE OR REPLACE FUNCTION attendance_guard_shift() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR ROW(NEW.branch_id,NEW.code) IS DISTINCT FROM ROW(OLD.branch_id,OLD.code) THEN RAISE EXCEPTION 'Shift identity is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER attendance_shift_identity BEFORE UPDATE OR DELETE ON attendance_shifts FOR EACH ROW EXECUTE FUNCTION attendance_guard_shift();
CREATE OR REPLACE FUNCTION attendance_guard_locked_month() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE schedule_row record; BEGIN
 IF TG_TABLE_NAME='attendance_schedules' THEN schedule_row:=NEW;
 ELSE SELECT s.* INTO schedule_row FROM attendance_schedules s WHERE s.id=NEW.schedule_id; END IF;
 PERFORM pg_advisory_xact_lock(10401);
 IF EXISTS(SELECT 1 FROM attendance_timesheets t WHERE t.employee_id=schedule_row.employee_id AND t.month=date_trunc('month',schedule_row.work_date)::date) THEN RAISE EXCEPTION 'Timesheet is locked'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER attendance_schedule_month BEFORE INSERT ON attendance_schedules FOR EACH ROW EXECUTE FUNCTION attendance_guard_locked_month();
CREATE TRIGGER attendance_punch_month BEFORE INSERT ON attendance_punches FOR EACH ROW EXECUTE FUNCTION attendance_guard_locked_month();
CREATE TRIGGER attendance_adjustment_month BEFORE INSERT OR UPDATE ON attendance_adjustments FOR EACH ROW EXECUTE FUNCTION attendance_guard_locked_month();
SQL);
        foreach (['schedules', 'punches', 'timesheets', 'requests'] as $table) {
            DB::unprepared("CREATE TRIGGER attendance_{$table}_immutable BEFORE UPDATE OR DELETE ON attendance_{$table} FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record()");
        }
        foreach (PermissionCatalog::DEFINITIONS as $key => [$label, $scope]) {
            if (str_starts_with($key, 'M15.')) {
                DB::table('permission_catalog')->updateOrInsert(['code' => $key], ['label' => $label, 'scope' => $scope, 'module' => 'M15 · الحضور والانصراف', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Attendance and employment history must be retained; use forward migrations.');
    }
};
