<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Attendance\Application\Contracts\AttendanceApi;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Employee follow-up file: job data from Organization plus attendance facts read through the Attendance contract. */
class EmployeeProfilePage extends Component
{
    #[Locked]
    public int $employee;

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    #[Url(as: 'month')]
    public string $month = '';

    public function mount(int $employee): void
    {
        $this->employee = $employee;
        $this->row();
        if (Validator::make(['month' => $this->month], ['month' => 'required|date_format:Y-m'])->fails()) {
            $this->month = now('Africa/Cairo')->format('Y-m');
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['overview', 'attendance', 'job'], true) ? $tab : 'overview';
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = CarbonImmutable::parse($this->month.'-01')->addMonths($delta > 0 ? 1 : -1)->format('Y-m');
    }

    public function goToCurrentMonth(): void
    {
        $this->month = now('Africa/Cairo')->format('Y-m');
    }

    private function row(): object
    {
        $row = DB::table('organization_employees')->find($this->employee);
        abort_unless($row, 404);
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest(auth()->id(), 'M01.employee_view', (int) $row->branch_id));
        } catch (ContractException $e) {
            abort($e->getCode() ?: 403);
        }

        return $row;
    }

    /** @return array{sheet: ?array, error: ?string} */
    private function attendance(): array
    {
        try {
            return ['sheet' => app(AttendanceApi::class)->timesheet(auth()->id(), $this->employee, $this->month), 'error' => null];
        } catch (HttpException $e) {
            return ['sheet' => null, 'error' => in_array($e->getStatusCode(), [403, 404], true) ? 'ليس لديك صلاحية عرض الحضور لفرع هذا الموظف.' : 'تعذر تحميل كشف الحضور.'];
        }
    }

    private function summary(array $days, string $today): array
    {
        $s = ['scheduled' => count($days), 'present' => 0, 'absent' => 0, 'leave' => 0, 'missing' => 0, 'upcoming' => 0, 'late_minutes' => 0, 'late_days' => 0, 'worked_minutes' => 0, 'overtime_minutes' => 0];
        foreach ($days as $d) {
            $s['present'] += $d['check_in'] ? 1 : 0;
            $s['absent'] += $d['absent'] ? 1 : 0;
            $s['leave'] += $d['leave'] ? 1 : 0;
            $s['missing'] += $d['missing_punch'] ? 1 : 0;
            $s['upcoming'] += (! $d['check_in'] && ! $d['absent'] && ! $d['leave'] && $d['work_date'] >= $today) ? 1 : 0;
            $s['late_minutes'] += $d['late_minutes'];
            $s['late_days'] += $d['late_minutes'] > 0 ? 1 : 0;
            $s['worked_minutes'] += $d['worked_minutes'];
            $s['overtime_minutes'] += $d['approved_overtime_minutes'];
        }
        $s['attendance_rate'] = $s['scheduled'] > 0 ? intdiv($s['present'] * 100, max(1, $s['present'] + $s['absent'])) : null;

        return $s;
    }

    public function render()
    {
        $row = $this->row();
        $today = now('Africa/Cairo')->toDateString();
        $branches = app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest(auth()->id(), 'M01.employee_view'));
        $branchName = array_column($branches, 'name', 'id')[$row->branch_id] ?? 'غير محدد';
        $attendance = $this->attendance();
        $sheet = $attendance['sheet'];
        $todayDay = $sheet ? collect($sheet['days'])->firstWhere('work_date', $today) : null;
        $canManage = collect(app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest(auth()->id(), 'M01.employee_manage')))->contains('id', (int) $row->branch_id);
        $canExport = false;
        try {
            $canExport = collect(app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest(auth()->id(), 'M15.export')))->contains('id', (int) $row->branch_id);
        } catch (ContractException) {
        }

        return view('organization.employee-profile', [
            'e' => $row, 'branchName' => $branchName, 'today' => $today, 'sheet' => $sheet, 'attendanceError' => $attendance['error'],
            'summary' => $sheet ? $this->summary($sheet['days'], $today) : null, 'todayDay' => $todayDay,
            'canManage' => $canManage, 'canExport' => $canExport,
        ]);
    }
}
