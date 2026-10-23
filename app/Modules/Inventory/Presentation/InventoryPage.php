<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Inventory\Application\InventoryQuery;
use App\Modules\Inventory\Application\InventoryReconciliation;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class InventoryPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $screen = 'dashboard';

    #[Url]
    public string $warehouse = '';

    public bool $editingMinimum = false;

    public string $minimum = '';

    #[Locked]
    public ?int $balanceId = null;

    public string $search = '';

    public string $status = '';

    public string $category = '';

    public string $branch = '';

    public string $operation = '';

    #[Locked]
    public ?array $reconciliation = null;

    public function reconcileWarehouse(int $warehouse): void
    {
        $this->reconciliation = app(InventoryReconciliation::class)->warehouse(Auth::id(), $warehouse);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'warehouse', 'branch', 'category', 'status', 'operation'], true)) {
            $this->resetPage();
            $this->reconciliation = null;
        }
    }

    public const TITLES = ['dashboard' => 'لوحة المخزون', 'balances' => 'أرصدة المخزون', 'movements' => 'حركات المخزون', 'reservations' => 'حجوزات المخزون'];

    public function mount(string $screen = 'dashboard'): void
    {
        abort_unless(isset(self::TITLES[$screen]), 404);
        $this->screen = $screen;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedWarehouse(): void
    {
        $this->resetPage();
    }

    public function editMinimum(int $id): void
    {
        $r = app(InventoryQuery::class)->balances(Auth::id())->where('id', $id)->first();
        abort_unless($r, 404);
        app(InventoryAccess::class)->authorize(Auth::id(), 'reorder_manage', $r->warehouse_id);
        $this->balanceId = $id;
        $this->minimum = $r->minimum ?? '';
        $this->editingMinimum = true;
    }

    public function saveMinimum(): void
    {
        abort_unless($this->balanceId, 422);
        app(InventoryActions::class)->setMinimum(Auth::id(), $this->balanceId, $this->minimum);
        $this->editingMinimum = false;
    }

    public function render()
    {
        $actor = Auth::id();
        $action = match ($this->screen) {
            'dashboard' => 'dashboard_view','balances' => 'balance_view','movements' => 'movement_view','reservations' => 'reservation_view'
        };
        $ids = app(InventoryAccess::class)->ids($actor, $action);
        abort_unless($ids !== [], 403, 'امنح صلاحية المخزون ونطاق المستودع من تفاصيل المستخدم.');
        $warehouses = DB::table('inventory_warehouses')->whereIn('id', $ids)->orderBy('name')->get();
        $scope = $this->warehouse !== '' ? array_values(array_intersect($ids, [(int) $this->warehouse])) : $ids;
        if (ctype_digit($this->branch)) {
            $scope = array_values(array_intersect($scope, $warehouses->where('branch_id', (int) $this->branch)->pluck('id')->all()));
        }
        $balanceIds = array_values(array_intersect($scope, array_unique(array_merge(
            app(InventoryAccess::class)->ids($actor, 'balance_view'),
            app(InventoryAccess::class)->ids($actor, 'dashboard_view'),
        ))));
        $canViewBalanceSummary = $balanceIds !== [];
        $balances = DB::table('inventory_balances')->whereIn('warehouse_id', $balanceIds);
        $stats = ['products' => (clone $balances)->distinct()->count('product_id'), 'warehouses' => $warehouses->where('active', true)->count(), 'low' => (clone $balances)->whereNotNull('minimum')->whereColumn('available', '<', 'minimum')->count(), 'empty' => (clone $balances)->where('available', '0')->count()];
        $table = match ($this->screen) {
            'movements' => 'inventory_movements','reservations' => 'inventory_reservations',default => 'inventory_balances'
        };
        $query = DB::table($table)->whereIn('warehouse_id', $scope);
        app(InventoryQuery::class)->filter($query, $table, ['search' => $this->search, 'status' => $this->status, 'operation' => $this->operation, 'category' => $this->category]);
        $distribution = (clone $balances)->selectRaw('warehouse_id, count(*) as products')->groupBy('warehouse_id')->get();
        $recent = DB::table('inventory_movements')->whereIn('warehouse_id', $scope)->orderByDesc('id')->limit(5)->get();

        $branches = collect(app(OrganizationApi::class)->inventoryScopes($actor))->unique('branch_id');
        $transit = DB::table('inventory_document_lines as l')->join('inventory_documents as d', 'd.id', '=', 'l.document_id')->where('d.status', 'dispatched')->whereIn('d.destination_id', $scope)->select('l.*', 'd.id as transfer_id', 'd.destination_id')->limit(20)->get();

        foreach ($transit as $entry) {
            $physical=(string)DB::table('inventory_transfer_receipt_lines')->where('line_id',$entry->id)->selectRaw('coalesce(sum(accepted+damaged),0) as physical')->value('physical');
            $entry->quantity=bcsub($entry->quantity,$physical,8);
        }
        $transit=$transit->filter(fn($e)=>bccomp($e->quantity,'0',8)>0);

        return view('inventory.overview', ['branches' => $branches, 'transit' => $transit, 'title' => self::TITLES[$this->screen], 'rows' => $query->orderByDesc('id')->paginate(15), 'stats' => $stats, 'canViewBalanceSummary' => $canViewBalanceSummary, 'warehouses' => $warehouses, 'distribution' => $distribution, 'recent' => $recent, 'categories' => (clone $balances)->whereNotNull('category_id')->select('category_id', 'category_name')->distinct()->get()])->layout('layouts::app', ['title' => self::TITLES[$this->screen]]);
    }
}
