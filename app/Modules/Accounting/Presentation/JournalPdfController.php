<?php

namespace App\Modules\Accounting\Presentation;

use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;

final class JournalPdfController
{
    public function __invoke(int $journal)
    {
        $row = DB::table('accounting_journals')->find($journal);
        abort_unless($row && $row->status === 'posted', 404);
        app(AccountingAccess::class)->authorize(auth()->id(), 'journal_view', $row->branch_id);
        $pdf = app(OfficialDocuments::class)->retrieve($row->official_document_id);

        return response($pdf['bytes'])->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.$pdf['number'].'.pdf"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
