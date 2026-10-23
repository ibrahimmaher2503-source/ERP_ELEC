<?php

namespace App\Modules\Products\Domain;

use Illuminate\Support\Collection;

interface ProductHistoryReader
{
    /** Caller must authorize current product-view permission. */
    public function forProduct(int $productId): Collection;
}
