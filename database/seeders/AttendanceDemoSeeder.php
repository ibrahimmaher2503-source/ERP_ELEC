<?php

namespace Database\Seeders;

use App\Modules\Attendance\Domain\AttendanceSchedule;
use App\Modules\Attendance\Domain\AttendanceShift;
use App\Modules\Organization\Domain\Employee;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class AttendanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $expected = app()->environment('testing') ? 'erp_elec_new_test' : 'erp_elec_new';
        if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== $expected) {
            throw new \RuntimeException('Attendance demo is restricted to local/testing PostgreSQL.');
        }
        DB::transaction(function () {
            DB::select('select pg_advisory_xact_lock(10401)');
            DB::select('select pg_advisory_xact_lock(151501)');
            $shift = AttendanceShift::where('code', 'DEMO-EG-SHIFT-DAY')->first()
                ?? AttendanceShift::factory()->create(['code' => 'DEMO-EG-SHIFT-DAY']);
            $employee = Employee::where('code', 'DEMO-EG-EMP-ATTENDANCE')->first()
                ?? Employee::factory()->create(['code' => 'DEMO-EG-EMP-ATTENDANCE', 'branch_id' => $shift->branch_id]);
            $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-demo-eg-attendance-schedule-1')->toString();
            if (! AttendanceSchedule::where('creation_key', $key)->exists()) {
                $s = AttendanceSchedule::factory()->create(['creation_key' => $key, 'shift_id' => $shift->id, 'branch_id' => $shift->branch_id, 'employee_id' => $employee->id]);
                app(RecordAudit::class)->execute(null, 'attendance_schedule', $s->id, 'demo.created', null, ['identity' => 'DEMO-EG-ATTENDANCE-SCHEDULE-001', 'state' => 'planned'], 'succeeded', 'Attendance');
            }
        });
    }
}
