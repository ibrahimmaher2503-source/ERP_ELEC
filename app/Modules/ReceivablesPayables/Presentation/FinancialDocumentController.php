<?php

namespace App\Modules\ReceivablesPayables\Presentation;

use App\Modules\ReceivablesPayables\Application\ReceivablesPayablesAccess;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;

final class FinancialDocumentController
{
    public function __invoke(string $type, int $record)
    {
        [$table, $permission] = match ($type) {
            'supplier-invoice' => ['rp_supplier_invoices', 'supplier_invoice_view'],
            'customer-invoice' => ['rp_customer_invoices', 'customer_invoice_view'],
            'credit-note' => ['rp_credit_notes', 'credit_note_manage'],
            default => abort(404),
        };
        $row = DB::table($table)->find($record);
        abort_unless($row && $row->official_document_id, 404);
        app(ReceivablesPayablesAccess::class)->authorize(auth()->id(), $permission, (int) $row->branch_id);

        return $this->response(app(OfficialDocuments::class)->retrieve($row->official_document_id));
    }

    private function response(array $pdf)
    {
        return response($pdf['bytes'])->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$pdf['number'].'.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
