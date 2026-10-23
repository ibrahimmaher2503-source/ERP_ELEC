<?php

namespace App\Modules\Purchasing\Domain;

final readonly class PurchasingChanged
{
    public function __construct(public int $actorId, public int $id, public string $action, public ?array $before, public array $after) {}
}
