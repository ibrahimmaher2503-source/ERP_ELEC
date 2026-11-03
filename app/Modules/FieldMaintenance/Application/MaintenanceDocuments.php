<?php

namespace App\Modules\FieldMaintenance\Application;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class MaintenanceDocuments
{
    public function upload(int $actor, ?int $request, ?int $work, ?int $visit, string $key, UploadedFile $file): int
    {
        Validator::make(compact('key', 'file'), ['key' => 'required|uuid', 'file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240'], [], __('maintenance.fields', [], 'ar'))->validate();
        abort_unless(($request !== null) !== ($work !== null), 422);
        $r = DB::table($request ? 'maintenance_requests' : 'maintenance_work_orders')->find($request ?? $work);
        abort_unless($r, 404);
        app(MaintenanceAccess::class)->authorize($actor, $r->branch_id, 'document_manage');
        $path = $file->store('maintenance/'.($request ? 'requests/'.$request : 'work-orders/'.$work), 'local');
        abort_unless($path, 500);
        try {
            return DB::transaction(function () use ($actor, $request, $work, $visit, $key, $file, $path) {
                $a = app(MaintenanceActions::class);
                $a->lock();
                $r = DB::table($request ? 'maintenance_requests' : 'maintenance_work_orders')->lockForUpdate()->find($request ?? $work);
                app(MaintenanceAccess::class)->authorize($actor, $r->branch_id, 'document_manage');
                abort_if(in_array($r->status, ['closed', 'cancelled', 'verified', 'work_completed', 'rejected']), 409, 'الملف تابع لسجل غير قابل للتعديل.');
                if ($visit) {
                    $v = DB::table('maintenance_visits')->where('work_order_id', $work)->find($visit);
                    abort_unless($v && $v->status === 'planned', 409, 'أرفق أدلة الزيارة قبل تثبيت تقريرها.');
                }
                $id = DB::table('maintenance_documents')->insertGetId(['creation_key' => $key, 'request_id' => $request, 'work_order_id' => $work, 'visit_id' => $visit, 'path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), 'mime' => $file->getMimeType(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'uploaded_by' => $actor]);
                $a->audit($actor, $request ? 'maintenance_request' : 'maintenance_work_order', $r->id, 'document.uploaded', null, ['document_id' => $id, 'visit_id' => $visit]);

                return $id;
            }, 5);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function download(int $actor, int $document)
    {
        $d = DB::table('maintenance_documents')->find($document);
        abort_unless($d, 404);
        $r = DB::table($d->request_id ? 'maintenance_requests' : 'maintenance_work_orders')->find($d->request_id ?? $d->work_order_id);
        app(MaintenanceAccess::class)->authorize($actor, $r->branch_id, 'document_view');
        abort_unless(app(MaintenanceAccess::class)->query($actor, $d->request_id ? 'maintenance_requests' : 'maintenance_work_orders')->where('id', $r->id)->exists(), 403);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($d->path) && hash_equals($d->sha256, hash_file('sha256', $disk->path($d->path))), 409, 'تعذر التحقق من سلامة الملف.');

        return response()->download($disk->path($d->path), $d->original_name, ['Content-Type' => $d->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
