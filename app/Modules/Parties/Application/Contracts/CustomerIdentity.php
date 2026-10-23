<?php

namespace App\Modules\Parties\Application\Contracts;

final readonly class CustomerIdentity
{
    public function __construct(public int $id, public string $code, public string $name, public string $entityType, public string $status) {}
}
