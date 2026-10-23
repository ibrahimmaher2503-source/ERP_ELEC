<?php

namespace App\Modules\Organization\Application\Contracts;

final readonly class AuthorizeResult
{
    /** @param list<string> $allowedFields @param list<int> $branchIds @param list<int> $warehouseIds */
    public function __construct(public array $allowedFields, public array $branchIds, public array $warehouseIds) {}
}
