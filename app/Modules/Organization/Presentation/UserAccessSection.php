<?php

namespace App\Modules\Organization\Presentation;

use App\Models\User;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\AssignUserRole;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\RbacGuard;
use App\Modules\Organization\Application\RevokeDirectGrant;
use App\Modules\Organization\Application\SetUserBranchScope;
use App\Modules\Organization\Application\SetUserWarehouseScope;
use App\Modules\Organization\Application\UserAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class UserAccessSection extends Component
{
    #[Locked]
    public int $user;

    #[Locked]
    public bool $historyOnly = false;

    public string $selectedRole = '';

    public string $selectedBranch = '';

    public string $selectedWarehouse = '';

    #[Locked]
    public array $roleVersions = [];

    public function boot(): void
    {
        app(RbacGuard::class)->authorize(Auth::user());
    }

    public function mount(int $user, bool $historyOnly = false): void
    {
        $this->user = $user;
        $this->historyOnly = $historyOnly;
        $this->target();
        $this->reloadVersions();
    }

    protected function target(): User
    {
        $target = User::findOrFail($this->user);
        app(UserAccess::class)->authorize(Auth::user(), 'M01.user_view', $target);

        return $target;
    }

    protected function reloadVersions(): void
    {
        $this->roleVersions = DB::table('roles')->pluck('version', 'id')->all();
    }

    public function assignRole(): void
    {
        $this->validate(['selectedRole' => ['required', 'integer', 'exists:roles,id']]);
        $this->changeRole((int) $this->selectedRole, true);
        $this->selectedRole = '';
    }

    public function revokeRole(int $roleId): void
    {
        $this->changeRole($roleId, false);
    }

    protected function changeRole(int $roleId, bool $assign): void
    {
        $this->target();
        app(AssignUserRole::class)->execute(Auth::user(), $roleId, $this->user, $assign, (int) ($this->roleVersions[$roleId] ?? 0));
        $this->reloadVersions();
        $this->dispatch('user-access-updated');
    }

    public function grantScope(): void
    {
        $this->target();
        $this->validate(['selectedBranch' => ['required', 'integer', 'exists:branches,id']]);
        app(SetUserBranchScope::class)->execute(Auth::user(), $this->user, (int) $this->selectedBranch, true);
        $this->selectedBranch = '';
        $this->dispatch('user-access-updated');
    }

    public function revokeScope(int $branchId): void
    {
        $this->target();
        app(SetUserBranchScope::class)->execute(Auth::user(), $this->user, $branchId, false);
        $this->dispatch('user-access-updated');
    }

    public function grantWarehouse(): void
    {
        $this->target();
        $this->validate(['selectedWarehouse' => ['required', 'integer', 'min:1']]);
        app(SetUserWarehouseScope::class)->execute(Auth::user(), $this->user, (int) $this->selectedWarehouse, true);
        $this->selectedWarehouse = '';
        $this->dispatch('user-access-updated');
    }

    public function revokeWarehouse(int $id): void
    {
        $this->target();
        app(SetUserWarehouseScope::class)->execute(Auth::user(), $this->user, $id, false);
        $this->dispatch('user-access-updated');
    }

    public function revokeDirect(int $grantId): void
    {
        $this->target();
        abort_unless(DB::table('access_grants')->where('id', $grantId)->where('user_id', $this->user)->exists(), 404);
        app(RevokeDirectGrant::class)->execute(Auth::user(), $grantId);
        $this->dispatch('user-access-updated');
    }

    public function render()
    {
        $target = $this->target();
        $access = app(PermissionAccess::class);
        $branches = DB::table('branches')->orderBy('name')->get(['id', 'name', 'active']);
        $effective = [];
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if ($code === 'M01.security_admin' && ! $target->email_verified_at) {
                continue;
            }
            if ($scope === 'warehouse') {
                $warehouseLabels = [];
                foreach (DB::table('user_warehouse_scopes')->where('user_id', $target->id)->get() as $w) {
                    if ($access->allows($target, $code, $w->branch_id, $w->warehouse_id)) {
                        $warehouseLabels[] = 'مستودع #'.$w->warehouse_id;
                    }
                }
                if ($warehouseLabels !== []) {
                    $effective[] = ['label' => $label, 'scope' => 'branch', 'branches' => $warehouseLabels];
                }

                continue;
            }
            $ids = $scope === 'branch' ? $access->branchIds($target, $code) : [];
            if (($scope === 'company' && $access->allows($target, $code)) || $ids !== []) {
                $effective[] = ['label' => $label, 'scope' => $scope, 'branches' => $branches->whereIn('id', $ids)->pluck('name')->all()];
            }
        }
        $roleIds = DB::table('user_roles')->where('user_id', $this->user)->pluck('role_id');

        $data = [
            'warehouses' => collect(app(InventoryApi::class)->warehouseDirectory(Auth::id())),
            'warehouseScopes' => DB::table('user_warehouse_scopes')->where('user_id', $this->user)->pluck('warehouse_id')->all(),
            'roles' => DB::table('roles')->whereIn('id', $roleIds)->get(),
            'availableRoles' => DB::table('roles')->whereNotIn('id', $roleIds)->orderBy('id')->get(),
            'branches' => $branches,
            'scopes' => DB::table('user_branch_scopes as s')->join('branches as b', 'b.id', '=', 's.branch_id')->where('s.user_id', $this->user)->get(['b.id', 'b.name', 'b.active']),
            'directGrants' => DB::table('access_grants as g')->leftJoin('permission_catalog as p', 'p.code', '=', 'g.permission_code')->leftJoin('branches as b', 'b.id', '=', 'g.branch_id')->where('g.user_id', $this->user)->get(['g.id', 'g.permission_code', 'p.label', 'b.name as branch_name']),
            'effective' => $effective, 'targetActive' => $target->active, 'targetVerified' => (bool) $target->email_verified_at, 'self' => $this->user === Auth::id(),
            'audits' => $this->historyOnly ? DB::table('access_audits')->leftJoin('users as audit_actor', 'audit_actor.id', '=', 'access_audits.actor_id')->where(function ($query) use ($roleIds) {
                $query->where(function ($query) {
                    $query->where('subject_user_id', $this->user)->where('action', '<>', 'role.permissions_changed');
                });
                foreach ($roleIds as $roleId) {
                    $query->orWhere(function ($query) use ($roleId) {
                        $query->where('action', 'role.permissions_changed')->whereRaw("after_snapshot->>'role_id' = ?", [(string) $roleId]);
                    });
                }
            })->orderByDesc('access_audits.id')->limit(30)->get(['access_audits.*', 'audit_actor.name as actor_name']) : collect(),
        ];
        $roleNames = $data['roles']->concat($data['availableRoles'])->pluck('name', 'id');
        $branchNames = $branches->pluck('name', 'id');
        $safe = function ($json) use ($roleNames, $branchNames) {
            $snapshot = json_decode($json, true) ?: [];
            $result = [];
            foreach (['roles' => ['الأدوار', $roleNames], 'branches' => ['الفروع', $branchNames]] as $key => [$label, $names]) {
                if (isset($snapshot[$key])) {
                    $result[$label] = collect($snapshot[$key])->map(fn ($id) => $names[$id] ?? 'سجل #'.$id)->all();
                }
            }
            if (isset($snapshot['warehouses'])) {
                $result['المستودعات'] = array_map(fn ($id) => 'مستودع #'.$id, $snapshot['warehouses']);
            }
            if (isset($snapshot['permissions'])) {
                $result['الصلاحيات'] = collect($snapshot['permissions'])->map(fn ($code) => PermissionCatalog::DEFINITIONS[$code][0] ?? $code)->all();
            }
            if (isset($snapshot['permission'])) {
                $result['الصلاحية'] = PermissionCatalog::DEFINITIONS[$snapshot['permission']][0] ?? $snapshot['permission'];
            }
            if (isset($snapshot['branch_id'])) {
                $result['الفرع'] = $branchNames[$snapshot['branch_id']] ?? 'غير متاح';
            }
            if (isset($snapshot['revoked'])) {
                $result['مسحوبة'] = (bool) $snapshot['revoked'];
            }

            return $result;
        };
        $labels = ['scope.warehouse_granted' => 'منح نطاق مستودع', 'scope.warehouse_revoked' => 'سحب نطاق مستودع', 'role.assigned' => 'إسناد دور', 'role.revoked' => 'سحب دور', 'scope.branch_granted' => 'منح نطاق فرع', 'scope.branch_revoked' => 'سحب نطاق فرع', 'direct_grant.revoked' => 'سحب منحة مباشرة', 'role.permissions_changed' => 'تعديل مصفوفة دور مشترك'];
        $data['events'] = $data['audits']->map(fn ($event) => [
            'id' => 'access:'.$event->id, 'label' => $labels[$event->action] ?? 'تغيير وصول مباشر', 'actor' => $event->actor_name ?? 'حساب غير متاح', 'occurred_at' => $event->occurred_at,
            'outcome' => $event->outcome, 'scope' => $event->action === 'role.permissions_changed' ? 'سجل غير مباشر · الدور المشترك' : 'تغيير مباشر · المستخدم #'.$this->user,
            'before' => $safe($event->before_snapshot), 'after' => $safe($event->after_snapshot),
        ]);

        return view('organization.user-access-section', $data);
    }
}
