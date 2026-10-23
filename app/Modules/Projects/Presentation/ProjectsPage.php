<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use Livewire\Component;
use Livewire\WithPagination;

final class ProjectsPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $a = app(ProjectAccess::class);
        $q = $a->query(auth()->id());
        $stats = (clone $q)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
        $q->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.mb_substr($this->search, 0, 200).'%')->orWhere('code', 'ilike', '%'.mb_substr($this->search, 0, 200).'%')))->when($this->status !== '', fn ($q) => $q->where('status', $this->status));

        return view('projects.index', ['rows' => $q->orderByDesc('id')->paginate(15), 'stats' => $stats, 'access' => $a, 'canCreate' => $a->branches(auth()->id(), 'project_create') !== []])->layout('layouts::app', ['title' => 'المشروعات']);
    }
}
