<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Attendance\Domain\AttendanceSchedule;
use App\Modules\Attendance\Domain\AttendanceShift;
use App\Modules\Organization\Domain\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AttendanceScheduleFactory extends Factory
{
    protected $model = AttendanceSchedule::class;

    public function definition(): array
    {
        return ['shift_id' => AttendanceShift::factory(),
            'branch_id' => fn (array $a) => AttendanceShift::findOrFail($a['shift_id'])->branch_id,
            'employee_id' => fn (array $a) => Employee::factory()->create(['branch_id' => $a['branch_id']])->id,
            'work_date' => now('Africa/Cairo')->addDays(7)->toDateString(),
            'starts_at' => fn (array $a) => CarbonImmutable::parse($a['work_date'].' '.AttendanceShift::findOrFail($a['shift_id'])->start_time, 'Africa/Cairo')->utc(),
            'ends_at' => function (array $a) {
                $s = AttendanceShift::findOrFail($a['shift_id']);
                $date = $s->end_time < $s->start_time ? CarbonImmutable::parse($a['work_date'])->addDay()->toDateString() : $a['work_date'];

                return CarbonImmutable::parse($date.' '.$s->end_time, 'Africa/Cairo')->utc();
            },
            'shift_snapshot' => fn (array $a) => AttendanceShift::findOrFail($a['shift_id'])->toArray(), 'creation_key' => (string) Str::uuid(),
            'created_by' => User::factory()->disabledDemo(), 'version' => 1];
    }
}
