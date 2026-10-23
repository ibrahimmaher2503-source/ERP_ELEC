<?php

namespace App\Modules\Inventory\Domain;

final readonly class InventoryChanged
{
    public function __construct(public int $actorId, public string $type, public int $id, public string $action, public ?array $before, public array $after) {}
}
