<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\ProjectFinancials;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ProjectReportsPage extends Component
{
    public string $status = '';

    public string $search = '';

    public function render()
    {
        $a = app(ProjectAccess::class);
        $ids = array_column($a->branches(auth()->id(), 'report_view'), 'id');
        $q = $a->query(auth()->id())->whereIn('branch_id', $ids)->when($this->status !== '', fn ($q) => $q->where('status', $this->status))->when($this->search !== '', fn ($q) => $q->where('name', 'ilike', '%'.mb_substr($this->search, 0, 200).'%'));
        $rows = $q->orderBy('id')->limit(500)->get();
        $progressByProject = $a->progressMany($rows);
        $data = [];
        foreach ($rows as $p) {
            $progress = $progressByProject[$p->id];
            $budget = $a->allows(auth()->id(), $p, 'budget_view') ? DB::table('project_baselines')->where('project_id', $p->id)->orderByDesc('revision')->first() : null;
            $financial = null;
            if ($a->allows(auth()->id(), $p, 'financial_view')) {
                try {
                    $financial = app(ProjectFinancials::class)->summary(auth()->id(), $p->id);
                } catch (HttpExceptionInterface $e) {
                    if ($e->getStatusCode() !== 403) {
                        throw $e;
                    }
                }
            }
            $data[] = ['project' => $p, 'progress' => $progress['percent'], 'budget' => $budget, 'financial' => $financial];
        }

        return view('projects.reports', ['data' => $data])->layout('layouts::app', ['title' => 'تقارير المشروعات']);
    }
}
