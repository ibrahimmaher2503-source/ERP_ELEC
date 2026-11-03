<?php

namespace App\Modules\Products\Application\Contracts;

interface ApprovedPrices
{
    public function find(int $actor, int $product, int $unit, string $date): ?array;
}
