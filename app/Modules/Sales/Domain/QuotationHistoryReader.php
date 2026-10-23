<?php

namespace App\Modules\Sales\Domain;

interface QuotationHistoryReader
{
    public function read(int $actorId, int $quotationId): array;
}
