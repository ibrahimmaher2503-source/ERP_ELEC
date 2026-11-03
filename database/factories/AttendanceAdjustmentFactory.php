<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Attendance\Domain\AttendanceAdjustment;
use App\Modules\Attendance\Domain\AttendanceSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AttendanceAdjustmentFactory extends Factory
{
    protected $model = AttendanceAdjustment::class;

    public function definition(): array
    {
        return ['schedule_id' => AttendanceSchedule::factory(), 'kind' => 'leave', 'data' => ['reason' => 'DEMO-EG: طلب تجريبي غير معتمد'],
            'status' => 'pending', 'creation_key' => (string) Str::uuid(), 'created_by' => User::factory()->disabledDemo(), 'version' => 1];
    }
}
