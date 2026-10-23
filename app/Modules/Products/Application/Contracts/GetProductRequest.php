<?php

namespace App\Modules\Products\Application\Contracts;

final readonly class GetProductRequest
{
    public function __construct(public int $actorId, public int $productId) {}
}
