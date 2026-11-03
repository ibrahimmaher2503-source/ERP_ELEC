<?php

namespace App\Modules\FieldMaintenance\Application\Contracts;

interface FieldMaintenanceApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array;

    public function createContractPreventiveDraft(int $actor, int $contract, string $key, array $data): int;

    public function contractWork(int $actor, int $work): array;

    public function assertLaborAvailable(int $technician, string $start, string $end): void;

    public function workshopTransfer(int $actor, int $work, int $visit, int $branch, int $customer): array;

    public function validateBillingEvidence(int $actor, int $work, int $branch, int $customer, int $approval): void;

    public function authorizeReconciliation(int $actor, int $work, int $branch): void;

    public function projectWorkOrders(int $actor, int $project, int $branch): array;

    public function inventoryDemand(int $actor, string $work, string $line, string $permission = 'material_manage'): array;

    public function validateUnusedPartReturn(int $actor, int $work, int $requirement, int $movement, string $quantity, string $key, array $serials): void;

    public function authorizeIssue(int $actor, string $work, string $line, string $key, int $warehouse, string $quantity): array;

    public function releaseAllowed(int $actor, string $work, string $line): bool;

    public function billingSource(int $actor, int $work): array;
}
