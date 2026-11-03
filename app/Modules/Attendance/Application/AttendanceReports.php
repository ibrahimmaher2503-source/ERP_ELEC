<?php

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Application\Contracts\AttendanceApi;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AttendanceReports implements AttendanceApi
{
    public function digest(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function timesheet(int $actor, int $employee, string $month): array
    {
        Validator::make(compact('month'), ['month' => 'required|date_format:Y-m'])->validate();
        $identity = app(OrganizationApi::class)->employeeIdentity($actor, $employee);
        app(AttendanceAccess::class)->authorize($actor, (int) $identity['branch_id']);
        $locked = DB::table('attendance_timesheets')->where('employee_id', $employee)->where('month', $month.'-01')->first();
        if ($locked) {
            $sheet = json_decode($locked->snapshot, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(hash_equals($locked->sha256, $this->digest($sheet)), 409, 'تغيرت بصمة كشف الحضور المقفل.');

            return $sheet;
        }
        $end = CarbonImmutable::parse($month.'-01')->addMonth()->toDateString();
        $schedules = DB::table('attendance_schedules')->where('employee_id', $employee)->where('work_date', '>=', $month.'-01')->where('work_date', '<', $end)->orderBy('work_date')->get();
        $days = $schedules->map(fn ($s) => $this->day($s))->all();
        $totals = [];
        foreach (['worked_minutes', 'late_minutes', 'early_minutes', 'approved_overtime_minutes'] as $key) {
            $totals[$key] = array_sum(array_column($days, $key));
        }

        return ['employee_id' => $employee, 'employee_code' => $identity['code'], 'employee_name' => $identity['name'],
            'branch_id' => (int) $identity['branch_id'], 'month' => $month, 'days' => $days, 'totals' => $totals, 'locked' => false];
    }

    public function day(object $s): array
    {
        $shift = json_decode($s->shift_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $punches = DB::table('attendance_punches')->where('schedule_id', $s->id)->orderBy('occurred_at')->get();
        $adjustments = DB::table('attendance_adjustments')->where('schedule_id', $s->id)->where('status', 'approved')->orderBy('id')->get()->keyBy('kind');
        $correction = isset($adjustments['correction']) ? json_decode($adjustments['correction']->data, true, 512, JSON_THROW_ON_ERROR) : null;
        $in = $punches->firstWhere('kind', 'in');
        $out = $punches->last(fn ($p) => $p->kind === 'out');
        $checkIn = $correction ? CarbonImmutable::parse($correction['check_in']) : ($in ? CarbonImmutable::parse($in->occurred_at) : null);
        $checkOut = $correction ? CarbonImmutable::parse($correction['check_out']) : ($out ? CarbonImmutable::parse($out->occurred_at) : null);
        $breakSeconds = 0;
        $breakStart = null;
        foreach ($punches as $p) {
            if ($p->kind === 'break_start') {
                $breakStart = CarbonImmutable::parse($p->occurred_at);
            } elseif ($p->kind === 'break_end' && $breakStart) {
                $breakSeconds += CarbonImmutable::parse($p->occurred_at)->timestamp - $breakStart->timestamp;
                $breakStart = null;
            }
        }
        $break = $correction ? (int) $correction['break_minutes'] : intdiv($breakSeconds, 60);
        $leave = isset($adjustments['leave']);
        $missing = ! $leave && (($checkIn === null) !== ($checkOut === null) || (! $correction && $breakStart !== null));
        $elapsed = $checkIn && $checkOut ? intdiv(max(0, $checkOut->timestamp - $checkIn->timestamp), 60) : 0;
        $worked = max(0, $elapsed - $break);
        $start = CarbonImmutable::parse($s->starts_at);
        $end = CarbonImmutable::parse($s->ends_at);
        $scheduled = intdiv($end->timestamp - $start->timestamp, 60) - (int) $shift['break_minutes'];
        $overtime = isset($adjustments['overtime']) ? (int) json_decode($adjustments['overtime']->data, true, 512, JSON_THROW_ON_ERROR)['minutes'] : 0;

        return ['schedule_id' => (int) $s->id, 'work_date' => $s->work_date,
            'scheduled_start' => $start->toIso8601String(), 'scheduled_end' => $end->toIso8601String(),
            'check_in' => $checkIn?->utc()->toIso8601String(), 'check_out' => $checkOut?->utc()->toIso8601String(),
            'worked_minutes' => $worked, 'late_minutes' => $checkIn ? max(0, intdiv($checkIn->timestamp - $start->timestamp, 60) - (int) $shift['grace_minutes']) : 0,
            'early_minutes' => $checkOut ? max(0, intdiv($end->timestamp - $checkOut->timestamp, 60)) : 0,
            'break_minutes' => $break, 'missing_punch' => $missing, 'absent' => ! $leave && ! $checkIn && ! $checkOut && $end->isPast(), 'leave' => $leave,
            'extra_minutes' => max(0, $worked - $scheduled), 'approved_overtime_minutes' => min($overtime, max(0, $worked - $scheduled)),
            'correction_id' => isset($adjustments['correction']) ? (int) $adjustments['correction']->id : null];
    }

    public function export(int $actor, int $employee, string $month): array
    {
        return app(AttendanceActions::class)->export($actor, $employee, $month);
    }

    public function workedIntervals(int $actor, int $employee, string $from, string $to): array
    {
        Validator::make(compact('from', 'to'), ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        abort_unless(CarbonImmutable::parse($to)->timestamp - CarbonImmutable::parse($from)->timestamp <= 366 * 86400, 422);
        $identity = app(OrganizationApi::class)->employeeIdentity($actor, $employee);
        app(AttendanceAccess::class)->authorize($actor, (int) $identity['branch_id']);

        return DB::table('attendance_schedules')->where('employee_id', $employee)->whereBetween('work_date', [$from, $to])->orderBy('work_date')->get()
            ->map(fn ($s) => $this->day($s))->filter(fn ($day) => ! $day['missing_punch'] && $day['check_in'] && $day['check_out'])
            ->map(fn ($day) => array_intersect_key($day, array_flip(['schedule_id', 'work_date', 'check_in', 'check_out', 'worked_minutes', 'break_minutes', 'correction_id'])))->values()->all();
    }
}
