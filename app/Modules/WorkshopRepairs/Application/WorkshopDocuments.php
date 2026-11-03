<?php

namespace App\Modules\WorkshopRepairs\Application;

use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class WorkshopDocuments
{
    public function upload(int $actor, int $intake, string $key, UploadedFile $file): int
    {
        Validator::make(compact('key', 'file'), ['key' => 'required|uuid', 'file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240'])->validate();
        $i = DB::table('workshop_intakes')->find($intake);
        abort_unless($i, 404);
        app(WorkshopAccess::class)->authorize($actor, $i->branch_id, 'document_manage');
        $path = $file->store('workshops/'.$intake, 'local');
        abort_unless($path, 500);
        try {
            return DB::transaction(function () use ($actor, $intake, $key, $file, $path) {
                $a = app(WorkshopActions::class);
                $a->lock();
                $i = DB::table('workshop_intakes')->lockForUpdate()->find($intake);
                app(WorkshopAccess::class)->authorize($actor, $i->branch_id, 'document_manage');
                abort_if(in_array($i->status, ['ready', 'delivered', 'returned_unrepaired']), 409);
                $id = DB::table('workshop_documents')->insertGetId(['intake_id' => $intake, 'creation_key' => $key, 'path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), 'mime' => $file->getMimeType(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'uploaded_by' => $actor]);
                $a->audit($actor, $intake, null, 'document.uploaded', ['document_id' => $id]);

                return $id;
            }, 5);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function download(int $actor, int $document)
    {
        $d = DB::table('workshop_documents')->find($document);
        abort_unless($d, 404);
        $i = DB::table('workshop_intakes')->find($d->intake_id);
        app(WorkshopAccess::class)->authorize($actor, $i->branch_id, 'document_view');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($d->path) && hash_equals($d->sha256, hash_file('sha256', $disk->path($d->path))), 409, 'تعذر التحقق من سلامة المستند.');

        return response()->download($disk->path($d->path), $d->original_name, ['Content-Type' => $d->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }

    public function report(int $actor, int $job)
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize($actor, $w->branch_id, 'document_view');
        $v = DB::table('workshop_verifications')->where('job_id', $job)->first();
        abort_unless($v, 404);
        $d = app(OfficialDocuments::class)->retrieve($v->document_id);

        return response($d['bytes'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$d['number'].'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
