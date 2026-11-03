<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\ProjectPlanning;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ProjectBudgetPage extends Component
{
    #[Locked]
    public int $projectId;

    #[Locked]
    public int $version;

    #[Locked]
    public string $creationKey;

    public array $form = ['currency' => 'EGP', 'total' => '', 'scope' => '', 'categories' => [['name' => '', 'amount' => '']], 'phases' => []];

    public string $reason = '';

    public string $decisionReason = '';

    public function mount(int $project): void
    {
        $this->projectId = $project;
        $this->creationKey = (string) Str::uuid();
        $p = Project::findOrFail($project);
        app(ProjectAccess::class)->authorize(auth()->id(), $p, 'budget_view');
        $this->version = $p->version;
        $baseline = DB::table('project_baselines')->where('project_id', $project)->orderByDesc('revision')->first();
        $this->form = $baseline ? json_decode($baseline->snapshot, true, 512, JSON_THROW_ON_ERROR) : array_replace($this->form, ['scope' => $p->description]);
        $this->form['structure'] = DB::table('project_phases')->where('project_id', $project)->orderBy('id')->get(['id', 'title', 'start_date', 'end_date', 'cancelled'])->map(fn ($row) => (array) $row)->all();
        $this->form['start_date'] ??= $p->start_date;
        $this->form['end_date'] ??= $p->end_date;
    }

    public function addPhaseProposal(): void
    {
        if (count($this->form['structure'] ?? []) < 100) {
            $this->form['structure'][] = ['id' => null, 'title' => '', 'start_date' => '', 'end_date' => '', 'cancelled' => false];
        }
    }

    public function togglePhaseAllocations(): void
    {
        $p = Project::findOrFail($this->projectId);
        app(ProjectAccess::class)->authorize(auth()->id(), $p, 'budget_manage');
        $this->form['phases'] = ($this->form['phases'] ?? []) ? [] : DB::table('project_phases')->where('project_id', $p->id)->where('cancelled', false)->get(['id'])->map(fn ($phase) => ['id' => $phase->id, 'amount' => ''])->all();
    }

    public function addCategory(): void
    {
        if (count($this->form['categories']) < 30) {
            $this->form['categories'][] = ['name' => '', 'amount' => ''];
        }
    }

    public function propose(): void
    {
        app(ProjectPlanning::class)->propose(auth()->id(), $this->projectId, $this->version, $this->creationKey, $this->reason, $this->form);
        $this->version = Project::findOrFail($this->projectId)->version;
        $this->creationKey = (string) Str::uuid();
        $this->reason = '';
    }

    public function decide(int $change, bool $approve): void
    {
        app(ProjectPlanning::class)->decide(auth()->id(), $this->projectId, $this->version, $change, $approve, $this->decisionReason);
        $this->version = Project::findOrFail($this->projectId)->version;
    }

    public function render()
    {
        $p = Project::findOrFail($this->projectId);
        $a = app(ProjectAccess::class);
        $a->authorize(auth()->id(), $p, 'budget_view');

        return view('projects.budget', ['project' => $p, 'canManage' => in_array($p->status, ['draft', 'planned', 'active', 'in_progress'], true) && $a->allows(auth()->id(), $p, 'budget_manage'), 'canApprove' => $a->allows(auth()->id(), $p, 'budget_approve'), 'baselines' => DB::table('project_baselines')->where('project_id', $p->id)->orderByDesc('revision')->get(), 'changes' => DB::table('project_changes')->where('project_id', $p->id)->orderByDesc('id')->get()])->layout('layouts::app', ['title' => 'ميزانية المشروع']);
    }
}
