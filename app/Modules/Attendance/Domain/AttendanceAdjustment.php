<?php

namespace App\Modules\Attendance\Domain;

use Database\Factories\AttendanceAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(AttendanceAdjustmentFactory::class)]
class AttendanceAdjustment extends Model
{
    use HasFactory;

    protected $table = 'attendance_adjustments';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer'];
    }
}
