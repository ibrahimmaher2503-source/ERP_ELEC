<?php

namespace App\Modules\Products\Domain;

final readonly class ProductChanged
{
    public function __construct(public int $actorId, public string $type, public int $id, public string $action, public ?array $before, public array $after) {}
}
