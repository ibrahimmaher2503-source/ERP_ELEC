<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class ProjectsPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    public function render()
    {
        $a = app(ProjectAccess::class);
        $q = $a->query(auth()->id());
        $stats = (clone $q)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
        $today = now('Africa/Cairo')->toDateString();
        $open = (clone $q)->whereNotIn('status', ['draft', 'completed', 'closed', 'cancelled']);
        $overdue = (clone $open)->where('end_date', '<', $today)->count();
        $deadlines = (clone $open)->whereNotNull('end_date')->orderBy('end_date')->orderBy('id')->limit(4)->get();
        $q->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.mb_substr($this->search, 0, 200).'%')->orWhere('code', 'ilike', '%'.mb_substr($this->search, 0, 200).'%')))->when($this->status !== '', fn ($q) => $q->where('status', $this->status));

        $rows = $q->orderByDesc('id')->paginate(15);
        $progress = $a->progressMany($rows->getCollection());

        return view('projects.index', compact('rows', 'stats', 'progress', 'today', 'overdue', 'deadlines') + [
            'canCreate' => $a->branches(auth()->id(), 'project_create') !== [],
            'canReport' => $a->branches(auth()->id(), 'report_view') !== [],
        ])->layout('layouts::app', ['title' => 'المشروعات']);
    }
}
