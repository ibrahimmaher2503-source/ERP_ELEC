<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class ProjectReportsPage extends Component
{
    public string $status = '';

    public string $search = '';

    public function render()
    {
        $a = app(ProjectAccess::class);
        $ids = array_column($a->branches(auth()->id(), 'report_view'), 'id');
        $q = $a->query(auth()->id())->where(fn ($q) => $q->whereIn('branch_id', $ids)->orWhere('manager_id', auth()->id()))->when($this->status !== '', fn ($q) => $q->where('status', $this->status))->when($this->search !== '', fn ($q) => $q->where('name', 'ilike', '%'.mb_substr($this->search, 0, 200).'%'));
        $rows = $q->orderBy('id')->limit(500)->get();
        $data = [];
        foreach ($rows as $p) {
            $progress = $a->progress($p);
            $budget = $a->allows(auth()->id(), $p, 'budget_view') ? DB::table('project_baselines')->where('project_id', $p->id)->orderByDesc('revision')->first() : null;
            $data[] = ['project' => $p, 'progress' => $progress['percent'], 'budget' => $budget];
        }

        return view('projects.reports', ['data' => $data])->layout('layouts::app', ['title' => 'تقارير المشروعات']);
    }
}
