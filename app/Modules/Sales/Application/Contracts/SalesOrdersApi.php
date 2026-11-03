<?php

namespace App\Modules\Sales\Application\Contracts;

interface SalesOrdersApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array;

    public function inventoryReleaseAllowed(int $actor, string $sourceId, string $sourceLine): bool;

    public function inventoryDemand(int $actor, string $sourceId, string $sourceLine): array;

    /** Authorized readonly projection; no stock, invoice or approval effect is implied. */
    public function getOrder(int $actorId, int $orderId): array;
}
