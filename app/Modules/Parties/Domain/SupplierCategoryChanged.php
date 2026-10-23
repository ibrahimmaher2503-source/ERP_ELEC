<?php

namespace App\Modules\Parties\Domain;

final readonly class SupplierCategoryChanged
{
    public function __construct(public int $actorId, public int $id, public ?array $before, public array $after) {}
}
