<?php

namespace App\Modules\Parties\Presentation;

use App\Modules\Parties\Application\SupplierAccess;
use App\Modules\Parties\Domain\SupplierDocument;
use Illuminate\Support\Facades\Storage;

class SupplierDocumentController
{
    public function __invoke(int $supplier, int $document)
    {
        $a = app(SupplierAccess::class);
        $a->authorize(auth()->id(), 'M03.supplier_view');
        $a->authorize(auth()->id(), 'M03.supplier_document_view');
        $r = SupplierDocument::where('supplier_id', $supplier)->findOrFail($document);
        abort_unless(Storage::disk('local')->exists($r->path), 404);

        return Storage::disk('local')->download($r->path, $r->name, ['Content-Type' => $r->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
