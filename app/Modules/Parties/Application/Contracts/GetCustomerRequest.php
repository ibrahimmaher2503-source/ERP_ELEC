<?php

namespace App\Modules\Parties\Application\Contracts;

final readonly class GetCustomerRequest
{
    public function __construct(public int $actorId, public int $customerId, public bool $requireActive = true) {}
}
