<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;

final class ProjectExportController
{
    public function __invoke()
    {
        request()->validate(['status' => 'nullable|string|max:20', 'search' => 'nullable|string|max:200']);
        $a = app(ProjectAccess::class);
        $branches = array_intersect(array_column($a->branches(auth()->id(), 'export'), 'id'), array_column($a->branches(auth()->id(), 'report_view'), 'id'));
        abort_unless($branches, 403);
        $q = $a->query(auth()->id())->whereIn('branch_id', $branches)->when(request()->filled('status'), fn ($q) => $q->where('status', request('status')))->when(request()->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.request('search').'%'));
        $columns = ['code' => 'الكود', 'name' => 'المشروع', 'customer' => 'العميل', 'status' => 'الحالة', 'start' => 'البداية', 'end' => 'النهاية المخططة', 'progress' => 'نسبة الإنجاز'];
        $path = app(WorkbookCodec::class)->write('projects', $columns, (function () use ($q, $a) {
            foreach ($q->orderBy('id')->limit(10001)->cursor() as $p) {
                yield ['code' => $p->code, 'name' => $p->name, 'customer' => $p->customer_name, 'status' => $p->status, 'start' => $p->start_date, 'end' => $p->end_date, 'progress' => $a->progress($p)['percent']];
            }
        })());

        return response()->download($path, 'projects.xlsx', ['Cache-Control' => 'private, no-store'])->setPrivate()->deleteFileAfterSend();
    }
}
