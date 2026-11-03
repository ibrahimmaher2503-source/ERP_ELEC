<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;

final class OrderPdfController
{
    public function __invoke(int $order)
    {
        app(PurchaseOrders::class)->record(auth()->id(), $order);
        $issue = DB::table('purchasing_order_issues')->where('order_id', $order)->first();
        abort_unless($issue, 404);
        $pdf = app(OfficialDocuments::class)->retrieve($issue->document_id);

        return response($pdf['bytes'])->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.$pdf['number'].'.pdf"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
