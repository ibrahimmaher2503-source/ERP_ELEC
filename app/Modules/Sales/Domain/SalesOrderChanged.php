<?php

namespace App\Modules\Sales\Domain;

final readonly class SalesOrderChanged
{
    public function __construct(public ?int $actorId, public int $id, public string $action, public ?array $before, public array $after) {}
}
