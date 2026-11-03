<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\ProjectDocuments;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

final class ProjectDocumentsPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $projectId;

    #[Locked]
    public string $creationKey;

    public $upload;

    public array $form = ['number' => '', 'title' => '', 'classification' => '', 'task_id' => '', 'required' => false, 'notes' => ''];

    public string $reason = '';

    public function mount(int $project): void
    {
        $this->projectId = $project;
        $this->creationKey = (string) Str::uuid();
        app(ProjectAccess::class)->authorize(auth()->id(), Project::findOrFail($project), 'document_view');
    }

    public function saveDocument(): void
    {
        $this->validate(['upload' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240']);
        app(ProjectDocuments::class)->upload(auth()->id(), $this->projectId, $this->creationKey, $this->form, $this->upload);
        $this->upload->delete();
        $this->upload = null;
        $this->creationKey = (string) Str::uuid();
    }

    public function decide(int $revision, int $version, string $state): void
    {
        app(ProjectDocuments::class)->decide(auth()->id(), $this->projectId, $revision, $version, $state, $this->reason);
    }

    public function render()
    {
        $p = Project::findOrFail($this->projectId);
        $a = app(ProjectAccess::class);
        $a->authorize(auth()->id(), $p, 'document_view');

        return view('projects.documents', ['tasks' => DB::table('project_tasks')->where('project_id', $p->id)->orderBy('id')->get(['id', 'title']), 'project' => $p, 'canManage' => ! in_array($p->status, ['closed', 'cancelled'], true) && $a->allows(auth()->id(), $p, 'document_manage'), 'canApprove' => ! in_array($p->status, ['closed', 'cancelled'], true) && $a->allows(auth()->id(), $p, 'document_approve'), 'rows' => DB::table('project_document_revisions as r')->join('project_documents as d', 'd.id', '=', 'r.document_id')->where('d.project_id', $p->id)->orderByDesc('r.id')->get(['r.*', 'd.number', 'd.title', 'd.classification', 'd.required', 'd.task_id'])])->layout('layouts::app', ['title' => 'مستندات المشروع']);
    }
}
