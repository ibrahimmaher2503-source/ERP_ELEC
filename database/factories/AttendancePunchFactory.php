<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Attendance\Domain\AttendancePunch;
use App\Modules\Attendance\Domain\AttendanceSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AttendancePunchFactory extends Factory
{
    protected $model = AttendancePunch::class;

    public function definition(): array
    {
        return ['schedule_id' => AttendanceSchedule::factory()->state(['work_date' => now('Africa/Cairo')->subDays(7)->toDateString()]),
            'employee_id' => fn (array $a) => AttendanceSchedule::findOrFail($a['schedule_id'])->employee_id,
            'branch_id' => fn (array $a) => AttendanceSchedule::findOrFail($a['schedule_id'])->branch_id,
            'occurred_at' => fn (array $a) => AttendanceSchedule::findOrFail($a['schedule_id'])->starts_at,
            'kind' => 'in', 'creation_key' => (string) Str::uuid(), 'created_by' => User::factory()->disabledDemo()];
    }
}
