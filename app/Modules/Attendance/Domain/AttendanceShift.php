<?php

namespace App\Modules\Attendance\Domain;

use Database\Factories\AttendanceShiftFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(AttendanceShiftFactory::class)]
class AttendanceShift extends Model
{
    use HasFactory;

    protected $table = 'attendance_shifts';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return ['version' => 'integer', 'break_minutes' => 'integer', 'grace_minutes' => 'integer'];
    }
}
