<?php

namespace App\Modules\Attendance\Domain;

use Database\Factories\AttendanceTimesheetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(AttendanceTimesheetFactory::class)]
class AttendanceTimesheet extends Model
{
    use HasFactory;

    protected $table = 'attendance_timesheets';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'locked_at' => 'immutable_datetime', 'month' => 'immutable_date'];
    }
}
