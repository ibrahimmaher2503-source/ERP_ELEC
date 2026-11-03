<?php

namespace App\Modules\System\Application\Contracts;

interface OfficialDocuments
{
    /** Caller authorizes its owner action; allocation commits with that same transaction. */
    public function nextOperationalReference(string $type, string $date): string;

    public function nextMaintenanceReference(string $type, string $date): string;

    /** Owner must authorize and freeze the source inside the same transaction. */
    public function issue(int $actor, string $type, string $source, int $revision, string $date, array $content): array;

    /** Owner must authorize access before retrieving these private bytes. */
    public function retrieve(string $id): array;
}
