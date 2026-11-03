<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Application\Contracts\QuotationsApi;
use App\Modules\Sales\Domain\Quotation;

final class QuotationsService implements QuotationsApi
{
    public function createTenderDraft(int $actor, int $tender, int $result, array $mapping): int
    {
        return app(TenderQuotationDraft::class)->create($actor, $tender, $result, $mapping);
    }

    public function getQuotation(int $actorId, int $quotationId): array
    {
        app(QuotationAccess::class)->authorize($actorId);
        app(QuotationAccess::class)->authorizeRecord($actorId, $quotationId);
        $r = Quotation::with('lines')->findOrFail($quotationId);

        return ['id' => $r->id, 'draftKey' => $r->draft_key, 'number' => $r->number, 'revision' => $r->revision, 'version' => $r->version, 'status' => $r->status, 'conversionStatus' => 'not_converted', 'customer' => $r->customer_snapshot, 'date' => $r->quotation_date->toDateString(), 'validUntil' => $r->valid_until?->toDateString(), 'currency' => 'EGP', 'calculation' => $r->calculation, 'lines' => $r->lines->map(fn ($l) => $l->only(['id', 'product_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'suggested_price', 'requested_price', 'discount_mode', 'discount_value']))->all()];
    }
}
