<?php

namespace App\Modules\Organization\Application\Contracts;

interface OrganizationApi
{
    /** Authorized identity including inactive employment, for retained operational history. */
    public function employeeIdentity(int $actor, int $employee): array;

    public function employees(int $actor, int $branch, string $search = '', bool $activeOnly = true): array;

    /** Scoped employment identity at the requested business date. No salary, rate or login authority. */
    public function employee(int $actor, int $employee, int $branch, string $date): array;

    /** Complete company set, including inactive branches; close and every branch's report authority required. */
    public function financialClosingBranches(int $actor): array;

    /** Internal projection for an already authorized source document. No permission grant. */
    public function documentCompany(int $actor): array;

    /** Active account and explicit branch scope; does not grant an operation. */
    public function projectScopedBranches(int $actor): array;

    public function projectBranchMember(int $actor, int $branch): bool;

    /** Safe names only, for an authorized project assignment selector. */
    public function projectAssignees(int $actor, int $branch): array;

    /** Names only for retained participants in an authorized branch, including inactive accounts. */
    public function projectParticipantNames(int $actor, int $branch, array $users): array;

    public function inventoryScopes(int $actor): array;

    public function authorizeInventoryLookup(int $actor): void;

    public function authorize(AuthorizeRequest $request): AuthorizeResult;

    public function getBranch(GetBranchRequest $request): GetBranchResult;

    /** @return list<array{id:int,code:string,name:string,address:?string,active:bool}> */
    public function listBranchesForPermission(AuthorizeRequest $request): array;

    /** Fixed approved roles for a security administrator configuring responsibility. */
    public function securityRoles(int $actorId): array;

    public function hasRole(int $actorId, int $roleId): bool;

    public function listAllowedWarehouses(ListAllowedWarehousesRequest $request): ListAllowedWarehousesResult;
}
