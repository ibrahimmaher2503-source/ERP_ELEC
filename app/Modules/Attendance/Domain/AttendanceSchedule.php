<?php

namespace App\Modules\Attendance\Domain;

use Database\Factories\AttendanceScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(AttendanceScheduleFactory::class)]
class AttendanceSchedule extends Model
{
    use HasFactory;

    protected $table = 'attendance_schedules';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['shift_snapshot' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'work_date' => 'immutable_date', 'version' => 'integer'];
    }
}
