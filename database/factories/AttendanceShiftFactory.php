<?php

namespace Database\Factories;

use App\Modules\Attendance\Domain\AttendanceShift;
use App\Modules\Organization\Domain\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AttendanceShiftFactory extends Factory
{
    protected $model = AttendanceShift::class;

    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'code' => 'DEMO-EG-SHIFT-'.Str::upper(Str::random(8)), 'name' => 'وردية خدمة تجريبية — القاهرة',
            'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 30, 'grace_minutes' => 0, 'version' => 1];
    }
}
