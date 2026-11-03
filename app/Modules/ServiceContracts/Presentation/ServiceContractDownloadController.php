<?php

namespace App\Modules\ServiceContracts\Presentation;

use App\Modules\ServiceContracts\Application\ServiceContractAccess;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;

final class ServiceContractDownloadController
{
    public function evidence(int $document)
    {
        return app(ServiceContractDocuments::class)->download(auth()->id(), $document);
    }

    public function official(int $revision)
    {
        $r = DB::table('service_contract_revisions')->find($revision);
        abort_unless($r, 404);
        $c = DB::table('service_contracts')->find($r->contract_id);
        app(ServiceContractAccess::class)->authorize(auth()->id(), (int) $c->branch_id, 'document_view');
        $reference = DB::table('service_contract_official_documents')->where('revision_id', $revision)->first();
        abort_unless($reference, 404);
        $doc = app(OfficialDocuments::class)->retrieve($reference->document_id);

        return response($doc['bytes'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$doc['number'].'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
