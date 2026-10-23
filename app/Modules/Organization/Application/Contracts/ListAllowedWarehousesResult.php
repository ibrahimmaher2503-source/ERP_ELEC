<?php

namespace App\Modules\Organization\Application\Contracts;

final readonly class ListAllowedWarehousesResult
{
    /** @param list<int> $warehouseIds */
    public function __construct(public array $warehouseIds, public int $page, public int $pageSize, public int $total) {}
}
