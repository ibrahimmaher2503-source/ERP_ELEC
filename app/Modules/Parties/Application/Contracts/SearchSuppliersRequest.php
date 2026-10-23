<?php

namespace App\Modules\Parties\Application\Contracts;

final readonly class SearchSuppliersRequest
{
    public function __construct(public int $actorId, public string $search = '', public int $limit = 20) {}
}
