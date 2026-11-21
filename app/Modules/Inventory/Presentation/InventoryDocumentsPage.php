<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Inventory\Application\InventoryQuery;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class InventoryDocumentsPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $kind = 'transfer';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $warehouse = '';

    #[Url(except: '')]
    public string $direction = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(string $kind = 'transfer'): void
    {
        abort_unless(isset(InventoryDocuments::KINDS[$kind]), 404);
        $this->kind = $kind;
    }

    public function updated($property): void
    {
        if ($property === 'warehouse' && $this->warehouse === '') {
            $this->direction = '';
        }
        if (in_array($property, ['search', 'warehouse', 'direction', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $actor = Auth::id();
        $base = app(InventoryQuery::class)->documents($actor, $this->kind);
        $ids = array_column(app(OrganizationApi::class)->inventoryScopes($actor), 'warehouse_id');

        $search = trim(mb_substr($this->search, 0, 100));
        if ($search !== '') {
            $base->where(fn ($q) => $q->where('reason', 'ilike', '%'.$search.'%')
                ->when(ctype_digit($search), fn ($q) => $q->orWhere('id', (int) $search)));
        }
        if (ctype_digit($this->warehouse)) {
            $warehouse = (int) $this->warehouse;
            if ($this->kind === 'transfer' && $this->direction === 'out') {
                $base->where('warehouse_id', $warehouse);
            } elseif ($this->kind === 'transfer' && $this->direction === 'in') {
                $base->where('destination_id', $warehouse);
            } else {
                $base->where(fn ($q) => $q->where('warehouse_id', $warehouse)->orWhere('destination_id', $warehouse));
            }
        }

        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $query = clone $base;
        if (isset(InventoryDisplay::DOCUMENT_STATUS[$this->status])) {
            $query->where('status', $this->status);
        }

        $page = $query->orderByDesc('id')->paginate(15);
        $lineStats = DB::table('inventory_document_lines')
            ->whereIn('document_id', $page->pluck('id'))
            ->selectRaw('document_id, count(*) as items, count(distinct unit_name) as units, min(unit_name) as unit_name, sum(quantity) as total')
            ->groupBy('document_id')->get()->keyBy('document_id');

        return view('inventory.documents', [
            'lineStats' => $lineStats, 'counts' => $counts, 'title' => InventoryDocuments::KINDS[$this->kind], 'rows' => $page,
            'canExport' => app(InventoryAccess::class)->ids($actor, 'export') !== [],
            'warehouses' => DB::table('inventory_warehouses')->whereIn('id', $ids)->orderBy('name')->get(),
        ])->layout('layouts::app', ['title' => InventoryDocuments::KINDS[$this->kind]]);
    }
}
