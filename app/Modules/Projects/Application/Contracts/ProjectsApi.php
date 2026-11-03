<?php

namespace App\Modules\Projects\Application\Contracts;

interface ProjectsApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array;

    public function contractTenderAward(int $actor, int $tender, int $result, array $mapping): array;

    /** Current winning submission for an authorized, source-linked draft handoff. Shared transaction required. */
    public function tenderAward(int $actor, int $tender, int $result, array $mapping): array;

    public function maintenancePlanning(int $actor, int $project, int $branch, int $customer): array;

    public function maintenanceProjects(int $actor, int $branch, int $customer): array;

    public function maintenanceProject(int $actor, int $project, int $branch, int $customer): void;

    public function inventoryDemand(int $actor, string $project, string $requirement, string $permission = 'material_manage'): array;

    public function authorizeMaterialIssue(int $actor, string $project, string $requirement, string $key, int $warehouse, string $quantity): array;

    public function releaseAllowed(int $actor, string $project, string $requirement): bool;

    /** Current explicit source link, never inferred historical attribution. */
    public function sourceProject(int $actor, string $type, int $source, int $branch, ?int $customer = null): ?int;

    public function authorizeFinancialSource(int $actor, int $project, int $branch): void;

    public function authorizeFinancialView(int $actor, int $project, int $branch): void;
}
