<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationAttachment;
use App\Modules\Sales\Domain\QuotationChanged;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class QuotationDocuments
{
    public function upload(int $actor, int $id, int $version, UploadedFile $file): QuotationAttachment
    {
        app(QuotationAccess::class)->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, 'document_manage');
        app(QuotationAccess::class)->authorizeRecord($actor, $id);
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg']])->validate();
        $path = $file->store('sales/quotations/internal', 'local');
        abort_unless($path, 422);
        try {
            return DB::transaction(function () use ($actor, $id, $version, $file, $path) {
                $r = Quotation::lockForUpdate()->findOrFail($id);
                app(QuotationActions::class)->version($r, $version);
                abort_unless($r->editable(), 422, 'يمكن الإرفاق بالمسودة فقط.');
                $name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200);
                $doc = $r->attachments()->create(['name' => $name, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $actor, 'sha256' => hash_file('sha256', Storage::disk('local')->path($path))]);
                $r->update(['version' => $r->version + 1, 'updated_by' => $actor]);
                event(new QuotationChanged($actor, $id, 'internal_document_uploaded', null, ['document_id' => $doc->id, 'sha256' => $doc->sha256]));

                return $doc;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
