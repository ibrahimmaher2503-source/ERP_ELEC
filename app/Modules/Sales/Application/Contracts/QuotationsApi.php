<?php

namespace App\Modules\Sales\Application\Contracts;

interface QuotationsApi
{
    /** Creates an unapproved draft through normal M04 pricing; preserves the winning source separately. */
    public function createTenderDraft(int $actor, int $tender, int $result, array $mapping): int;

    /** Authorized scalar snapshot; no Eloquent model escapes the owner. */
    public function getQuotation(int $actorId, int $quotationId): array;
}
