<?php

namespace App\Modules\Sales\Application\Contracts;

interface SalesDeliverySources
{
    /** A staged, currently authorized Sales instruction, never a generic stock grant. */
    public function authorizeIssue(int $actor, string $order, string $line, string $key, int $warehouse, string $quantity): array;

    /** Locked confirmed delivery and frozen commercial evidence for M12 billing. */
    public function customerInvoiceSource(int $actor, array $deliveryLineIds): array;
}
