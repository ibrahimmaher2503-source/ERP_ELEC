<?php

namespace App\Modules\FieldMaintenance\Presentation;

use App\Modules\FieldMaintenance\Application\MaintenanceAccess;
use App\Modules\FieldMaintenance\Application\MaintenanceDocuments;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;

final class MaintenanceDownloadController
{
    public function document(int $document)
    {
        return app(MaintenanceDocuments::class)->download(auth()->id(), $document);
    }

    public function report(int $workOrder, int $completion)
    {
        $w = DB::table('maintenance_work_orders')->find($workOrder);
        abort_unless($w, 404);
        app(MaintenanceAccess::class)->authorize(auth()->id(), $w->branch_id, 'reports_view');
        abort_unless(app(MaintenanceAccess::class)->query(auth()->id())->where('id', $w->id)->exists(), 403);
        $c = DB::table('maintenance_completions')->where('work_order_id', $workOrder)->find($completion);
        abort_unless($c, 404);
        $doc = app(OfficialDocuments::class)->retrieve($c->official_document_id);

        return response($doc['bytes'])->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.$doc['number'].'.pdf"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
