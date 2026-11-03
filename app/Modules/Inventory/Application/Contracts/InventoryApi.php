<?php

namespace App\Modules\Inventory\Application\Contracts;

interface InventoryApi
{
    /** Pure canonical serial validation; grants no product, stock or valuation access. */
    public function normalizeSerialSelections(mixed $serials, string $quantity): array;

    /** Actual service-issued serial identity only; no costs or stock mutation authority. */
    public function servicePartSerials(int $actor, string $owner, int $work, int $requirement, string $quantity, array $serials, string $purpose, ?int $movement = null): array;

    /** Real company equipment identity; no stock/value creation. */
    public function repairEquipment(int $actor, int $serial): array;

    public function repairEquipmentOptions(int $actor, int $branch, string $search = '', string $selected = ''): array;

    public function repairEquipmentFromReceipt(int $actor, int $movement): array;

    public function repairIsolation(int $actor, int $isolation): array;

    public function workshopReservationsReconciled(int $actor, int $work, int $branch, array $reservations): bool;

    public function workshopIssueEvidence(int $actor, string $issueKey): array;

    public function returnWorkshopIssue(int $actor, int $movement, string $quantity, string $key, string $reason, array $serials = []): array;

    /** Owner-only boolean used by authorized M08 technical closure. No stock or cost projection. */
    public function maintenanceReservationsReconciled(int $actor, int $work, int $branch, array $reservations): bool;

    public function maintenanceIssueEvidence(int $actor, string $issueKey): array;

    public function returnMaintenanceIssue(int $actor, int $movement, string $quantity, string $key, string $reason, array $serials = []): array;

    public function projectSummary(int $actor, int $project, int $branch, array $salesOrders = []): array;

    public function postOpening(int $actor, int $batch, string $date, array $lines): void;

    public function receivePurchase(int $actor, int $receipt): array;

    public function costedMovement(int $actor, int $movement): array;

    /** Authorized base-unit quantities; no valuation or commercial approval. */
    public function getAvailability(int $actor, int $warehouse, int $product): array;

    public function reconcileWarehouse(int $actor, int $warehouse): array;

    public function isolateStock(int $actor, array $data): array;

    public function releaseIsolation(int $actor, int $isolation, string $quantity, string $key, array $serials = []): array;

    public function receiveTransfer(int $actor, int $document, array $data): array;

    public function transferStatus(int $actor, int $document): array;

    public function reverseMovement(int $actor, array $data): array;

    /** Internal owner lookup; contains no quantities or business authority. */
    public function document(int $actor, int $id): array;

    public function warehouse(int $id): ?array;

    public function warehouseDirectory(int $actor): array;

    public function requestReservation(int $actor, array $data): array;

    public function reservationStatus(int $actor, int $id): array;

    public function replenishReservation(int $actor, int $id, string $quantity, string $key): array;

    public function reservationWarehouses(int $actor, int $branch): array;

    public function adjustReservation(int $actor, int $id, string $quantity, string $key): array;

    public function releaseReservation(int $actor, int $id, string $key): array;

    public function fulfillReservation(int $actor, int $id, string $quantity, string $key): array;

    /** Replay immutable FIFO layer originals and allocated cents at inclusive business-date cutoff. */
    public function financialValue(int $actor, array $branchIds, ?string $to = null): string;

    public function transitValue(int $actor, array $branchIds, ?string $to = null): string;
}
