<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\ChangeBranchStatus;
use App\Modules\Organization\Application\OrganizationAccess;
use App\Modules\Organization\Application\SaveBranch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class BranchesPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $editing = false;

    public bool $confirmingStatus = false;

    public array $form = ['code' => '', 'name' => '', 'address' => '', 'manager_name' => ''];

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $version = null;

    #[Locked]
    public ?int $statusId = null;

    #[Locked]
    public ?int $statusVersion = null;

    #[Locked]
    public bool $nextActive = false;

    public function boot(): void
    {
        app(OrganizationAccess::class)->authorizeDirectory(Auth::user());
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        app(OrganizationAccess::class)->authorize(Auth::user(), 'M01.branch_create');
        $this->reset('form', 'editingId', 'version');
        $this->resetValidation();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $access = app(OrganizationAccess::class);
        $branch = $access->branches(Auth::user())->where('id', $id)->first();
        abort_unless($branch, 404);
        $access->authorizeBranch(Auth::user(), 'M01.branch_update', $id);
        $this->editingId = $id;
        $this->version = $branch->version;
        $this->form = ['code' => $branch->code, 'name' => $branch->name, 'address' => $branch->address ?? '', 'manager_name' => $branch->manager_name ?? ''];
        $this->resetValidation();
        $this->editing = true;
    }

    public function save(): void
    {
        try {
            app(SaveBranch::class)->execute(Auth::user(), $this->form, $this->editingId, $this->version);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[str_starts_with($key, 'form.') ? $key : 'form.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->resetValidation();
        $this->editing = false;
        session()->flash('status', 'تم حفظ الفرع بنجاح.');
    }

    public function confirmStatus(int $id): void
    {
        $access = app(OrganizationAccess::class);
        $branch = $access->branches(Auth::user())->where('id', $id)->first();
        abort_unless($branch, 404);
        $access->authorizeBranch(Auth::user(), 'M01.branch_status', $id);
        $this->statusId = $id;
        $this->statusVersion = $branch->version;
        $this->nextActive = ! $branch->active;
        $this->resetValidation();
        $this->confirmingStatus = true;
    }

    public function changeStatus(): void
    {
        abort_unless($this->statusId && $this->statusVersion, 422);
        app(ChangeBranchStatus::class)->execute(Auth::user(), $this->statusId, $this->nextActive, $this->statusVersion);
        $this->statusId = null;
        $this->confirmingStatus = false;
        session()->flash('status', 'تم تحديث حالة الفرع.');
    }

    public function cancelStatus(): void
    {
        $this->statusId = null;
        $this->confirmingStatus = false;
        $this->resetValidation();
    }

    public function render()
    {
        $access = app(OrganizationAccess::class);
        $scope = $access->branches(Auth::user());
        $stats = ['total' => (clone $scope)->count(), 'active' => (clone $scope)->where('active', true)->count(), 'inactive' => (clone $scope)->where('active', false)->count()];
        $query = clone $scope;
        $search = mb_substr(trim($this->search), 0, 200);
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'ilike', '%'.$search.'%')->orWhere('code', 'ilike', '%'.$search.'%');
            });
        }
        if (in_array($this->statusFilter, ['active', 'inactive'], true)) {
            $query->where('active', $this->statusFilter === 'active');
        }

        return view('organization.branches', [
            'branches' => $query->orderBy('id')->paginate(10), 'stats' => $stats, 'access' => $access,
            'canCreate' => $access->allows(Auth::user(), 'M01.branch_create'),
        ])->layout('layouts::app', ['title' => 'الفروع']);
    }
}
