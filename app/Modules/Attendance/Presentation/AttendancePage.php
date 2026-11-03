<?php

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\AttendanceAccess;
use App\Modules\Attendance\Application\AttendanceActions;
use App\Modules\Attendance\Application\AttendanceImport;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

final class AttendancePage extends Component
{
    use WithFileUploads;

    public string $branch = '';

    public string $employee = '';

    public string $employeeSearch = '';

    public string $month = '';

    public array $shift = ['branch_id' => '', 'code' => '', 'name' => '', 'start_time' => '', 'end_time' => '', 'break_minutes' => '', 'grace_minutes' => ''];

    public array $schedule = ['shift_id' => '', 'date' => ''];

    public array $punch = ['time' => '', 'kind' => ''];

    public array $adjustment = ['schedule_id' => '', 'kind' => '', 'check_in' => '', 'check_out' => '', 'break_minutes' => '', 'minutes' => '', 'reason' => ''];

    public string $reviewReason = '';

    public string $lockReason = '';

    public $file;

    #[Locked]
    public ?int $shiftId = null;

    #[Locked]
    public int $shiftVersion = 0;

    public function updatedBranch(): void
    {
        $this->reset('employee', 'shiftId', 'shiftVersion', 'shift');
    }

    public function editShift(int $id): void
    {
        $s = DB::table('attendance_shifts')->find($id);
        abort_unless($s, 404);
        app(AttendanceAccess::class)->authorize(auth()->id(), (int) $s->branch_id, 'shift_manage');
        $this->shiftId = $id;
        $this->shiftVersion = (int) $s->version;
        $this->branch = (string) $s->branch_id;
        $this->shift = array_intersect_key((array) $s, $this->shift);
        $this->shift['start_time'] = substr($s->start_time, 0, 5);
        $this->shift['end_time'] = substr($s->end_time, 0, 5);
    }

    public function mount(): void
    {
        $this->month = now('Africa/Cairo')->format('Y-m');
    }

    private function localTime(string $time): string
    {
        Validator::make(compact('time'), ['time' => 'required|date_format:Y-m-d\TH:i'])->validate();
        $date = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $time, 'Africa/Cairo');
        abort_unless($date->format('Y-m-d\TH:i') === $time, 422, 'الوقت المحلي غير موجود.');

        return $date->toIso8601String();
    }

    public function saveShift(): void
    {
        $d = $this->shift;
        $d['branch_id'] = (int) $this->branch;
        app(AttendanceActions::class)->saveShift(auth()->id(), $this->shiftId, $this->shiftVersion, $d);
        $this->reset('shift', 'shiftId', 'shiftVersion');
        session()->flash('status', 'تم حفظ الوردية.');
    }

    public function saveSchedule(): void
    {
        app(AttendanceActions::class)->schedule(auth()->id(), (int) $this->employee, (int) $this->schedule['shift_id'], $this->schedule['date'], (string) Str::uuid());
        session()->flash('status', 'تمت جدولة الوردية.');
    }

    public function recordPunch(): void
    {
        app(AttendanceActions::class)->punch(auth()->id(), (int) $this->employee, $this->localTime($this->punch['time']), $this->punch['kind'], (string) Str::uuid());
        $this->reset('punch');
        session()->flash('status', 'تم تسجيل واقعة الحضور.');
    }

    public function requestAdjustment(): void
    {
        $d = ['reason' => $this->adjustment['reason']];
        if ($this->adjustment['kind'] === 'correction') {
            $d += ['check_in' => $this->localTime($this->adjustment['check_in']), 'check_out' => $this->localTime($this->adjustment['check_out']), 'break_minutes' => $this->adjustment['break_minutes']];
        } elseif ($this->adjustment['kind'] === 'overtime') {
            $d['minutes'] = $this->adjustment['minutes'];
        }
        app(AttendanceActions::class)->requestAdjustment(auth()->id(), (int) $this->adjustment['schedule_id'], $this->adjustment['kind'], $d, (string) Str::uuid());
        $this->reset('adjustment');
        session()->flash('status', 'تم إرسال الطلب للمراجعة المستقلة.');
    }

    public function decide(int $id, int $version, bool $approved): void
    {
        app(AttendanceActions::class)->approveAdjustment(auth()->id(), $id, $version, $approved, $this->reviewReason);
        session()->flash('status', 'تم حفظ قرار المراجعة.');
    }

    public function lockSheet(): void
    {
        app(AttendanceActions::class)->lockTimesheet(auth()->id(), (int) $this->employee, $this->month, $this->lockReason);
        session()->flash('status', 'تم إقفال كشف الشهر والاحتفاظ ببصمته.');
    }

    public function import(): void
    {
        $result = app(AttendanceImport::class)->import(auth()->id(), $this->file);
        $this->reset('file');
        session()->flash('status', 'تم استيراد '.count($result['punch_ids']).' واقعة؛ التكرارات تحتفظ بالسجل الأصلي.');
    }

    public function render()
    {
        $access = app(AttendanceAccess::class);
        $branches = $access->branches(auth()->id());
        abort_unless($branches, 403);
        $allowed = in_array((int) $this->branch, array_column($branches, 'id'), true);
        $employees = $allowed ? app(OrganizationApi::class)->employees(auth()->id(), (int) $this->branch, mb_substr($this->employeeSearch, 0, 200), false) : [];
        $sheet = null;
        if ($this->employee !== '') {
            $month = Validator::make(['month' => $this->month], ['month' => 'required|date_format:Y-m']);
            if ($month->fails()) {
                $this->addError('month', $month->errors()->first('month'));
            } else {
                $this->resetValidation('month');
                $sheet = app(AttendanceActions::class)->timesheet(auth()->id(), (int) $this->employee, $this->month);
            }
        }
        if ($allowed && $sheet && ! collect($employees)->contains('id', (int) $this->employee)) {
            $selected = app(OrganizationApi::class)->employeeIdentity(auth()->id(), (int) $this->employee);
            if ((int) $selected['branch_id'] === (int) $this->branch) {
                $employees[] = $selected;
            }
        }
        $shifts = DB::table('attendance_shifts')->whereIn('branch_id', array_column($branches, 'id'))->when($allowed, fn ($q) => $q->where('branch_id', (int) $this->branch))->orderBy('code')->limit(100)->get();
        $scheduleIds = $sheet ? array_column($sheet['days'], 'schedule_id') : [];
        $requests = DB::table('attendance_adjustments')->whereIn('schedule_id', $scheduleIds)->orderByDesc('id')->limit(100)->get();
        $permissions = [];
        foreach (['shift_manage', 'schedule_manage', 'punch_manage', 'adjustment_request', 'adjustment_approve', 'timesheet_lock', 'export'] as $p) {
            $permissions[$p] = in_array((int) $this->branch, array_column($access->branches(auth()->id(), $p), 'id'), true);
        }

        return view('attendance.index', compact('branches', 'employees', 'shifts', 'sheet', 'requests', 'permissions'));
    }
}
