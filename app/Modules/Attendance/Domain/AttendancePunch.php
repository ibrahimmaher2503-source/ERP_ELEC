<?php

namespace App\Modules\Attendance\Domain;

use Database\Factories\AttendancePunchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(AttendancePunchFactory::class)]
class AttendancePunch extends Model
{
    use HasFactory;

    protected $table = 'attendance_punches';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
