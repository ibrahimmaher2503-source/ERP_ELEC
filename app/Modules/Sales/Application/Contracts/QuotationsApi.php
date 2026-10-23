<?php

namespace App\Modules\Sales\Application\Contracts;

interface QuotationsApi
{
    /** Authorized scalar snapshot; no Eloquent model escapes the owner. */
    public function getQuotation(int $actorId, int $quotationId): array;
}
