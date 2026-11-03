<?php

namespace App\Modules\Attendance\Application\Contracts;

interface AttendanceApi
{
    public function timesheet(int $actor, int $employee, string $month): array;

    public function export(int $actor, int $employee, string $month): array;

    /** Factual intervals only: no wage, rate, approval or labor-cost posting authority. */
    public function workedIntervals(int $actor, int $employee, string $from, string $to): array;
}
