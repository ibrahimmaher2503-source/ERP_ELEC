<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\QuotationAccess;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;

final class QuotationOfficialController
{
    public function __invoke(int $quotation)
    {
        app(QuotationAccess::class)->authorizeRecord(auth()->id(), $quotation);
        app(QuotationAccess::class)->authorize(auth()->id());
        app(QuotationAccess::class)->authorize(auth()->id(), 'document_view');
        Quotation::findOrFail($quotation);
        $issue = DB::table('sales_quotation_issues')->where('quotation_id', $quotation)->first();
        abort_unless($issue, 404);
        $doc = app(OfficialDocuments::class)->retrieve($issue->document_id);

        return response($doc['bytes'])->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$doc['number'].'.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function acceptance(int $quotation)
    {
        app(QuotationAccess::class)->authorizeRecord(auth()->id(), $quotation);
        app(QuotationAccess::class)->authorize(auth()->id());
        app(QuotationAccess::class)->authorize(auth()->id(), 'document_view');
        Quotation::findOrFail($quotation);
        $evidence = DB::table('sales_quotation_acceptances')->where('quotation_id', $quotation)->first();
        abort_unless($evidence, 404);
        $bytes = base64_decode($evidence->evidence_base64, true);
        abort_unless(is_string($bytes) && hash_equals($evidence->sha256, hash('sha256', $bytes)), 500, 'Acceptance evidence integrity failure.');

        return response()->streamDownload(function () use ($bytes) {
            echo $bytes;
        }, $evidence->evidence_name,
            ['Content-Type' => $evidence->evidence_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
