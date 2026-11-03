<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\ProjectExecution;
use App\Modules\Projects\Application\ProjectFinancials;
use App\Modules\Projects\Application\ProjectPlanning;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ProjectClosurePage extends Component
{
    #[Locked]
    public int $projectId;

    #[Locked]
    public int $version;

    public string $evidence = '';

    public string $reason = '';

    public function mount(int $project): void
    {
        $this->projectId = $project;
        $p = Project::findOrFail($project);
        app(ProjectAccess::class)->authorize(auth()->id(), $p);
        $this->version = $p->version;
    }

    public function handover(): void
    {
        app(ProjectExecution::class)->handover(auth()->id(), $this->projectId, $this->version, $this->evidence);
        $this->version = Project::findOrFail($this->projectId)->version;
    }

    public function close(): void
    {
        app(ProjectPlanning::class)->transition(auth()->id(), $this->projectId, $this->version, 'closed', $this->reason);
        $this->version = Project::findOrFail($this->projectId)->version;
    }

    public function render()
    {
        $p = Project::findOrFail($this->projectId);
        $a = app(ProjectAccess::class);
        $a->authorize(auth()->id(), $p);
        $checks = app(ProjectExecution::class)->completionChecks($p);
        $summary = $a->allows(auth()->id(), $p, 'financial_view') ? app(ProjectFinancials::class)->summary(auth()->id(), $p->id) : null;

        return view('projects.closure', ['project' => $p, 'checks' => $checks, 'summary' => $summary, 'canClose' => $a->allows(auth()->id(), $p, 'project_close'), 'handovers' => DB::table('project_handovers')->where('project_id', $p->id)->orderByDesc('id')->get()])->layout('layouts::app', ['title' => 'التسليم وإغلاق المشروع']);
    }
}
