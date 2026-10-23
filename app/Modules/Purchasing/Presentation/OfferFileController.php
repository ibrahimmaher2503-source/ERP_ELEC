<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\SupplierOffers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class OfferFileController
{
    public function __invoke(int $file)
    {
        $row = DB::table('purchasing_offer_files')->find($file);
        abort_unless($row, 404);
        app(SupplierOffers::class)->record(auth()->id(), $row->offer_id);
        abort_unless(Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->download($row->path, $row->original_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
