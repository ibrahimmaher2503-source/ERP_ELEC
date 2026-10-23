<?php

namespace App\Modules\Sales\Application\Contracts;

interface QuotationPricing
{
    /** Null means the owning approved pricing source is unavailable. No guessed price. */
    public function suggested(int $actorId, int $productId, int $unitId, string $date): ?array;
}
