<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Parties\Application\Contracts\CustomerContractException;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\ProjectActions;
use App\Modules\Projects\Application\ProjectPlanning;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ProjectPage extends Component
{
    #[Locked]
    public ?int $projectId = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey;

    public array $form = ['code' => '', 'name' => '', 'branch_id' => '', 'customer_id' => '', 'manager_id' => '', 'start_date' => '', 'end_date' => '', 'description' => '', 'notes' => '', 'members' => []];

    public array $phase = ['title' => '', 'start_date' => '', 'end_date' => ''];

    public array $task = ['phase_id' => '', 'title' => '', 'assigned_to' => '', 'start_date' => '', 'end_date' => '', 'notes' => ''];

    public string $customerSearch = '';

    #[Locked]
    public string $screen = 'details';

    public function mount(?int $project = null, string $screen = 'details'): void
    {
        abort_unless(in_array($screen, ['details', 'tasks']), 404);
        $this->screen = $screen;
        $this->creationKey = (string) Str::uuid();
        if ($project) {
            $this->projectId = $project;
            $this->reload();
        } else {
            $this->form['start_date'] = now()->toDateString();
            $this->form['end_date'] = now()->addMonth()->toDateString();
            abort_unless(app(ProjectAccess::class)->branches(auth()->id(), 'project_create'), 403);
        }
    }

    private function reload(): void
    {
        $p = Project::findOrFail($this->projectId);
        app(ProjectAccess::class)->authorize(auth()->id(), $p);
        $this->version = $p->version;
        foreach (array_keys($this->form) as $key) {
            if ($key !== 'members') {
                $this->form[$key] = $p->$key;
            }
        }$this->form['members'] = DB::table('project_members')->where('project_id', $p->id)->pluck('user_id')->all();
    }

    public function save(): void
    {
        $p = app(ProjectActions::class)->save(auth()->id(), $this->projectId, $this->version, $this->creationKey, $this->form);
        $this->projectId = $p->id;
        $this->version = $p->version;
        $this->redirectRoute('projects.show', $p->id, navigate: true);
    }

    public function saveTeam(): void
    {
        abort_unless($this->projectId, 404);
        app(ProjectActions::class)->team(auth()->id(), $this->projectId, $this->version, $this->form['members']);
        $this->reload();
    }

    public function addPhase(): void
    {
        abort_unless($this->projectId, 404);
        app(ProjectActions::class)->phase(auth()->id(), $this->projectId, $this->version, $this->phase);
        $this->reload();
        $this->phase = ['title' => '', 'start_date' => '', 'end_date' => ''];
    }

    #[Locked]
    public ?int $editingTask = null;

    public function editTask(int $task): void
    {
        $p = Project::findOrFail($this->projectId);
        app(ProjectAccess::class)->authorize(auth()->id(), $p, 'project_manage');
        $row = DB::table('project_tasks')->where('project_id', $p->id)->where('id', $task)->firstOrFail();
        $this->editingTask = $row->id;
        foreach (array_keys($this->task) as $key) {
            $this->task[$key] = $row->$key;
        }
        $this->version = $p->version;
    }

    public function addTask(): void
    {
        abort_unless($this->projectId, 404);
        app(ProjectActions::class)->task(auth()->id(), $this->projectId, $this->version, $this->task, $this->editingTask);
        $this->editingTask = null;
        $this->reload();
        $this->task = ['phase_id' => '', 'title' => '', 'assigned_to' => '', 'start_date' => '', 'end_date' => '', 'notes' => ''];
    }

    public function taskStatus(int $task, int $version, string $status): void
    {
        abort_unless($this->projectId, 404);
        app(ProjectActions::class)->taskStatus(auth()->id(), $this->projectId, $task, $version, $status);
        $this->reload();
    }

    public string $lifecycleReason = '';

    public function changeLifecycle(string $state): void
    {
        abort_unless($this->projectId, 404);
        app(ProjectPlanning::class)->transition(auth()->id(), $this->projectId, $this->version, $state, $this->lifecycleReason);
        $this->reload();
    }

    public function render()
    {
        $a = app(ProjectAccess::class);
        $p = $this->projectId ? Project::findOrFail($this->projectId) : null;
        if ($p) {
            $a->authorize(auth()->id(), $p);
        }$branches = $a->branches(auth()->id(), 'project_create');
        $users = [];
        if ($this->form['branch_id'] && ctype_digit((string) $this->form['branch_id'])) {
            $users = app(ProjectAccess::class)->assignees(auth()->id(), (int) $this->form['branch_id']);
        }$customers = [];
        try {
            $customers = app(CustomersApi::class)->searchCustomers(new SearchCustomersRequest(auth()->id(), mb_substr($this->customerSearch, 0, 150), 50));
        } catch (CustomerContractException) {
        }

        return view('projects.show', ['project' => $p, 'branches' => $branches, 'users' => $users, 'customers' => $customers, 'progress' => $p ? $a->progress($p) : null, 'tasks' => $p ? DB::table('project_tasks')->where('project_id', $p->id)->orderBy('id')->get() : collect(), 'activities' => $p ? DB::table('project_activities')->where('project_id', $p->id)->orderByDesc('id')->limit(30)->get() : collect(), 'canManage' => $p && $a->allows(auth()->id(), $p, 'project_manage'), 'canBudget' => $p && $a->allows(auth()->id(), $p, 'budget_view'), 'canUpdateTeam' => $p && $a->allows(auth()->id(), $p, 'project_update'), 'hasBaseline' => $p && DB::table('project_baselines')->where('project_id', $p->id)->exists()])->layout('layouts::app', ['title' => $p?->name ?? 'مشروع جديد']);
    }
}
