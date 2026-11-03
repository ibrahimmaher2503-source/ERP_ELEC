<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\EmployeeActions;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class EmployeesPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $branch = '';

    public string $statusFilter = '';

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
        $stats = (clone $directory)->selectRaw('count(*) as total, count(*) filter (where active) as active, count(*) filter (where not active) as inactive')->first();
        $rows = $directory
            ->when($this->branch !== '', fn ($q) => $q->where('branch_id', (int) $this->branch))
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($q) => $q->where('active', $this->statusFilter === 'active'))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.mb_substr($this->search, 0, 200).'%')->orWhere('code', 'ilike', '%'.mb_substr($this->search, 0, 200).'%')))->orderBy('code')->paginate(25);

        return view('organization.employees', ['rows' => $rows, 'branches' => $branches, 'stats' => $stats, 'manageBranches' => $this->branches('M01.employee_manage')]);
    }
}
