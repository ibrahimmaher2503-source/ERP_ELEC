<?php

namespace App\Modules\Purchasing\Application;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class OfferFiles
{
    public function attach(int $actor, int $offer, UploadedFile $file): void
    {
        $actions = app(SupplierOffers::class);
        $doc = $actions->record($actor, $offer, 'offer_file');
        abort_unless($doc->created_by === $actor && $doc->status === 'draft', 403, 'أرفق الأصول قبل تثبيت العرض.');
        Validator::make(['file' => $file], ['file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240'])->validate();
        $path = $file->store('purchasing/offers/'.$offer, 'local');
        try {
            DB::transaction(function () use ($actions, $actor, $offer, $file, $path) {
                $actions->lock();
                $doc = $actions->record($actor, $offer, 'offer_file');
                abort_unless($doc->created_by === $actor && $doc->status === 'draft', 403);
                $id = DB::table('purchasing_offer_files')->insertGetId(['offer_id' => $offer, 'path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), 'mime' => $file->getMimeType(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'size' => $file->getSize(), 'created_by' => $actor, 'created_at' => now()]);
                $actions->audit($actor, 'supplier_offer', $offer, 'offer.original_attached', null, ['file_id' => $id]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
