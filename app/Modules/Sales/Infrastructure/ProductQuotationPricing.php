<?php

namespace App\Modules\Sales\Infrastructure;

use App\Modules\Products\Application\Contracts\ApprovedPrices;
use App\Modules\Sales\Application\Contracts\QuotationPricing;

final class ProductQuotationPricing implements QuotationPricing
{
    public function suggested(int $actorId, int $productId, int $unitId, string $date): ?array
    {
        return app(ApprovedPrices::class)->find($actorId, $productId, $unitId, $date);
    }
}
