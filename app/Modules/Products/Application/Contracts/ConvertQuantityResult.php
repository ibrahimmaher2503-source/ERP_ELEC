<?php

namespace App\Modules\Products\Application\Contracts;

final readonly class ConvertQuantityResult
{
    public function __construct(public string $baseQuantity, public int $baseUnitId) {}
}
