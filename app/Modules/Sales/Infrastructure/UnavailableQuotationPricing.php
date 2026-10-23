<?php

namespace App\Modules\Sales\Infrastructure;

use App\Modules\Sales\Application\Contracts\QuotationPricing;

final class UnavailableQuotationPricing implements QuotationPricing
{
    public function suggested(int $actorId, int $productId, int $unitId, string $date): ?array
    {
        return null;
    }
}
