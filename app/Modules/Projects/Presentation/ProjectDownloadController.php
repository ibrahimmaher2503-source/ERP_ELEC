<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ProjectDownloadController
{
    public function __invoke(int $project, int $revision)
    {
        $p = Project::findOrFail($project);
        app(ProjectAccess::class)->authorize(auth()->id(), $p, 'document_view');
        $r = DB::table('project_document_revisions')->whereIn('document_id', DB::table('project_documents')->where('project_id', $project)->select('id'))->where('id', $revision)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($r->path), 404);
        abort_unless(hash_equals($r->sha256, hash_file('sha256', Storage::disk('local')->path($r->path))), 409, 'فشل التحقق من سلامة المستند.');

        return Storage::disk('local')->download($r->path, $r->original_name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
