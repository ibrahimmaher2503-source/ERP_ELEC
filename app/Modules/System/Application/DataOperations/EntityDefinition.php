<?php

namespace App\Modules\System\Application\DataOperations;

interface EntityDefinition
{
    public function id(): string;

    public function label(): string;

    public function version(): string;

    public function authorize(int $actorId, string $capability): void;

    public function columns(): array;

    public function lookups(int $actorId): array;

    public function bulkFields(): array;

    public function previewBulk(int $actorId, int $id, array $changes): array;

    public function previewImport(int $actorId, array $row, string $mode): array;

    public function duplicateKeys(array $row): array;

    public function execute(int $actorId, array $plan): array;

    public function exportRows(int $actorId, string $mode, array $filters, array $ids): iterable;
}
