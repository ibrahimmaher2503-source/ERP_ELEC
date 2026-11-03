<?php

namespace App\Modules\WorkshopRepairs\Presentation;

use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\WorkshopRepairs\Application\WorkshopAccess;
use Illuminate\Support\Facades\DB;

final class RepairDocumentController
{
    public function __invoke(int $job, string $type, int $record)
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize(auth()->id(), $w->branch_id, 'document_view');
        if ($type === 'quote') {
            $d = DB::table('workshop_quotes')->where('job_id', $job)->find($record);
        } else {
            $d = DB::table('workshop_delivery_documents as p')->join('workshop_deliveries as d', 'd.id', '=', 'p.delivery_id')->where('d.job_id', $job)->where('d.id', $record)->first(['p.document_id']);
        }
        abort_unless($d, 404);
        $pdf = app(OfficialDocuments::class)->retrieve($d->document_id);

        return response($pdf['bytes'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$pdf['number'].'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
