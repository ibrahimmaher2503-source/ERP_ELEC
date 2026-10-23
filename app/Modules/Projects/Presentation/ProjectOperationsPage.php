<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\ProjectSources;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ProjectOperationsPage extends Component
{
    #[Locked]
    public int $projectId;

    public string $sourceType = 'sales_order';

    public string $sourceId = '';

    public array $material = ['product_id' => '', 'quantity' => '', 'notes' => ''];

    public function mount(int $project): void
    {
        $this->projectId = $project;
        app(ProjectAccess::class)->authorize(auth()->id(), Project::findOrFail($project));
    }

    public function link(): void
    {
        $this->validate(['sourceType' => 'required|in:quotation,sales_order,requisition,rfq,supplier_offer,purchase_order,purchase_receipt', 'sourceId' => 'required|integer|min:1']);
        app(ProjectSources::class)->link(auth()->id(), $this->projectId, $this->sourceType, (int) $this->sourceId);
        $this->sourceId = '';
    }

    public function addMaterial(): void
    {
        app(ProjectSources::class)->requirement(auth()->id(), $this->projectId, $this->material);
        $this->material = ['product_id' => '', 'quantity' => '', 'notes' => ''];
    }

    public function render()
    {
        $p = Project::findOrFail($this->projectId);
        $a = app(ProjectAccess::class);
        $a->authorize(auth()->id(), $p);
        $links = [];
        foreach (DB::table('project_source_links')->where('project_id', $p->id)->get() as $link) {
            try {
                $s = app(ProjectSources::class)->source(auth()->id(), $link->source_type, $link->source_id);
                if (isset($s['customer']) && (int) ($s['customer']['id'] ?? 0) !== (int) $p->customer_id) {
                    $s = null;
                }
                if (isset($s['branchId']) && (int) $s['branchId'] !== (int) $p->branch_id) {
                    $s = null;
                }
                $links[] = ['link' => $link, 'source' => $s];
            } catch (HttpExceptionInterface) {
                $links[] = ['link' => $link, 'source' => null];
            }
        }

        return view('projects.operations', ['project' => $p, 'canManage' => $a->allows(auth()->id(), $p, 'project_manage'), 'canLink' => $a->allows(auth()->id(), $p, 'project_update'), 'links' => $links, 'materials' => DB::table('project_requirements')->where('project_id', $p->id)->get()])->layout('layouts::app', ['title' => 'ارتباطات ومواد المشروع']);
    }
}
