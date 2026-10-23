<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserAccess
{
    private const PERMISSIONS = ['M01.user_admin', 'M01.user_create', 'M01.user_view', 'M01.user_update', 'M01.user_status', 'M01.user_password_reset'];

    public function can(User $actor, string $permission, ?object $target = null): bool
    {
        if (! in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }
        if (! DB::table('users')->where('id', $actor->id)->where('active', true)->exists()) {
            return false;
        }
        $permissions = app(PermissionAccess::class);
        $admin = $permissions->allows($actor, 'M01.user_admin');
        if ($permission === 'M01.user_admin') {
            return $admin;
        }
        if ($target && $target->id === $actor->id && $permission !== 'M01.user_view') {
            return false;
        }
        if ($target && $permission !== 'M01.user_view' && $permissions->securityDesignated($target->id) && ! $permissions->allows($actor, 'M01.security_admin')) {
            return false;
        }
        if ($admin) {
            return true;
        }
        if ($permission === 'M01.user_create') {
            return $permissions->allows($actor, $permission);
        }
        if (! $target?->branch_id) {
            return false;
        }
        if ($permission !== 'M01.user_view') {
            // Scoped account operators cannot take over accounts holding broader authority.
            $protected = DB::table('access_grants')->where('user_id', $target->id)->where(function ($query) use ($target) {
                $query->whereNull('branch_id')->orWhere('branch_id', '<>', $target->branch_id)
                    ->orWhereNotIn('permission_code', ['M01.branch_view', 'M01.user_view']);
            })->exists();
            if ($protected || DB::table('user_roles')->where('user_id', $target->id)->exists()) {
                return false;
            }
        }

        return $permissions->allows($actor, $permission, $target->branch_id);
    }

    public function authorize(User $actor, string $permission, ?object $target = null): void
    {
        abort_unless($this->can($actor, $permission, $target), 403, 'ليس لديك صلاحية لإدارة هذا الحساب.');
    }

    public function authorizeDirectory(User $actor): void
    {
        abort_unless($this->can($actor, 'M01.user_admin') || $this->can($actor, 'M01.user_create') ||
            app(PermissionAccess::class)->branchIds($actor, 'M01.user_view') !== [], 403);
    }

    public function query(User $actor): Builder
    {
        $this->authorizeDirectory($actor);
        $query = DB::table('users as u')->leftJoin('branches as b', 'u.branch_id', '=', 'b.id')
            ->select('u.id', 'u.name', 'u.email', 'u.gender', 'u.active', 'u.branch_id', 'u.version', 'u.created_at', 'u.last_login_at', 'b.name as branch_name');
        if (! $this->can($actor, 'M01.user_admin')) {
            $query->whereIn('u.branch_id', app(PermissionAccess::class)->branchIds($actor, 'M01.user_view'));
        }

        return $query;
    }

    public function branches(User $actor): Collection
    {
        $this->authorizeDirectory($actor);
        $query = DB::table('branches')->select('id', 'name', 'active')->orderBy('name');
        if (! $this->can($actor, 'M01.user_admin')) {
            $query->whereIn('id', array_unique(array_merge(app(PermissionAccess::class)->branchIds($actor, 'M01.user_view'), app(PermissionAccess::class)->branchIds($actor, 'M01.user_update'))));
        }

        return $query->get();
    }

    public function allowedBranches(User $actor, object $target): Collection
    {
        $this->authorize($actor, 'M01.user_view', $target);
        $ids = DB::table('access_grants')->where('user_id', $target->id)->whereNotNull('branch_id')->pluck('branch_id')->merge(DB::table('user_branch_scopes')->where('user_id', $target->id)->pluck('branch_id'))->unique()->all();
        $query = DB::table('branches')->select('branches.id', 'branches.name')->whereIn('branches.id', $ids);
        if (! $this->can($actor, 'M01.user_admin')) {
            $query->where('branches.id', $target->branch_id);
        }

        return $query->orderBy('name')->get();
    }

    public function lockAccounts(User $actor, ?int $targetId = null): void
    {
        DB::table('users')->whereIn('id', array_filter([$actor->id, $targetId]))->orderBy('id')->lockForUpdate()->get();
    }
}
