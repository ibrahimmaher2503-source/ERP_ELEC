<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\AuthorizeResult;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\ErrorCode;
use App\Modules\Organization\Application\Contracts\GetBranchRequest;
use App\Modules\Organization\Application\Contracts\GetBranchResult;
use App\Modules\Organization\Application\Contracts\ListAllowedWarehousesRequest;
use App\Modules\Organization\Application\Contracts\ListAllowedWarehousesResult;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;

final class OrganizationService implements OrganizationApi
{
    private const BRANCH_FIELDS = ['id', 'company_id', 'code', 'name', 'active', 'address'];

    public function projectScopedBranches(int $actor): array
    {
        $this->actor($actor);

        return DB::table('user_branch_scopes as s')->join('branches as b', 'b.id', '=', 's.branch_id')->where('s.user_id', $actor)->where('b.active', true)->pluck('b.id')->all();
    }

    public function projectBranchMember(int $actor, int $branch): bool
    {
        $this->actor($actor);

        return DB::table('user_branch_scopes')->where('user_id', $actor)->where('branch_id', $branch)->join('branches', 'branches.id', '=', 'user_branch_scopes.branch_id')->where('branches.active', true)->exists();
    }

    public function projectAssignees(int $actor, int $branch): array
    {
        if (! $this->projectBranchMember($actor, $branch)) {
            throw new ContractException(ErrorCode::Forbidden, 'Branch scope required.');
        }

        return DB::table('users as u')->join('user_branch_scopes as s', 's.user_id', '=', 'u.id')->where('s.branch_id', $branch)->where('u.active', true)->whereNotNull('u.email_verified_at')->orderBy('u.name')->get(['u.id', 'u.name'])->map(fn ($u) => (array) $u)->all();
    }

    public function inventoryScopes(int $actor): array
    {
        $this->actor($actor);
        $catalog = array_keys(array_filter(PermissionCatalog::DEFINITIONS, fn ($d) => $d[1] === 'warehouse'));
        $admin = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $actor)->where('r.code', 'Administrator')->exists();
        $codes = $admin ? $catalog : DB::table('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->where('ur.user_id', $actor)->whereIn('rp.permission_code', $catalog)->pluck('rp.permission_code')->all();
        $direct = DB::table('access_grants')->where('user_id', $actor)->whereIn('permission_code', $catalog)->get();
        $scopes = DB::table('user_warehouse_scopes as w')->join('user_branch_scopes as b', fn ($join) => $join->on('b.user_id', '=', 'w.user_id')->on('b.branch_id', '=', 'w.branch_id'))->join('branches as br', 'br.id', '=', 'w.branch_id')->where('w.user_id', $actor)->get(['w.warehouse_id', 'w.branch_id', 'br.active', 'br.name as branch_name']);

        return $scopes->map(function ($s) use ($codes, $direct) {
            $permissions = array_values(array_unique(array_merge($codes, $direct->where('branch_id', $s->branch_id)->pluck('permission_code')->all())));
            if (! $s->active) {
                $permissions = array_values(array_filter($permissions, fn ($p) => str_ends_with($p, '_view') || $p === 'M06.export'));
            }

            return ['warehouse_id' => $s->warehouse_id, 'branch_id' => $s->branch_id, 'branch_name' => $s->branch_name, 'permissions' => $permissions];
        })->all();
    }

    public function authorizeInventoryLookup(int $actor): void
    {
        foreach ($this->inventoryScopes($actor) as $scope) {
            if ($scope['permissions'] !== []) {
                return;
            }
        }
        throw new ContractException(ErrorCode::Forbidden, 'Inventory lookup requires effective warehouse authority.');
    }

    public function authorize(AuthorizeRequest $request): AuthorizeResult
    {
        $actor = $this->actor($request->actorId);
        if (! app(PermissionAccess::class)->allows($actor, $request->permissionCode, $request->recordBranchId, $request->warehouseId)) {
            throw new ContractException(ErrorCode::Forbidden, 'Operation or scope is not authorized.');
        }
        if ($request->warehouseId !== null && ! str_ends_with($request->permissionCode, '_view') && $request->permissionCode !== 'M06.export'
            && ! DB::table('branches')->where('id', $request->recordBranchId)->where('active', true)->exists()) {
            throw new ContractException(ErrorCode::Forbidden, 'Inactive branch cannot accept warehouse operations.');
        }
        $fields = match ($request->permissionCode) {
            'M01.branch_view' => self::BRANCH_FIELDS,
            'M01.company_view' => ['id', 'code', 'name', 'legal_name', 'tax_registration', 'address', 'currency'],
            'M01.user_view' => ['id', 'name', 'email', 'active', 'branch_id', 'gender', 'created_at', 'last_login_at'],
            default => [],
        };
        $this->fields($request->requestedFields, $fields);

        return new AuthorizeResult($request->requestedFields, $request->recordBranchId === null ? [] : [$request->recordBranchId], $request->warehouseId === null ? [] : [$request->warehouseId]);
    }

    public function getBranch(GetBranchRequest $request): GetBranchResult
    {
        $actor = $this->actor($request->actorId);
        $this->query($request->scopeBranchId, $request->warehouseIds, $request->filters, $request->page, $request->pageSize);
        if ($request->branchId < 1) {
            throw new ContractException(ErrorCode::ValidationFailed, 'Invalid branch identifier.');
        }
        $fields = $request->requestedFields ?: self::BRANCH_FIELDS;
        $this->fields($fields, self::BRANCH_FIELDS);
        if (($request->scopeBranchId !== null && $request->scopeBranchId !== $request->branchId) || $request->warehouseIds !== [] || ! app(OrganizationAccess::class)->branchAllows($actor, 'M01.branch_view', $request->branchId)) {
            throw new ContractException(ErrorCode::NotFound, 'Branch is unavailable.');
        }
        $branch = DB::table('branches')->where('id', $request->branchId)->where('company_id', 1)->first($fields);
        if (! $branch) {
            throw new ContractException(ErrorCode::NotFound, 'Branch is unavailable.');
        }

        return new GetBranchResult((array) $branch);
    }

    public function listAllowedWarehouses(ListAllowedWarehousesRequest $request): ListAllowedWarehousesResult
    {
        $this->actor($request->actorId);
        $this->query($request->scopeBranchId, $request->warehouseIds, $request->filters, $request->page, $request->pageSize);
        $ids = DB::table('user_warehouse_scopes')->where('user_id', $request->actorId);
        if ($request->scopeBranchId !== null) {
            $ids->where('branch_id', $request->scopeBranchId);
        }
        if ($request->warehouseIds !== []) {
            $ids->whereIn('warehouse_id', $request->warehouseIds);
        }
        $branches = DB::table('user_branch_scopes')->where('user_id', $request->actorId)->pluck('branch_id');
        $ids->whereIn('branch_id', $branches);
        $total = (clone $ids)->count();

        return new ListAllowedWarehousesResult($ids->orderBy('warehouse_id')->forPage($request->page, $request->pageSize)->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all(), $request->page, $request->pageSize, $total);
    }

    public function listBranchesForPermission(AuthorizeRequest $request): array
    {
        $actor = $this->actor($request->actorId);
        if ((PermissionCatalog::DEFINITIONS[$request->permissionCode][1] ?? null) !== 'branch' || $request->warehouseId !== null || $request->requestedFields !== []) {
            throw new ContractException(ErrorCode::Forbidden, 'Branch-scoped permission required.');
        }
        $ids = app(PermissionAccess::class)->branchIds($actor, $request->permissionCode);
        $query = DB::table('branches')->where('company_id', 1)->whereIn('id', $ids);
        if ($request->recordBranchId !== null) {
            $query->where('id', $request->recordBranchId);
        }

        return $query->orderBy('name')->get(['id', 'code', 'name', 'address', 'active'])->map(fn ($r) => (array) $r)->all();
    }

    public function securityRoles(int $actorId): array
    {
        $this->authorize(new AuthorizeRequest($actorId, 'M01.security_admin'));

        return DB::table('roles')->whereIn('code', array_keys(PermissionCatalog::ROLES))->orderBy('id')->get(['id', 'code', 'name'])->map(fn ($r) => (array) $r)->all();
    }

    public function hasRole(int $actorId, int $roleId): bool
    {
        $this->actor($actorId);

        return DB::table('user_roles')->where('user_id', $actorId)->where('role_id', $roleId)->exists();
    }

    private function actor(int $id): User
    {
        $actor = $id > 0 ? User::whereKey($id)->where('active', true)->whereNotNull('email_verified_at')->first() : null;
        if (! $actor) {
            throw new ContractException(ErrorCode::Forbidden, 'Active verified identity required.');
        }

        return $actor;
    }

    private function fields(array $requested, array $allowed): void
    {
        if (! array_is_list($requested) || count($requested) > 100 || array_filter($requested, fn ($field) => ! is_string($field)) || array_diff($requested, $allowed)) {
            throw new ContractException(ErrorCode::Forbidden, 'Requested fields are unavailable.');
        }
    }

    private function query(?int $scopeBranchId, array $warehouseIds, array $filters, int $page, int $pageSize): void
    {
        if (($scopeBranchId !== null && $scopeBranchId < 1) || $page < 1 || $pageSize < 1 || $pageSize > 100 || ! array_is_list($warehouseIds) || count($warehouseIds) > 100 || array_filter($warehouseIds, fn ($id) => ! is_int($id) || $id < 1) || $filters !== []) {
            throw new ContractException(ErrorCode::ValidationFailed, 'Invalid or unsupported query context.');
        }
    }
}
