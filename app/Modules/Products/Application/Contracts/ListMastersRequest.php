<?php

namespace App\Modules\Products\Application\Contracts;

final readonly class ListMastersRequest
{
    public function __construct(public int $actorId, public string $entityType = 'products', public string $search = '', public bool $activeOnly = true, public int $page = 1, public int $pageSize = 25) {}
}
