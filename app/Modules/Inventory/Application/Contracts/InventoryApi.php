<?php

namespace App\Modules\Inventory\Application\Contracts;

interface InventoryApi
{
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

    public function adjustReservation(int $actor, int $id, string $quantity, string $key): array;

    public function releaseReservation(int $actor, int $id, string $key): array;

    public function fulfillReservation(int $actor, int $id, string $quantity, string $key): array;
}
