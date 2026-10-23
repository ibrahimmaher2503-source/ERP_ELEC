<?php

namespace App\Modules\Parties\Domain;

final readonly class CustomerChanged
{
    public function __construct(public int $actorId, public int $id, public string $action, public ?array $before, public array $after) {}
}
