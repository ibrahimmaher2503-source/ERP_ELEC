<?php

namespace App\Modules\WorkshopRepairs\Application\Contracts;

interface WorkshopApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array;

    public function contractWork(int $actor, int $job): array;

    public function fieldRepairs(int $actor, int $work, int $branch, int $customer): array;

    public function assertLaborAvailable(int $technician, string $start, string $end): void;

    public function inventoryDemand(int $actor, string $job, string $line, string $permission = 'material_manage'): array;

    public function validateUnusedPartReturn(int $actor, int $work, int $requirement, int $movement, string $quantity, string $key, array $serials): void;

    public function authorizeIssue(int $actor, string $job, string $line, string $key, int $warehouse, string $quantity): array;

    public function releaseAllowed(int $actor, string $job, string $line): bool;

    public function authorizeReconciliation(int $actor, int $job, int $branch): void;

    public function billingSource(int $actor, int $job): array;

    public function validateBillingEvidence(int $actor, int $job, int $branch, int $customer, int $approval): void;

    public function projectJobs(int $actor, int $project, int $branch): array;
}
