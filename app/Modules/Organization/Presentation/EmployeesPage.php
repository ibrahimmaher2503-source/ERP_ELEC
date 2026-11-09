<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\EmployeeActions;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithPagination;

final class EmployeesPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $branch = '';

    public string $statusFilter = '';

    public string $jobFilter = '';

    #[Session]
    public string $sort = 'code';

    #[Session]
    public string $direction = 'asc';

    #[Session]
    public int $perPage = 25;

    public bool $editing = false;

    public array $form = ['branch_id' => '', 'code' => '', 'name' => '', 'starts_on' => '', 'ends_on' => null, 'job_title' => '', 'active' => true];

    #[Locked]
    public ?int $employeeId = null;

    #[Locked]
    public int $version = 0;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBranch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedJobFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 25;
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        abort_unless(in_array($column, ['code', 'name', 'starts_on', 'job_title'], true), 422);
        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->resetPage();
    }

    /** Stops or resumes an employee from the list; the owning action keeps every rule and audit. */
    public function toggleActive(int $id): void
    {
        $r = DB::table('organization_employees')->find($id);
        abort_unless($r, 404);
        app(OrganizationApi::class)->authorize(new AuthorizeRequest(auth()->id(), 'M01.employee_manage', (int) $r->branch_id));
        $data = array_intersect_key((array) $r, ['branch_id' => 1, 'code' => 1, 'name' => 1, 'starts_on' => 1, 'ends_on' => 1, 'job_title' => 1, 'user_id' => 1]);
        $data['active'] = ! $r->active;
        app(EmployeeActions::class)->save(auth()->id(), $id, (int) $r->version, $data);
        session()->flash('status', $r->active ? 'تم إيقاف الموظف.' : 'تم تفعيل الموظف.');
    }

    public function cancel(): void
    {
        $this->editing = false;
    }

    public function create(): void
    {
        abort_unless($this->branches('M01.employee_manage'), 403);
        $this->reset('form', 'employeeId', 'version');
        $this->resetValidation();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $r = DB::table('organization_employees')->find($id);
        abort_unless($r, 404);
        app(OrganizationApi::class)->authorize(new AuthorizeRequest(auth()->id(), 'M01.employee_manage', (int) $r->branch_id));
        $this->employeeId = $id;
        $this->version = (int) $r->version;
        $this->form = array_intersect_key((array) $r, $this->form);
        $this->resetValidation();
        $this->editing = true;
    }

    public function save(): void
    {
        $data = $this->form;
        $data['branch_id'] = (int) $data['branch_id'];
        $data['ends_on'] = $data['ends_on'] ?: null;
        if ($this->employeeId) {
            $data['user_id'] = DB::table('organization_employees')->where('id', $this->employeeId)->value('user_id');
        } else {
            $data['user_id'] = null;
        }
        app(EmployeeActions::class)->save(auth()->id(), $this->employeeId, $this->version, $data);
        $this->editing = false;
        session()->flash('status', 'تم حفظ بيانات الموظف.');
    }

    private function branches(string $permission): array
    {
        return app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest(auth()->id(), $permission));
    }

    public function render()
    {
        $branches = $this->branches('M01.employee_view');
        abort_unless($branches, 403);
        $directory = DB::table('organization_employees')->whereIn('branch_id', array_column($branches, 'id'));
        $today = now('Africa/Cairo')->toDateString();
        $stats = (clone $directory)->selectRaw('count(*) as total, count(*) filter (where active) as active, count(*) filter (where not active) as inactive, count(*) filter (where user_id is not null) as linked, count(*) filter (where ends_on is not null and ends_on >= ?::date and ends_on <= (?::date + 30)) as ending', [$today, $today])->first();
        $jobTitles = (clone $directory)->whereNotNull('job_title')->where('job_title', '<>', '')->distinct()->orderBy('job_title')->pluck('job_title');
        $term = '%'.mb_substr($this->search, 0, 200).'%';
        $rows = $directory
            ->when($this->branch !== '', fn ($q) => $q->where('branch_id', (int) $this->branch))
            ->when($this->jobFilter !== '', fn ($q) => $q->where('job_title', $this->jobFilter))
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($q) => $q->where('active', $this->statusFilter === 'active'))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term)->orWhere('job_title', 'ilike', $term)))
            ->orderBy(in_array($this->sort, ['code', 'name', 'starts_on', 'job_title'], true) ? $this->sort : 'code', $this->direction === 'desc' ? 'desc' : 'asc')->orderBy('id')
            ->paginate(in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 25);

        return view('organization.employees', ['rows' => $rows, 'branches' => $branches, 'stats' => $stats, 'jobTitles' => $jobTitles, 'today' => $today, 'manageBranches' => $this->branches('M01.employee_manage')]);
    }
}
