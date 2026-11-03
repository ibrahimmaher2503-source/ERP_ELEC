<?php

namespace App\Modules\Projects\Application;

use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class TenderDocuments
{
    public const FINANCIAL = ['financial', 'submission', 'award', 'guarantee'];

    public function upload(int $actor, int $tender, int $version, string $key, string $title, string $classification, UploadedFile $file): int
    {
        Validator::make(compact('key', 'title', 'classification', 'file'), ['key' => 'required|uuid', 'title' => 'required|string|max:200', 'classification' => 'required|in:general,technical,financial,submission,award,guarantee,addendum', 'file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240'])->validate();
        $t = DB::table('project_tenders')->find($tender);
        abort_unless($t, 404);
        app(TenderAccess::class)->authorize($actor, $t->branch_id, 'manage');
        if (in_array($classification, self::FINANCIAL, true)) {
            app(TenderAccess::class)->authorize($actor, $t->branch_id, 'financial_view');
        }
        $path = $file->store('tenders/'.$tender, 'local');
        abort_unless($path, 500);
        try {
            return DB::transaction(function () use ($actor, $tender, $version, $key, $title, $classification, $file, $path) {
                $a = app(TenderActions::class);
                $t = $a->work($actor, $tender, $version);
                $hash = hash_file('sha256', Storage::disk('local')->path($path));
                if ($old = DB::table('project_tender_documents')->where('creation_key', $key)->first()) {
                    abort_unless($old->tender_id === $tender && $old->uploaded_by === $actor && $old->title === $title && $old->classification === $classification && hash_equals($old->sha256, $hash), 409);
                    Storage::disk('local')->delete($path);

                    return $old->id;
                }
                $id = DB::table('project_tender_documents')->insertGetId(['tender_id' => $tender, 'creation_key' => $key, 'title' => $title, 'classification' => $classification, 'path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $hash, 'uploaded_by' => $actor]);
                $a->touch($actor, $t, 'document.uploaded', ['document' => $id, 'classification' => $classification, 'sha256' => $hash]);

                return $id;
            }, 5);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function evidence(int $tender, int $document): object
    {
        $d = DB::table('project_tender_documents')->where('tender_id', $tender)->find($document);
        abort_unless($d, 422, 'مستند المصدر لا يتبع المناقصة.');
        abort_unless(Storage::disk('local')->exists($d->path) && hash_equals($d->sha256, hash_file('sha256', Storage::disk('local')->path($d->path))), 409, 'ملف الدليل مفقود أو تغير.');

        return $d;
    }

    public function download(int $actor, int $id)
    {
        $d = DB::table('project_tender_documents')->find($id);
        abort_unless($d, 404);
        $t = DB::table('project_tenders')->find($d->tender_id);
        $a = app(TenderAccess::class);
        $a->authorize($actor, $t->branch_id);
        $a->authorize($actor, $t->branch_id, 'document_view');
        if (in_array($d->classification, self::FINANCIAL, true)) {
            $a->authorize($actor, $t->branch_id, 'financial_view');
        }
        $this->evidence($t->id, $id);

        return response()->download(Storage::disk('local')->path($d->path), $d->original_name, ['Content-Type' => $d->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }

    public function bid(int $actor, int $id, int $financial)
    {
        $t = DB::table('project_tenders')->find($id);
        abort_unless($t, 404);
        $a = app(TenderAccess::class);
        $a->authorize($actor, $t->branch_id);
        $a->authorize($actor, $t->branch_id, 'document_view');
        $a->authorize($actor, $t->branch_id, 'financial_view');
        abort_unless(DB::table('project_tender_financial')->where('tender_id', $id)->where('id', $financial)->exists(), 404);
        $d = DB::table('project_tender_bid_documents')->where('financial_id', $financial)->first();
        abort_unless($d, 404);
        $pdf = app(OfficialDocuments::class)->retrieve($d->document_id);

        return response($pdf['bytes'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$pdf['number'].'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
