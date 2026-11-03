<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\ProjectMaterials;
use App\Modules\Projects\Application\ProjectSources;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public array $reservation = ['requirement_id' => '', 'warehouse_id' => '', 'quantity' => ''];

    public array $issueQuantities = [];

    public array $availability = [];

    #[Locked]
    public string $operationKey;

    public function mount(int $project): void
    {
        $this->projectId = $project;
        $this->operationKey = (string) Str::uuid();
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

    public function checkAvailability(): void
    {
        $this->validate(['reservation.requirement_id' => 'required|integer|min:1', 'reservation.warehouse_id' => 'required|integer|min:1']);
        $p = Project::findOrFail($this->projectId);
        app(ProjectAccess::class)->authorize(auth()->id(), $p, 'material_manage');
        $r = DB::table('project_requirements')->where('project_id', $p->id)->where('id', (int) $this->reservation['requirement_id'])->firstOrFail();
        $warehouse = app(InventoryApi::class)->warehouse((int) $this->reservation['warehouse_id']);
        abort_unless($warehouse && (int) $warehouse['branch_id'] === (int) $p->branch_id, 422);
        $this->availability = app(InventoryApi::class)->getAvailability(auth()->id(), (int) $warehouse['id'], (int) $r->product_id);
    }

    public function reserve(): void
    {
        $this->validate(['reservation.requirement_id' => 'required|integer|min:1', 'reservation.warehouse_id' => 'required|integer|min:1', 'reservation.quantity' => 'required|string']);
        app(ProjectMaterials::class)->reserve(auth()->id(), $this->projectId, (int) $this->reservation['requirement_id'], (int) $this->reservation['warehouse_id'], $this->reservation['quantity'], $this->operationKey);
        $this->operationKey = (string) Str::uuid();
    }

    public function issue(int $reservation): void
    {
        app(ProjectMaterials::class)->issue(auth()->id(), $this->projectId, $reservation, (string) ($this->issueQuantities[$reservation] ?? ''), $this->operationKey);
        $this->operationKey = (string) Str::uuid();
    }

    public function release(int $reservation): void
    {
        app(ProjectMaterials::class)->release(auth()->id(), $this->projectId, $reservation, $this->operationKey);
        $this->operationKey = (string) Str::uuid();
    }

    public function replenish(int $reservation): void
    {
        app(ProjectMaterials::class)->replenish(auth()->id(), $this->projectId, $reservation, (string) ($this->issueQuantities[$reservation] ?? ''), $this->operationKey);
        $this->operationKey = (string) Str::uuid();
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

        $reservations = [];
        try {
            $reservations = app(ProjectMaterials::class)->reservations(auth()->id(), $p->id);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }
        }
        $open = ! in_array($p->status, ['closed', 'cancelled', 'completed'], true);
        foreach ($reservations as &$reservation) {
            $reservation['can_issue'] = $open && $a->materialIssueAllowed(auth()->id(), $p, (int) $reservation['warehouse_id']);
        }
        unset($reservation);

        return view('projects.operations', ['project' => $p, 'canManage' => $open && $a->allows(auth()->id(), $p, 'project_manage'), 'canReserve' => $open && $a->allows(auth()->id(), $p, 'material_manage'), 'canIssue' => $open && $a->allows(auth()->id(), $p, 'material_issue'), 'warehouses' => app(InventoryApi::class)->reservationWarehouses(auth()->id(), $p->branch_id), 'reservations' => $reservations, 'canLink' => $open && $a->allows(auth()->id(), $p, 'project_update'), 'links' => $links, 'materials' => DB::table('project_requirements')->where('project_id', $p->id)->get()])->layout('layouts::app', ['title' => 'ارتباطات ومواد المشروع']);
    }
}
