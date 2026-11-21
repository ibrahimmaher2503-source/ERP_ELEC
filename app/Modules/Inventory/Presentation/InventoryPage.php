<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Inventory\Application\InventoryQuery;
use App\Modules\Inventory\Application\InventoryReconciliation;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\ProductAccess;
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

    #[Url(except: '')]
    public string $warehouse = '';

    public bool $editingMinimum = false;

    public string $minimum = '';

    #[Locked]
    public ?int $balanceId = null;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: '')]
    public string $operation = '';

    #[Url(except: '')]
    public string $dateFrom = '';

    #[Url(except: '')]
    public string $dateTo = '';

    #[Locked]
    public ?array $reconciliation = null;

    public function reconcileWarehouse(int $warehouse): void
    {
        $this->reconciliation = app(InventoryReconciliation::class)->warehouse(Auth::id(), $warehouse);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'warehouse', 'branch', 'category', 'status', 'operation', 'dateFrom', 'dateTo'], true)) {
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

    public function editMinimum(int $id): void
    {
        $r = app(InventoryQuery::class)->balances(Auth::id())->where('id', $id)->first();
        abort_unless($r, 404);
        app(InventoryAccess::class)->authorize(Auth::id(), 'reorder_manage', $r->warehouse_id);
        $this->balanceId = $id;
        $this->minimum = $r->minimum !== null ? InventoryDisplay::inputQty($r->minimum) : '';
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
        $access = app(InventoryAccess::class);
        $action = match ($this->screen) {
            'dashboard' => 'dashboard_view','balances' => 'balance_view','movements' => 'movement_view','reservations' => 'reservation_view'
        };
        $ids = $access->ids($actor, $action);
        abort_unless($ids !== [], 403, $access->denialMessage($actor, $action));
        $warehouses = DB::table('inventory_warehouses')->whereIn('id', $ids)->orderBy('name')->get();
        $scope = $this->warehouse !== '' ? array_values(array_intersect($ids, [(int) $this->warehouse])) : $ids;
        if (ctype_digit($this->branch)) {
            $scope = array_values(array_intersect($scope, $warehouses->where('branch_id', (int) $this->branch)->pluck('id')->all()));
        }
        $balanceIds = array_values(array_intersect($scope, array_unique(array_merge(
            $access->ids($actor, 'balance_view'),
            $access->ids($actor, 'dashboard_view'),
        ))));
        $canViewBalanceSummary = $balanceIds !== [];
        $balances = DB::table('inventory_balances')->whereIn('warehouse_id', $balanceIds);
        $stats = ['products' => (clone $balances)->distinct()->count('product_id'), 'warehouses' => $warehouses->where('active', true)->count(), 'low' => (clone $balances)->whereNotNull('minimum')->whereColumn('available', '<', 'minimum')->count(), 'empty' => (clone $balances)->where('available', '0')->count()];

        $canViewWarehouses = $access->allows($actor, 'warehouse_view');
        $canViewBalances = $access->ids($actor, 'balance_view') !== [];
        $canViewMovements = $access->ids($actor, 'movement_view') !== [];
        $canViewReservations = $access->ids($actor, 'reservation_view') !== [];
        $canViewIsolations = ($access->ids($actor, 'isolation_manage') !== [] || $access->ids($actor, 'isolation_release') !== []);
        $canViewProducts = app(ProductAccess::class)->allows($actor, 'M02.product_view');

        $branches = $this->screen === 'dashboard' ? collect() : collect(app(OrganizationApi::class)->inventoryScopes($actor))->unique('branch_id');
        $distribution = collect();
        $recent = collect();
        $transit = collect();
        $lowItems = collect();
        $pendingApprovals = collect();
        $warehouseNames = $warehouses->pluck('name', 'id')->all();

        if ($this->screen === 'dashboard') {
            $distribution = (clone $balances)->selectRaw('warehouse_id, count(*) as products')->groupBy('warehouse_id')->get();
            $recent = DB::table('inventory_movements')->whereIn('warehouse_id', $scope)->orderByDesc('id')->limit(8)->get();
            $transit = DB::table('inventory_document_lines as l')->join('inventory_documents as d', 'd.id', '=', 'l.document_id')->where('d.status', 'dispatched')->whereIn('d.destination_id', $scope)->select('l.*', 'd.id as transfer_id', 'd.destination_id')->limit(20)->get();
            foreach ($transit as $entry) {
                $physical = (string) DB::table('inventory_transfer_receipt_lines')->where('line_id', $entry->id)->selectRaw('coalesce(sum(accepted+damaged),0) as physical')->value('physical');
                $entry->quantity = bcsub($entry->quantity, $physical, 8);
            }
            $transit = $transit->filter(fn ($e) => bccomp($e->quantity, '0', 8) > 0);
            if ($canViewBalanceSummary) {
                $lowItems = (clone $balances)->whereNotNull('minimum')->whereColumn('available', '<', 'minimum')->orderBy('available')->limit(5)->get();
            }

            $docs = app(InventoryDocuments::class);
            $approveScopes = [];
            foreach (array_keys(InventoryDocuments::KINDS) as $kind) {
                $allowed = $access->ids($actor, $docs->permission($kind, 'approve'));
                if ($allowed !== []) {
                    $approveScopes[$kind] = $allowed;
                }
            }
            if ($approveScopes !== []) {
                $pendingApprovals = DB::table('inventory_documents')->where('status', 'submitted')->where('created_by', '!=', $actor)
                    ->where(function ($q) use ($approveScopes) {
                        foreach ($approveScopes as $kind => $allowed) {
                            $q->orWhere(fn ($k) => $k->where('kind', $kind)
                                ->whereIn('warehouse_id', $allowed)
                                ->where(fn ($d) => $d->whereNull('destination_id')->orWhereIn('destination_id', $allowed)));
                        }
                    })->orderBy('id')->limit(5)->get();
                $extraWarehouseIds = $pendingApprovals->pluck('warehouse_id')
                    ->merge($pendingApprovals->pluck('destination_id'))
                    ->filter()
                    ->unique()
                    ->diff(array_keys($warehouseNames));
                if ($extraWarehouseIds->isNotEmpty()) {
                    $warehouseNames += DB::table('inventory_warehouses')->whereIn('id', $extraWarehouseIds)->pluck('name', 'id')->all();
                }
            }
        }

        $rows = collect();
        $chips = null;
        $productNames = collect();
        $docKinds = collect();
        $categories = collect();
        $movementUnits = collect();
        if ($this->screen !== 'dashboard') {
            $table = match ($this->screen) {
                'movements' => 'inventory_movements', 'reservations' => 'inventory_reservations', default => 'inventory_balances'
            };
            $query = DB::table($table)->whereIn('warehouse_id', $scope);
            app(InventoryQuery::class)->filter($query, $table, ['search' => $this->search, 'status' => $this->status, 'operation' => $this->operation, 'category' => $this->category, 'date_from' => $this->dateFrom, 'date_to' => $this->dateTo]);
            $rows = $query->orderByDesc('id')->paginate(15);

            if ($this->screen === 'balances' && $canViewBalanceSummary) {
                $chips = [
                    '' => (clone $balances)->count(),
                    'low' => $stats['low'],
                    'empty' => $stats['empty'],
                    'reserved' => (clone $balances)->where('reserved', '>', '0')->count(),
                    'unavailable' => (clone $balances)->where('unavailable', '>', '0')->count(),
                ];
                $categories = (clone $balances)->whereNotNull('category_id')->select('category_id', 'category_name')->distinct()->get();
            }
            if ($this->screen === 'reservations' && $rows->isNotEmpty()) {
                $productNames = DB::table('inventory_balances')->whereIn('warehouse_id', $scope)->whereIn('product_id', $rows->pluck('product_id')->unique())
                    ->select('product_id', 'sku', 'product_name', 'unit_name')->distinct()->get()->keyBy('product_id');
            }
            if ($this->screen === 'movements' && $rows->isNotEmpty()) {
                $docKinds = DB::table('inventory_documents')->whereIn('id', $rows->pluck('document_id')->filter()->unique())->pluck('kind', 'id');
                $movementUnits = DB::table('inventory_balances')
                    ->whereIn('warehouse_id', $scope)
                    ->whereIn('product_id', $rows->pluck('product_id')->unique())
                    ->get(['warehouse_id', 'product_id', 'unit_name'])
                    ->keyBy(fn ($b) => $b->warehouse_id.':'.$b->product_id);
            }
        }

        $editingBalance = $this->editingMinimum && $this->balanceId
            ? app(InventoryQuery::class)->balances($actor)->where('id', $this->balanceId)->first()
            : null;
        $reconciliationProducts = collect();
        if ($this->reconciliation && ! empty($this->reconciliation['discrepancies'])) {
            $recWarehouse = (int) $this->reconciliation['warehouse_id'];
            $recPids = array_column($this->reconciliation['discrepancies'], 'product_id');
            $reconciliationProducts = DB::table('inventory_balances')->where('warehouse_id', $recWarehouse)->whereIn('product_id', $recPids)
                ->select('product_id', 'sku', 'product_name')->get()->keyBy('product_id');
            $missingPids = array_diff($recPids, $reconciliationProducts->keys()->all());
            if ($missingPids !== []) {
                $fromMovements = DB::table('inventory_movements')->where('warehouse_id', $recWarehouse)->whereIn('product_id', $missingPids)
                    ->select('product_id', 'sku', 'product_name')->distinct()->get()->keyBy('product_id');
                $reconciliationProducts = $reconciliationProducts->union($fromMovements);
            }
        }

        return view('inventory.overview', [
            'chips' => $chips, 'lowItems' => $lowItems, 'productNames' => $productNames, 'docKinds' => $docKinds, 'movementUnits' => $movementUnits, 'pendingApprovals' => $pendingApprovals,
            'canExport' => $access->ids($actor, 'export') !== [], 'canViewWarehouses' => $canViewWarehouses, 'canViewBalances' => $canViewBalances,
            'canViewMovements' => $canViewMovements, 'canViewReservations' => $canViewReservations, 'canViewIsolations' => $canViewIsolations,
            'canViewProducts' => $canViewProducts, 'warehouseNames' => $warehouseNames,
            'editingBalance' => $editingBalance, 'reconciliationProducts' => $reconciliationProducts,
            'branches' => $branches, 'transit' => $transit, 'title' => self::TITLES[$this->screen], 'rows' => $rows, 'stats' => $stats,
            'canViewBalanceSummary' => $canViewBalanceSummary, 'warehouses' => $warehouses, 'distribution' => $distribution, 'recent' => $recent, 'categories' => $categories,
        ])->layout('layouts::app', ['title' => self::TITLES[$this->screen]]);
    }
}
