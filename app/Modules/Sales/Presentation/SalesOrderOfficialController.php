<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\SalesOrderAccess;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;

final class SalesOrderOfficialController
{
    public function __invoke(int $order, ?int $instruction = null)
    {
        $record = SalesOrder::findOrFail($order);
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'view', $record->branch_id);
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'export', $record->branch_id);
        $source = $instruction === null ? DB::table('sales_order_issues')->where('order_id', $order)->first()
            : DB::table('sales_delivery_instructions')->where('order_id', $order)->where('status', 'confirmed')->find($instruction);
        abort_unless($source && $source->document_id, 404);
        $document = app(OfficialDocuments::class)->retrieve($source->document_id);

        return response($document['bytes'])->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$document['number'].'.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
