<?php

namespace Database\Factories;

use App\Modules\Attendance\Application\AttendanceActions;
use App\Modules\Attendance\Domain\AttendanceTimesheet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class AttendanceTimesheetFactory extends Factory
{
    protected $model = AttendanceTimesheet::class;

    public function definition(): array
    {
        throw new \RuntimeException('Use lockFromFacts with authorized, genuine attendance sources; never manufacture locked history.');
    }

    public function lockFromFacts(int $actor, int $employee, string $month, string $reason): AttendanceTimesheet
    {
        abort_unless(app()->environment('testing') && DB::connection()->getDriverName() === 'pgsql' && DB::connection()->getDatabaseName() === 'erp_elec_new_test', 403);

        return AttendanceTimesheet::findOrFail(app(AttendanceActions::class)->lockTimesheet($actor, $employee, $month, $reason));
    }
}
