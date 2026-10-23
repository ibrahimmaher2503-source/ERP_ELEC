<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use Livewire\Component;

final class ProjectsDashboardPage extends Component
{
    public string $status = '';

    public function render()
    {
        $a = app(ProjectAccess::class);
        $q = $a->query(auth()->id());
        $rows = (clone $q)->when($this->status !== '', fn ($q) => $q->where('status', $this->status))->orderBy('end_date')->limit(100)->get();
        $stats = (clone $q)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
        $progress = [];
        foreach ($rows as $p) {
            $progress[$p->id] = $a->progress($p)['percent'];
        }

        return view('projects.dashboard', ['rows' => $rows, 'stats' => $stats, 'progress' => $progress, 'canCreate' => $a->branches(auth()->id(), 'project_create') !== []])->layout('layouts::app', ['title' => 'لوحة المشروعات']);
    }
}
