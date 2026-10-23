<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Inventory\Application\InventoryQuery;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class WarehousesPage extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $editing = false;

    public array $form = ['code' => '', 'name' => '', 'branch_id' => '', 'address' => '', 'notes' => '', 'active' => true];

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $version = null;

    public function boot(): void
    {
        app(InventoryAccess::class)->authorize(Auth::id(), 'warehouse_view');
    }

    public function create(): void
    {
        app(InventoryAccess::class)->authorize(Auth::id(), 'warehouse_create');
        $this->reset('form', 'editingId', 'version');
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        app(InventoryAccess::class)->authorize(Auth::id(), 'warehouse_update');
        $row = app(InventoryQuery::class)->warehouses(Auth::id())->where('id', $id)->first();
        abort_unless($row, 404);
        $this->editingId = $id;
        $this->version = $row->version;
        foreach (array_keys($this->form) as $k) {
            $this->form[$k] = $row->$k ?? '';
        } $this->editing = true;
    }

    public function save(): void
    {
        app(InventoryActions::class)->saveWarehouse(Auth::id(), $this->form, $this->editingId, $this->version);
        $this->editing = false;
        session()->flash('status', 'تم حفظ المستودع. نطاقات الوصول تُمنح بصورة مستقلة من تفاصيل المستخدم.');
    }

    public function render()
    {
        $q = app(InventoryQuery::class)->warehouses(Auth::id());
        $search = mb_substr($this->search, 0, 100);

        return view('inventory.warehouses', ['rows' => $q->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$search.'%')->orWhere('code', 'ilike', '%'.$search.'%')))->orderBy('id')->paginate(15), 'branches' => app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest(Auth::id(), 'M01.branch_view')), 'access' => app(InventoryAccess::class)])->layout('layouts::app', ['title' => 'المستودعات']);
    }
}
