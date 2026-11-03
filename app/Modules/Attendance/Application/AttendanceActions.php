<?php

namespace App\Modules\Attendance\Application;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\System\Application\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AttendanceActions
{
    public function lock(): void
    {
        DB::select('select pg_advisory_xact_lock(10401)');
        DB::select('select pg_advisory_xact_lock(151501)');
    }

    public function audit(int $actor, int $id, string $action, array $details): void
    {
        app(RecordAudit::class)->execute($actor, 'attendance', $id, $action, null, $details, 'succeeded', 'Attendance');
    }

    public function saveShift(int $actor, ?int $id, int $version, array $data): int
    {
        return DB::transaction(function () use ($actor, $id, $version, $data) {
            $this->lock();
            $old = $id ? DB::table('attendance_shifts')->lockForUpdate()->find($id) : null;
            abort_unless(! $id || $old, 404);
            app(AttendanceAccess::class)->authorize($actor, $old?->branch_id ?? (int) ($data['branch_id'] ?? 0), 'shift_manage');
            $d = Validator::make($data, ['branch_id' => 'required|integer|min:1', 'code' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN_-]+$/uD', Rule::unique('attendance_shifts', 'code')->ignore($id)],
                'name' => 'required|string|min:2|max:200', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|different:start_time',
                'break_minutes' => 'required|integer|min:0|max:1439', 'grace_minutes' => 'required|integer|min:0|max:1439'])->validate();
            if ($old) {
                abort_unless((int) $old->version === $version && (int) $old->branch_id === (int) $d['branch_id'] && $old->code === $d['code'], 409);
            }
            $minutes = (($this->clockMinutes($d['end_time']) - $this->clockMinutes($d['start_time'])) + 1440) % 1440;
            if ($d['break_minutes'] >= $minutes || $d['grace_minutes'] >= $minutes) {
                throw ValidationException::withMessages(['shift' => 'الراحة والسماح أقل من مدة الوردية.']);
            }
            $d['version'] = $old ? $version + 1 : 1;
            $d['updated_at'] = now();
            if ($old) {
                DB::table('attendance_shifts')->where('id', $id)->update($d);
            } else {
                $id = DB::table('attendance_shifts')->insertGetId($d);
            }
            $this->audit($actor, $id, 'shift.saved', ['version' => $d['version'], 'branch_id' => $d['branch_id']]);

            return $id;
        }, 3);
    }

    private function clockMinutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private function replay(int $actor, string $key, string $type, array $data): ?int
    {
        Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();
        $r = DB::table('attendance_requests')->where('creation_key', $key)->first();
        if ($r) {
            abort_unless((int) $r->created_by === $actor && $r->type === $type && hash_equals($r->payload_hash, hash('sha256', json_encode($data, JSON_THROW_ON_ERROR))), 409, 'مفتاح الطلب مستخدم لمحتوى آخر.');

            return (int) $r->source_id;
        }

        return null;
    }

    private function remember(int $actor, string $key, string $type, array $data, int $id): int
    {
        DB::table('attendance_requests')->insert(['creation_key' => $key, 'type' => $type, 'payload_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'source_id' => $id, 'created_by' => $actor]);

        return $id;
    }

    public function unlocked(int $employee, string $workDate): void
    {
        abort_if(DB::table('attendance_timesheets')->where('employee_id', $employee)->where('month', substr($workDate, 0, 7).'-01')->exists(), 409, 'كشف شهر بداية الوردية مقفل.');
    }

    public function schedule(int $actor, int $employee, int $shift, string $workDate, string $key): int
    {
        Validator::make(['date' => $workDate], ['date' => 'required|date_format:Y-m-d'])->validate();

        return DB::transaction(function () use ($actor, $employee, $shift, $workDate, $key) {
            $this->lock();
            $s = DB::table('attendance_shifts')->find($shift);
            abort_unless($s, 404);
            app(AttendanceAccess::class)->authorize($actor, (int) $s->branch_id, 'schedule_manage');
            $identity = app(OrganizationApi::class)->employee($actor, $employee, (int) $s->branch_id, $workDate);
            $payload = [$employee, $shift, $workDate];
            if ($old = $this->replay($actor, $key, 'schedule', $payload)) {
                return $old;
            }
            $this->unlocked($employee, $workDate);
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $workDate.' '.substr($s->start_time, 0, 5), 'Africa/Cairo');
            $endDate = substr($s->end_time, 0, 5) < substr($s->start_time, 0, 5) ? CarbonImmutable::parse($workDate, 'Africa/Cairo')->addDay()->toDateString() : $workDate;
            $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $endDate.' '.substr($s->end_time, 0, 5), 'Africa/Cairo');
            abort_unless($start->format('Y-m-d H:i') === $workDate.' '.substr($s->start_time, 0, 5) && $end->format('Y-m-d H:i') === $endDate.' '.substr($s->end_time, 0, 5), 422, 'وقت الوردية يقع في ساعة محلية غير موجودة.');
            app(OrganizationApi::class)->employee($actor, $employee, (int) $s->branch_id, $endDate);
            abort_if(DB::table('attendance_schedules')->where('employee_id', $employee)->where(fn ($q) => $q->where('work_date', $workDate)->orWhere(fn ($q) => $q->where('starts_at', '<', $end)->where('ends_at', '>', $start)))->exists(), 409, 'جدولة مكررة أو متداخلة.');
            $id = DB::table('attendance_schedules')->insertGetId(['employee_id' => $employee, 'branch_id' => $s->branch_id, 'shift_id' => $shift, 'work_date' => $workDate,
                'starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'shift_snapshot' => json_encode((array) $s + ['employee' => $identity], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'creation_key' => $key, 'created_by' => $actor]);
            $this->audit($actor, $id, 'schedule.created', ['employee_id' => $employee, 'work_date' => $workDate]);

            return $this->remember($actor, $key, 'schedule', $payload, $id);
        }, 3);
    }

    public function instant(string $value): CarbonImmutable
    {
        Validator::make(['time' => $value], ['time' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', 'date']])->validate();

        return CarbonImmutable::parse($value)->utc();
    }

    public function punch(int $actor, int $employee, string $occurredAt, string $kind, string $key): int
    {
        $time = $this->instant($occurredAt);
        Validator::make(compact('kind'), ['kind' => 'required|in:in,out,break_start,break_end'])->validate();
        abort_if($time->isFuture(), 422, 'لا يسجل حضور مستقبلي.');

        return DB::transaction(function () use ($actor, $employee, $time, $kind, $key) {
            $this->lock();
            $identity = app(OrganizationApi::class)->employeeIdentity($actor, $employee);
            app(AttendanceAccess::class)->authorize($actor, (int) $identity['branch_id'], 'punch_manage');
            app(OrganizationApi::class)->employee($actor, $employee, (int) $identity['branch_id'], $time->setTimezone('Africa/Cairo')->toDateString());
            $payload = [$employee, $time->toIso8601String(), $kind];
            if ($old = $this->replay($actor, $key, 'punch', $payload)) {
                return $old;
            }
            $duplicate = DB::table('attendance_punches')->where('employee_id', $employee)->where('occurred_at', $time)->first();
            if ($duplicate) {
                abort_unless($duplicate->kind === $kind, 409);

                return $this->remember($actor, $key, 'punch', $payload, (int) $duplicate->id);
            }
            $date = $time->setTimezone('Africa/Cairo')->toDateString();
            $s = DB::table('attendance_schedules')->where('employee_id', $employee)->where('starts_at', '<=', $time)->where('ends_at', '>=', $time)->orderBy('starts_at')->first();
            if (! $s && $kind !== 'in') {
                $last = DB::table('attendance_punches')->where('employee_id', $employee)->where('occurred_at', '<', $time)->orderByDesc('occurred_at')->first();
                if ($last && $last->kind !== 'out') {
                    $s = DB::table('attendance_schedules')->find($last->schedule_id);
                }
            }
            $s ??= DB::table('attendance_schedules')->where('employee_id', $employee)->where('work_date', $date)->first();
            abort_unless($s, 422, 'يلزم جدول وردية واضح لهذه الواقعة.');
            $this->unlocked($employee, $s->work_date);
            $endDate = CarbonImmutable::parse($s->ends_at)->setTimezone('Africa/Cairo')->toDateString();
            abort_unless($date >= $s->work_date && $date <= $endDate, 422, 'واقعة الحضور خارج يومي الوردية المحددة.');
            $events = DB::table('attendance_punches')->where('schedule_id', $s->id)->orderBy('occurred_at')->get()->map(fn ($p) => ['time' => CarbonImmutable::parse($p->occurred_at)->timestamp, 'kind' => $p->kind])->all();
            $events[] = ['time' => $time->timestamp, 'kind' => $kind];
            usort($events, fn ($a, $b) => $a['time'] <=> $b['time']);
            $state = 'out';
            foreach ($events as $event) {
                $next = match ($event['kind']) {
                    'in' => $state === 'out' ? 'in' : null,
                    'break_start' => $state === 'in' ? 'break' : null,
                    'break_end' => $state === 'break' ? 'in' : null,
                    'out' => $state === 'in' ? 'out' : null,
                };
                abort_unless($next, 409, 'تتابع وقائع الحضور متعارض؛ اطلب تصحيحاً موثقاً.');
                $state = $next;
            }
            abort_if(count(array_filter($events, fn ($e) => $e['kind'] === 'in')) > 1, 409, 'للموظف بداية حضور واحدة لهذه الوردية؛ التصحيح يحتفظ بالأصل.');
            $id = DB::table('attendance_punches')->insertGetId(['schedule_id' => $s->id, 'employee_id' => $employee, 'branch_id' => $identity['branch_id'], 'occurred_at' => $time, 'kind' => $kind, 'creation_key' => $key, 'created_by' => $actor]);
            $this->audit($actor, $id, 'punch.recorded', ['employee_id' => $employee, 'schedule_id' => $s->id, 'kind' => $kind, 'occurred_at' => $time->toIso8601String()]);

            return $this->remember($actor, $key, 'punch', $payload, $id);
        }, 3);
    }

    public function requestAdjustment(int $actor, int $schedule, string $kind, array $data, string $key): int
    {
        Validator::make(compact('kind'), ['kind' => 'required|in:correction,leave,overtime'])->validate();
        $rules = match ($kind) {
            'correction' => ['check_in' => 'required|string', 'check_out' => 'required|string', 'break_minutes' => 'required|integer|min:0|max:1439', 'reason' => 'required|string|min:5|max:2000'],
            'leave' => ['reason' => 'required|string|min:5|max:2000'],
            'overtime' => ['minutes' => 'required|integer|min:1|max:1439', 'reason' => 'required|string|min:5|max:2000'],
        };
        abort_if(array_diff(array_keys($data), array_keys($rules)), 422);
        $d = Validator::make($data, $rules)->validate();

        return DB::transaction(function () use ($actor, $schedule, $kind, $d, $key) {
            $this->lock();
            $s = DB::table('attendance_schedules')->find($schedule);
            abort_unless($s, 404);
            app(AttendanceAccess::class)->authorize($actor, (int) $s->branch_id, 'adjustment_request');
            $payload = [$schedule, $kind, $d];
            if ($old = $this->replay($actor, $key, 'adjustment', $payload)) {
                return $old;
            }
            $this->unlocked((int) $s->employee_id, $s->work_date);
            $this->validateAdjustment($s, $kind, $d);
            abort_if(DB::table('attendance_adjustments')->where('schedule_id', $schedule)->where('kind', $kind)->where('status', 'pending')->exists(), 409, 'يوجد طلب مماثل قيد المراجعة.');
            $id = DB::table('attendance_adjustments')->insertGetId(['schedule_id' => $schedule, 'kind' => $kind, 'data' => json_encode($d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'creation_key' => $key, 'created_by' => $actor]);
            $this->audit($actor, $id, 'adjustment.requested', ['schedule_id' => $schedule, 'kind' => $kind]);

            return $this->remember($actor, $key, 'adjustment', $payload, $id);
        }, 3);
    }

    private function validateAdjustment(object $s, string $kind, array $data): void
    {
        if ($kind === 'correction') {
            $in = $this->instant($data['check_in']);
            $out = $this->instant($data['check_out']);
            abort_unless($in->lt($out) && ! $out->isFuture() && $in->setTimezone('Africa/Cairo')->toDateString() === $s->work_date
                && $out->timestamp - $in->timestamp <= 90000 && $out->timestamp - $in->timestamp > $data['break_minutes'] * 60, 422, 'فترة التصحيح غير صالحة.');
        } else {
            $row = app(AttendanceReports::class)->day($s);
            if ($kind === 'leave') {
                abort_unless($row['check_in'] === null && $row['check_out'] === null, 409, 'الإجازة لا تمحو حضوراً فعلياً.');
            } else {
                abort_unless(! $row['missing_punch'] && $data['minutes'] <= $row['extra_minutes'], 422, 'الوقت الإضافي لا يتجاوز الوقائع الفعلية.');
            }
        }
    }

    public function approveAdjustment(int $actor, int $id, int $version, bool $approved, string $reason): void
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:2000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $approved, $reason) {
            $this->lock();
            $r = DB::table('attendance_adjustments')->lockForUpdate()->find($id);
            abort_unless($r, 404);
            $s = DB::table('attendance_schedules')->find($r->schedule_id);
            app(AttendanceAccess::class)->authorize($actor, (int) $s->branch_id, 'adjustment_approve');
            abort_unless((int) $r->created_by !== $actor, 403, 'يلزم اعتماد مستقل عن مقدم الطلب.');
            abort_unless((int) $r->version === $version && $r->status === 'pending', 409);
            $this->unlocked((int) $s->employee_id, $s->work_date);
            if ($approved) {
                $this->validateAdjustment($s, $r->kind, json_decode($r->data, true, 512, JSON_THROW_ON_ERROR));
            }
            DB::table('attendance_adjustments')->where('id', $id)->update(['status' => $approved ? 'approved' : 'rejected', 'reviewed_by' => $actor, 'reason' => $reason, 'reviewed_at' => now(), 'version' => $version + 1]);
            $this->audit($actor, $id, $approved ? 'adjustment.approved' : 'adjustment.rejected', ['schedule_id' => $s->id, 'kind' => $r->kind, 'reason' => $reason]);
        }, 3);
    }

    public function timesheet(int $actor, int $employee, string $month): array
    {
        return app(AttendanceReports::class)->timesheet($actor, $employee, $month);
    }

    public function lockTimesheet(int $actor, int $employee, string $month, string $reason): int
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $employee, $month, $reason) {
            $this->lock();
            $sheet = $this->timesheet($actor, $employee, $month);
            app(AttendanceAccess::class)->authorize($actor, $sheet['branch_id'], 'timesheet_lock');
            $old = DB::table('attendance_timesheets')->where('employee_id', $employee)->where('month', $month.'-01')->first();
            if ($old) {
                return (int) $old->id;
            }
            abort_unless($sheet['days'] !== [] && CarbonImmutable::parse($month.'-01', 'Africa/Cairo')->addMonth()->lte(now('Africa/Cairo')), 422, 'الإقفال لشهر منتهٍ ذي جدول حضور.');
            abort_if(collect($sheet['days'])->contains('missing_punch', true), 409, 'صحح الوقائع الناقصة قبل الإقفال.');
            abort_if(DB::table('attendance_adjustments')->whereIn('schedule_id', array_column($sheet['days'], 'schedule_id'))->where('status', 'pending')->exists(), 409, 'احسم الطلبات المعلقة قبل الإقفال.');
            $sheet['locked'] = true;
            $json = json_encode($sheet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $digest = app(AttendanceReports::class)->digest($sheet);
            $id = DB::table('attendance_timesheets')->insertGetId(['employee_id' => $employee, 'branch_id' => $sheet['branch_id'], 'month' => $month.'-01', 'snapshot' => $json, 'sha256' => $digest, 'locked_by' => $actor, 'reason' => $reason]);
            $this->audit($actor, $id, 'timesheet.locked', ['employee_id' => $employee, 'month' => $month, 'sha256' => $digest, 'reason' => $reason]);

            return $id;
        }, 3);
    }

    public function export(int $actor, int $employee, string $month): array
    {
        $sheet = $this->timesheet($actor, $employee, $month);
        app(AttendanceAccess::class)->authorize($actor, $sheet['branch_id'], 'export');
        abort_unless($sheet['locked'], 409, 'التصدير المعتمد من كشف مقفل فقط.');

        return $sheet;
    }
}
