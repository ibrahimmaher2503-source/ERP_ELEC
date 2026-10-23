<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\UserAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

class UsersPage extends UserManagementPage
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $branchFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBranchFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $access = app(UserAccess::class);
        $scope = $access->query(Auth::user());
        $stats = ['total' => (clone $scope)->count(), 'active' => (clone $scope)->where('u.active', true)->count(), 'inactive' => (clone $scope)->where('u.active', false)->count()];
        $query = clone $scope;
        $search = mb_substr(trim($this->search), 0, 200);
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('u.name', 'ilike', '%'.$search.'%')->orWhere('u.email', 'ilike', '%'.$search.'%');
            });
        }
        if (in_array($this->statusFilter, ['active', 'inactive'], true)) {
            $query->where('u.active', $this->statusFilter === 'active');
        }
        if ($this->branchFilter !== '') {
            $query->where('u.branch_id', ctype_digit($this->branchFilter) ? (int) $this->branchFilter : -1);
        }

        $users = $query->orderBy('u.id')->paginate(10);
        $roleNames = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->whereIn('ur.user_id', $users->pluck('id'))->get(['ur.user_id', 'r.name'])->groupBy('user_id');
        foreach ($users as $user) {
            $user->role_names = ($roleNames->get($user->id) ?? collect())->pluck('name')->join('، ');
        }

        return view('organization.users', [
            'users' => $users, 'stats' => $stats, 'branches' => $access->branches(Auth::user()),
            'access' => $access, 'canCreate' => $access->can(Auth::user(), 'M01.user_create'),
        ])->layout('layouts::app', ['title' => 'المستخدمون']);
    }
}
