<?php

namespace App\Modules\Treasury\Presentation;

use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\Treasury\Application\TreasuryAccess;
use Illuminate\Support\Facades\DB;

final class TreasuryDocumentController
{
    public function __invoke(int $movement)
    {
        $row = DB::table('treasury_movements')->find($movement);
        abort_unless($row && $row->official_document_id, 404);
        $permission = match ($row->kind) {
            'supplier_payment' => 'payment_execute', 'customer_collection' => 'collection_execute',
            'customer_advance_refund' => 'advance_refund', default => 'transfer_execute',
            'customer_credit_refund', 'supplier_credit_refund' => 'credit_refund',
            'cash_correction_in', 'cash_correction_out' => 'cash_correct',
        };
        app(TreasuryAccess::class)->authorize(auth()->id(), $permission, (int) $row->branch_id);
        $pdf = app(OfficialDocuments::class)->retrieve($row->official_document_id);

        return response($pdf['bytes'])->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$pdf['number'].'.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
