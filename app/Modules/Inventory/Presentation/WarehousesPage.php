<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Inventory\Application\InventoryQuery;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class WarehousesPage extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: '')]
    public string $state = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'branch', 'state'], true)) {
            $this->resetPage();
        }
    }

    public bool $editing = false;

    public array $form = ['code' => '', 'name' => '', 'branch_id' => '', 'address' => '', 'storekeeper_name' => '', 'notes' => '', 'active' => true];

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
        $q->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$search.'%')->orWhere('code', 'ilike', '%'.$search.'%')));
        if (ctype_digit($this->branch)) {
            $q->where('branch_id', (int) $this->branch);
        }
        $counts = (clone $q)->selectRaw('CASE WHEN active THEN 1 ELSE 0 END as k, count(*) as total')->groupByRaw('CASE WHEN active THEN 1 ELSE 0 END')->pluck('total', 'k');
        if (in_array($this->state, ['active', 'inactive'], true)) {
            $q->where('active', $this->state === 'active');
        }
        $rows = $q->orderBy('id')->paginate(15);
        $access = app(InventoryAccess::class);
        $viewable = $access->ids(Auth::id(), 'balance_view');
        $movable = $access->ids(Auth::id(), 'movement_view');
        $scopedRowIds = array_values(array_intersect($rows->pluck('id')->all(), $viewable));
        $items = $scopedRowIds !== []
            ? DB::table('inventory_balances')->whereIn('warehouse_id', $scopedRowIds)->where('on_hand', '>', '0')->selectRaw('warehouse_id, count(*) as products')->groupBy('warehouse_id')->pluck('products', 'warehouse_id')
            : collect();

        return view('inventory.warehouses', ['rows' => $rows, 'counts' => $counts, 'items' => $items, 'viewable' => $viewable, 'movable' => $movable, 'branches' => app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest(Auth::id(), 'M01.branch_view')), 'access' => $access])->layout('layouts::app', ['title' => 'المستودعات']);
    }
}
