<?php

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\AttendanceActions;

final class AttendanceExportController
{
    public function __invoke(int $employee, string $month)
    {
        $sheet = app(AttendanceActions::class)->export(auth()->id(), $employee, $month);

        return response()->streamDownload(function () use ($sheet) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['work_date', 'check_in', 'check_out', 'worked_minutes', 'late_minutes', 'early_minutes', 'break_minutes', 'absent', 'leave', 'approved_overtime_minutes'], ',', '"', '');
            foreach ($sheet['days'] as $day) {
                fputcsv($stream, array_map(fn ($key) => is_bool($day[$key]) ? (int) $day[$key] : $day[$key], ['work_date', 'check_in', 'check_out', 'worked_minutes', 'late_minutes', 'early_minutes', 'break_minutes', 'absent', 'leave', 'approved_overtime_minutes']), ',', '"', '');
            }
            fclose($stream);
        }, 'attendance-'.$employee.'-'.$month.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
