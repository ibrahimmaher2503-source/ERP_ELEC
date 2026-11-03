<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\QuotationAccess;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationAttachment;
use Illuminate\Support\Facades\Storage;

final class QuotationDocumentController
{
    public function __invoke(int $quotation, int $document)
    {
        app(QuotationAccess::class)->authorizeRecord(auth()->id(), $quotation);
        app(QuotationAccess::class)->authorize(auth()->id());
        app(QuotationAccess::class)->authorize(auth()->id(), 'document_view');
        Quotation::findOrFail($quotation);
        $d = QuotationAttachment::where('quotation_id', $quotation)->findOrFail($document);
        abort_unless(Storage::disk('local')->exists($d->path), 404);

        return Storage::disk('local')->download($d->path, $d->name, ['Content-Type' => $d->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
