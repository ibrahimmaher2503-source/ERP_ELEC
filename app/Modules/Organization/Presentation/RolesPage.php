<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\RbacGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class RolesPage extends Component
{
    use WithPagination;

    protected $listeners = ['role-matrix-updated' => '$refresh'];

    public string $search = '';

    public string $scopeFilter = '';

    public int $selectedRole = 0;

    public function boot(): void
    {
        app(RbacGuard::class)->authorize(Auth::user());
    }

    public function mount(): void
    {
        $this->selectedRole = (int) DB::table('roles')->orderBy('id')->value('id');
    }

    public function selectRole(int $role): void
    {
        abort_unless(DB::table('roles')->where('id', $role)->exists(), 404);
        $this->selectedRole = $role;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedScopeFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = DB::table('roles as r')->select('r.*')
            ->selectSub(DB::table('role_permissions')->selectRaw('count(*)')->whereColumn('role_id', 'r.id'), 'permission_count')
            ->selectSub(DB::table('user_roles')->selectRaw('count(*)')->whereColumn('role_id', 'r.id'), 'user_count');
        $search = mb_substr(trim($this->search), 0, 200);
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('r.name', 'ilike', '%'.$search.'%')->orWhere('r.code', 'ilike', '%'.$search.'%'));
        }
        if (in_array($this->scopeFilter, ['company', 'branch', 'warehouse'], true)) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('role_permissions as rp')->join('permission_catalog as pc', 'pc.code', '=', 'rp.permission_code')->whereColumn('rp.role_id', 'r.id')->where('pc.scope', $this->scopeFilter));
        }

        return view('organization.roles', ['roles' => $query->orderBy('r.id')->paginate(8), 'catalog' => DB::table('permission_catalog')->orderBy('code')->get(), 'catalogOnly' => request()->routeIs('organization.permissions'),
            'stats' => ['roles' => DB::table('roles')->count(), 'permissions' => DB::table('permission_catalog')->count(), 'users' => DB::table('users')->count()],
        ])
            ->layout('layouts::app', ['title' => 'الأدوار والصلاحيات']);
    }
}
