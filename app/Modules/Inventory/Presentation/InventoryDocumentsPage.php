<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Inventory\Application\InventoryQuery;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class InventoryDocumentsPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $kind = 'transfer';

    public function mount(string $kind = 'transfer'): void
    {
        abort_unless(isset(InventoryDocuments::KINDS[$kind]), 404);
        $this->kind = $kind;
    }

    public function render()
    {
        $q = app(InventoryQuery::class)->documents(Auth::id(), $this->kind);
        $ids = array_column(app(OrganizationApi::class)->inventoryScopes(Auth::id()), 'warehouse_id');

        return view('inventory.documents', ['title' => InventoryDocuments::KINDS[$this->kind], 'rows' => $q->orderByDesc('id')->paginate(15), 'warehouses' => DB::table('inventory_warehouses')->whereIn('id', $ids)->get()])->layout('layouts::app', ['title' => InventoryDocuments::KINDS[$this->kind]]);
    }
}
