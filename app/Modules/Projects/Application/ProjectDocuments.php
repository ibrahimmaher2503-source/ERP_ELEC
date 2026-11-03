<?php

namespace App\Modules\Projects\Application;

use App\Modules\Projects\Domain\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProjectDocuments
{
    public function upload(int $actor, int $id, string $key, array $input, UploadedFile $file): void
    {
        $p = Project::findOrFail($id);
        app(ProjectAccess::class)->authorize($actor, $p, 'document_manage');
        $d = Validator::make($input, ['number' => 'required|string|max:80', 'title' => 'required|string|max:200', 'classification' => 'required|string|max:100', 'required' => 'boolean', 'notes' => 'nullable|string|max:2000'])->validate();
        $task = Validator::make($input, ['task_id' => 'nullable|integer|min:1'])->validate()['task_id'] ?? null;
        $task = $task ? (int) $task : null;
        abort_unless($task === null || DB::table('project_tasks')->where('project_id', $id)->where('id', $task)->exists(), 422, 'المهمة لا تتبع المشروع.');
        Validator::make(['file' => $file, 'key' => $key], ['file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240', 'key' => 'required|uuid'])->validate();
        $path = $file->store('projects/'.$id, 'local');
        if (! $path) {
            throw new \RuntimeException('Unable to store document.');
        }
        try {
            DB::transaction(function () use ($actor, $id, $key, $d, $file, $path, $task) {
                DB::select('select pg_advisory_xact_lock(10401)');
                DB::select('select pg_advisory_xact_lock(70701)');
                $p = Project::lockForUpdate()->findOrFail($id);
                app(ProjectAccess::class)->authorize($actor, $p, 'document_manage');
                if (in_array($p->status, ['closed', 'cancelled'])) {
                    $this->fail('المشروع لا يسمح برفع مستندات.');
                }if (DB::table('project_document_revisions')->where('creation_key', $key)->exists()) {
                    $this->fail('هذا الرفع محفوظ بالفعل.');
                }$doc = DB::table('project_documents')->where('project_id', $id)->where('number', $d['number'])->first();
                if ($doc && ($doc->title !== $d['title'] || $doc->classification !== $d['classification'] || (bool) $doc->required !== (bool) ($d['required'] ?? false) || ($doc->task_id === null ? null : (int) $doc->task_id) !== $task)) {
                    $this->fail('هوية المستند ثابتة بين المراجعات.');
                }$docId = $doc?->id ?? DB::table('project_documents')->insertGetId(['project_id' => $id, 'task_id' => $task, 'number' => $d['number'], 'title' => $d['title'], 'classification' => $d['classification'], 'required' => $d['required'] ?? false]);
                if (DB::table('project_document_revisions')->where('document_id', $docId)->whereIn('status', ['draft', 'review'])->exists()) {
                    $this->fail('يوجد إصدار غير محسوم لهذا المستند.');
                }$revision = (int) DB::table('project_document_revisions')->where('document_id', $docId)->max('revision') + 1;
                $revId = DB::table('project_document_revisions')->insertGetId(['document_id' => $docId, 'creation_key' => $key, 'revision' => $revision, 'path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), 'mime' => $file->getMimeType(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'size' => $file->getSize(), 'uploaded_by' => $actor, 'uploaded_at' => now(), 'notes' => $d['notes'] ?? '']);
                app(ProjectActions::class)->activity($actor, $p, 'document.uploaded', null, ['document_id' => $docId, 'revision' => $revision, 'revision_id' => $revId]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function decide(int $actor, int $id, int $revision, int $version, string $state, string $reason): void
    {
        $reason = trim($reason);
        Validator::make(['state' => $state, 'reason' => $reason], ['state' => 'required|in:review,approved,rejected', 'reason' => 'required|string|min:3|max:2000'])->validate();
        DB::transaction(function () use ($actor, $id, $revision, $version, $state, $reason) {
            DB::select('select pg_advisory_xact_lock(10401)');
            DB::select('select pg_advisory_xact_lock(70701)');
            $p = Project::lockForUpdate()->findOrFail($id);
            app(ProjectAccess::class)->authorize($actor, $p, 'project_view');
            if (in_array($p->status, ['closed', 'cancelled'])) {
                $this->fail('المشروع مقفل.');
            }$docIds = DB::table('project_documents')->where('project_id', $id)->select('id');
            $r = DB::table('project_document_revisions')->whereIn('document_id', $docIds)->where('id', $revision)->lockForUpdate()->firstOrFail();
            if ($r->version != $version) {
                $this->fail('المراجعة تغيرت.');
            }if ($state === 'review') {
                app(ProjectAccess::class)->authorize($actor, $p, 'document_manage');
                abort_unless($r->uploaded_by == $actor || $p->manager_id == $actor, 403);
                if ($r->status !== 'draft') {
                    $this->fail('الإرسال للمراجعة من المسودة فقط.');
                }
            } else {
                app(ProjectAccess::class)->authorize($actor, $p, 'document_approve');
                abort_unless((int) $r->uploaded_by !== $actor, 403, 'اعتماد المستند يتطلب شخصاً مستقلاً.');
                abort_unless(Storage::disk('local')->exists($r->path) && hash_equals($r->sha256, hash_file('sha256', Storage::disk('local')->path($r->path))), 409, 'فشل التحقق من سلامة مستند الاعتماد.');
                if ($r->status !== 'review') {
                    $this->fail('المستند ليس قيد المراجعة.');
                }
            }DB::table('project_document_revisions')->where('id', $revision)->update(['status' => $state, 'version' => $version + 1, 'decided_by' => $state === 'review' ? null : $actor, 'decided_at' => $state === 'review' ? null : now(), 'decision_reason' => $reason]);
            app(ProjectActions::class)->activity($actor, $p, 'document.'.$state, ['status' => $r->status], ['revision_id' => $revision, 'status' => $state, 'reason' => $reason]);
        });
    }

    private function fail(string $m): never
    {
        throw ValidationException::withMessages(['form' => $m]);
    }
}
