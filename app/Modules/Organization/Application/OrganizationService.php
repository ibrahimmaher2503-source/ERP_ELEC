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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class OrganizationService implements OrganizationApi
{
    public function employeeIdentity(int $actor, int $employee): array
    {
        $row = DB::table('organization_employees')->find($employee);
        if (! $row) {
            throw new ContractException(ErrorCode::NotFound, 'Employee identity is unavailable.');
        }
        $this->authorize(new AuthorizeRequest($actor, 'M01.employee_view', (int) $row->branch_id));

        return array_intersect_key((array) $row, array_flip(['id', 'code', 'name', 'branch_id', 'user_id', 'starts_on', 'ends_on', 'active', 'version']));
    }

    public function employees(int $actor, int $branch, string $search = '', bool $activeOnly = true): array
    {
        $this->authorize(new AuthorizeRequest($actor, 'M01.employee_view', $branch));
        abort_unless(mb_strlen($search) <= 200 && ! str_contains($search, "\0"), 422);

        return DB::table('organization_employees')->where('branch_id', $branch)->when($activeOnly, fn ($q) => $q->where('active', true))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$search.'%')->orWhere('code', 'ilike', '%'.$search.'%')))
            ->orderBy('code')->limit(100)->get(['id', 'code', 'name', 'branch_id', 'starts_on', 'ends_on', 'active'])->map(fn ($r) => (array) $r)->all();
    }

    public function employee(int $actor, int $employee, int $branch, string $date): array
    {
        $this->authorize(new AuthorizeRequest($actor, 'M01.employee_view', $branch));
        Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
        $row = DB::table('organization_employees')->where('id', $employee)->where('branch_id', $branch)->first();
        if (! $row || ! $row->active || $row->starts_on > $date || ($row->ends_on !== null && $row->ends_on < $date)) {
            throw new ContractException(ErrorCode::ValidationFailed, 'Employee employment is unavailable for this branch/date.');
        }

        return array_intersect_key((array) $row, array_flip(['id', 'code', 'name', 'branch_id', 'user_id', 'starts_on', 'ends_on', 'active', 'version']));
    }

    public function financialClosingBranches(int $actor): array
    {
        PermissionAccess::forgetRequestSnapshot();
        $this->authorize(new AuthorizeRequest($actor, 'M11.period_close'));
        $branches = DB::table('branches')->where('company_id', 1)->orderBy('id')->get(['id', 'code', 'name', 'active']);
        if ($branches->isEmpty()) {
            throw new ContractException(ErrorCode::IncompleteSetup, 'Company branches are required for financial close.');
        }
        foreach ($branches as $branch) {
            $this->authorize(new AuthorizeRequest($actor, 'M11.report_view', (int) $branch->id));
        }

        return $branches->map(fn ($branch) => (array) $branch)->all();
    }

    public function documentCompany(int $actor): array
    {
        $this->actor($actor);
        $company = DB::table('companies')->where('setup_complete', true)->find(1, ['id', 'code', 'name', 'legal_name', 'tax_registration', 'address', 'currency', 'logo_path']);
        if (! $company || ! $company->legal_name || ! $company->address) {
            throw new ContractException(ErrorCode::IncompleteSetup, 'Complete the actual company legal name and address before official issue.');
        }

        $identity = (array) $company;
        unset($identity['logo_path']);
        if ($company->logo_path) {
            $disk = Storage::disk('local');
            if (! $disk->exists($company->logo_path)) {
                throw new ContractException(ErrorCode::IncompleteSetup, 'Restore the private company logo before official issue.');
            }
            $mime = $disk->mimeType($company->logo_path);
            abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 422);
            $identity['logo_data_uri'] = 'data:'.$mime.';base64,'.base64_encode($disk->get($company->logo_path));
        }

        return $identity;
    }

    private const BRANCH_FIELDS = ['id', 'company_id', 'code', 'name', 'active', 'address'];

    public function projectScopedBranches(int $actor): array
    {
        $this->actor($actor);

        return array_keys(array_filter(app(PermissionAccess::class)->scopedBranches($actor), fn ($branch) => $branch->active));
    }

    public function projectBranchMember(int $actor, int $branch): bool
    {
        $this->actor($actor);

        return (bool) (app(PermissionAccess::class)->scopedBranches($actor)[$branch]->active ?? false);
    }

    public function projectAssignees(int $actor, int $branch): array
    {
        if (! $this->projectBranchMember($actor, $branch)) {
            throw new ContractException(ErrorCode::Forbidden, 'Branch scope required.');
        }

        return DB::table('users as u')->join('user_branch_scopes as s', 's.user_id', '=', 'u.id')->where('s.branch_id', $branch)->where('u.active', true)->whereNotNull('u.email_verified_at')->orderBy('u.name')->get(['u.id', 'u.name'])->map(fn ($u) => (array) $u)->all();
    }

    public function projectParticipantNames(int $actor, int $branch, array $users): array
    {
        if (! $this->projectBranchMember($actor, $branch)) {
            throw new ContractException(ErrorCode::Forbidden, 'Branch scope required.');
        }

        return DB::table('users as u')->join('user_branch_scopes as s', 's.user_id', '=', 'u.id')
            ->where('s.branch_id', $branch)->whereIn('u.id', $users)->pluck('u.name', 'u.id')->all();
    }

    public function inventoryScopes(int $actor): array
    {
        $this->actor($actor);

        return app(PermissionAccess::class)->inventoryScopes($actor);
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
        $rows = app(PermissionAccess::class)->permissionBranches($actor->id, $request->permissionCode);

        return $request->recordBranchId === null ? $rows : array_values(array_filter($rows, fn ($row) => $row['id'] === $request->recordBranchId));
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
        $actor = app(PermissionAccess::class)->actor($id);
        if (! $actor?->active || ! $actor->email_verified_at) {
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
