<?php

namespace App\Modules\Products\Application\Contracts;

final readonly class ConvertQuantityRequest
{
    public function __construct(public int $actorId, public int $productId, public int $unitId, public string $quantity) {}
}
