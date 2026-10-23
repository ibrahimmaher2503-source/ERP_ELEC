<?php

namespace App\Modules\Products\Application\Contracts;

final readonly class GetProductResult
{
    public function __construct(public array $product) {}
}
