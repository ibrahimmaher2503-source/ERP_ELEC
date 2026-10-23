<?php

namespace App\Modules\System\Application\DataOperations;

class EntityRegistry
{
    public function __construct(private array $definitions) {}

    public function get(string $entity): EntityDefinition
    {
        abort_unless(isset($this->definitions[$entity]), 404);

        return app($this->definitions[$entity]);
    }
}
