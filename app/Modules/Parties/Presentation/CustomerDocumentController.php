<?php

namespace App\Modules\Parties\Presentation;

use App\Modules\Parties\Application\CustomerAccess;
use App\Modules\Parties\Domain\CustomerDocument;
use Illuminate\Support\Facades\Storage;

class CustomerDocumentController
{
    public function __invoke(int $customer, int $document)
    {
        $a = app(CustomerAccess::class);
        $a->authorize(auth()->id(), 'M03.customer_view');
        $a->authorize(auth()->id(), 'M03.document_view');
        $r = CustomerDocument::where('customer_id', $customer)->findOrFail($document);
        abort_unless(Storage::disk('local')->exists($r->path), 404);

        return Storage::disk('local')->download($r->path, $r->name, ['Content-Type' => $r->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
