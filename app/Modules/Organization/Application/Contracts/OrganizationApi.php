<?php

namespace App\Modules\Organization\Application\Contracts;

interface OrganizationApi
{
    /** Active account and explicit branch scope; does not grant an operation. */
    public function projectScopedBranches(int $actor): array;

    public function projectBranchMember(int $actor, int $branch): bool;

    /** Safe names only, for an authorized project assignment selector. */
    public function projectAssignees(int $actor, int $branch): array;

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
