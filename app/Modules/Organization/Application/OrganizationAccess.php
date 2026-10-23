<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OrganizationAccess
{
    public function companyIdentity(User $actor): ?object
    {
        if (! $this->allows($actor, 'M01.company_view')) {
            return null;
        }

        return DB::table('companies')->select('name', 'code')->where('id', 1)->first();
    }

    public function allows(User $actor, string $permission, ?int $branchId = null): bool
    {
        return app(PermissionAccess::class)->allows($actor, $permission, $branchId);
    }

    public function authorize(User $actor, string $permission, ?int $branchId = null): void
    {
        abort_unless($this->allows($actor, $permission, $branchId), 403, 'ليس لديك صلاحية لهذا الإجراء.');
    }

    public function branchAllows(User $actor, string $permission, int $branchId): bool
    {
        return $this->allows($actor, 'M01.branch_admin') || $this->allows($actor, $permission, $branchId);
    }

    public function authorizeBranch(User $actor, string $permission, int $branchId): void
    {
        abort_unless($this->branchAllows($actor, $permission, $branchId), 403, 'ليس لديك صلاحية على هذا الفرع.');
    }

    public function branches(User $actor): Builder
    {
        $query = DB::table('branches');
        if (! $this->allows($actor, 'M01.branch_admin')) {
            $query->whereIn('id', app(PermissionAccess::class)->branchIds($actor, 'M01.branch_view'));
        }
        abort_unless(DB::table('users')->where('id', $actor->id)->where('active', true)->exists(), 403);

        return $query;
    }

    public function authorizeDirectory(User $actor): void
    {
        abort_unless($this->allows($actor, 'M01.branch_admin') || $this->allows($actor, 'M01.branch_create') ||
            app(PermissionAccess::class)->branchIds($actor, 'M01.branch_view') !== [], 403);
    }
}
