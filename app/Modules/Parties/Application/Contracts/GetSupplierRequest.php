<?php

namespace App\Modules\Parties\Application\Contracts;

final readonly class GetSupplierRequest
{
    public function __construct(public int $actorId, public int $supplierId, public bool $requireActive = true) {}
}
