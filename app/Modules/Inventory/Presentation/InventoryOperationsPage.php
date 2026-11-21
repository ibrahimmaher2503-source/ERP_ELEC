<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryConditions;
use App\Modules\Inventory\Application\InventoryCorrections;
use App\Modules\Inventory\Application\InventoryQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InventoryOperationsPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $screen = 'isolations';

    #[Locked]
    public string $operationKey = '';

    #[Url(except: '')]
    public string $warehouse = '';

    #[Url(except: '')]
    public string $search = '';

    public string $sourceSearch = '';

    public array $form = ['source_movement_id' => '', 'quantity' => '', 'reason' => '', 'serials' => ''];

    public array $releaseQuantities = [];

    public array $releaseSerials = [];

    #[Locked]
    public array $releaseKeys = [];

    #[Locked]
    public ?int $selectedSerial = null;

    public bool $showSerialHistory = false;

    public bool $showRelease = false;

    #[Locked]
    public ?int $releasingId = null;

    public function openRelease(int $id): void
    {
        abort_unless($this->screen === 'isolations', 422);
        $row = DB::table('inventory_isolations')->whereIn('warehouse_id', $this->ids())->where('id', $id)->first();
        abort_unless($row, 404);
        app(InventoryAccess::class)->authorize(auth()->id(), 'isolation_release', $row->warehouse_id);
        $this->resetErrorBag();
        $this->releaseQuantities[$id] ??= InventoryDisplay::inputQty(bcsub($row->quantity, $row->released, 8));
        $this->releasingId = $id;
        $this->showRelease = true;
    }

    private function ids(): array
    {
        $actions = match ($this->screen) {
            'serials' => ['serial_view'], 'corrections' => ['correction_execute'], 'isolations' => ['isolation_manage', 'isolation_release'], default => abort(404)
        };
        $ids = [];
        foreach ($actions as $action) {
            $ids = array_merge($ids, app(InventoryAccess::class)->ids(auth()->id(), $action));
        }
        abort_unless($ids, 403);

        return array_values(array_unique($ids));
    }

    public function mount(string $screen = 'isolations'): void
    {
        $this->screen = $screen;
        $this->ids();
        $this->operationKey = (string) Str::uuid();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedWarehouse(): void
    {
        $this->resetPage();
        $this->selectedSerial = null;
        $this->showSerialHistory = false;
    }

    private function serials(string $text): array
    {
        return preg_split('/\R/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    public function execute(): void
    {
        $this->ids();
        $this->validate(['form.source_movement_id' => 'required|integer|min:1', 'form.quantity' => 'required|string', 'form.reason' => 'required|string|max:2000', 'form.serials' => 'string|max:200000']);
        $source = DB::table('inventory_movements')->find((int) $this->form['source_movement_id']);
        abort_unless($source, 404);
        $data = ['quantity' => $this->form['quantity'], 'reason' => $this->form['reason'], 'idempotency_key' => $this->operationKey, 'serials' => $this->serials($this->form['serials'])];
        if ($this->screen === 'isolations') {
            $out = app(InventoryConditions::class)->isolate(auth()->id(), $data + ['warehouse_id' => $source->warehouse_id, 'product_id' => $source->product_id, 'source_movement_id' => $source->id]);
            $message = 'تم عزل الكمية؛ الرصيد الفعلي ثابت والمتاح انخفض.';
        } else {
            abort_unless($this->screen === 'corrections', 422);
            $out = app(InventoryCorrections::class)->reverse(auth()->id(), $data + ['movement_id' => $source->id]);
            $message = $out['status'] === 'posted' ? 'تم ترحيل الحركة التصحيحية مع بقاء الأصل ثابتًا.' : 'حفظ طلب التصحيح بانتظار التكامل المالي؛ لم تتغير الكميات.';
        }
        $this->operationKey = (string) Str::uuid();
        $this->form = ['source_movement_id' => '', 'quantity' => '', 'reason' => '', 'serials' => ''];
        session()->flash('status', $message);
    }

    public function release(int $id): void
    {
        abort_unless($this->screen === 'isolations', 422);
        $this->ids();
        app(InventoryConditions::class)->release(auth()->id(), $id, $this->releaseQuantities[$id] ?? '', $this->releaseKeys[$id] ??= (string) Str::uuid(), $this->serials($this->releaseSerials[$id] ?? ''));
        unset($this->releaseQuantities[$id], $this->releaseSerials[$id]);
        $this->showRelease = false;
        $this->releasingId = null;
        $this->releaseKeys[$id] = (string) Str::uuid();
        session()->flash('status', 'تم تسجيل الإفراج عن الكمية المعزولة.');
    }

    public function inspectSerial(int $id): void
    {
        abort_unless($this->screen === 'serials', 422);
        $this->serialQuery()->where('s.id', $id)->firstOrFail();
        $this->selectedSerial = $id;
        $this->showSerialHistory = true;
    }

    private function serialQuery()
    {
        $ids = $this->ids();

        return DB::table('inventory_serials as s')->where(function ($q) use ($ids) {
            $q->whereIn('s.warehouse_id', $ids)->orWhereIn('s.transfer_id', DB::table('inventory_documents')->whereIn('destination_id', $ids)->select('id'));
        });
    }

    public function render()
    {
        $ids = $this->ids();
        $scope = $this->warehouse === '' ? $ids : array_values(array_intersect($ids, [(int) $this->warehouse]));
        $history = collect();
        $selectedSerialRecord = null;
        if ($this->screen === 'serials') {
            $q = $this->serialQuery()
                ->join('inventory_movements as m', 'm.id', '=', 's.source_movement_id')
                ->leftJoin('inventory_documents as td', 'td.id', '=', 's.transfer_id');
            if ($this->warehouse !== '') {
                $selectedWh = (int) $this->warehouse;
                $q->where(fn ($w) => $w->where('s.warehouse_id', $selectedWh)
                    ->orWhere(fn ($t) => $t->where('s.state', 'transit')->where('td.destination_id', $selectedWh)));
            }
            if ($this->search !== '') {
                $term = '%'.mb_substr($this->search, 0, 200).'%';
                $q->where(fn ($q) => $q->where('s.normalized', 'ilike', $term)
                    ->orWhere('s.entered', 'ilike', $term)
                    ->orWhere('m.sku', 'ilike', $term)
                    ->orWhere('m.product_name', 'ilike', $term));
            }
            $rows = $q->orderByDesc('s.id')->select('s.*', 'm.sku', 'm.product_name', 'td.destination_id as transfer_destination_id')->paginate(15);
            if ($this->selectedSerial) {
                $selectedSerialRecord = $this->serialQuery()
                    ->join('inventory_movements as m', 'm.id', '=', 's.source_movement_id')
                    ->leftJoin('inventory_documents as td', 'td.id', '=', 's.transfer_id')
                    ->where('s.id', $this->selectedSerial)
                    ->select('s.*', 'm.sku', 'm.product_name', 'td.destination_id as transfer_destination_id')
                    ->firstOrFail();
                $history = DB::table('inventory_serial_events')->where('serial_id', $this->selectedSerial)->orderByDesc('id')->get();
            }
        } else {
            $q = $this->screen === 'isolations'
                ? DB::table('inventory_isolations as r')->join('inventory_movements as m', 'm.id', '=', 'r.source_movement_id')->whereIn('r.warehouse_id', $scope)
                : DB::table('inventory_corrections as r')->join('inventory_movements as m', 'm.id', '=', 'r.movement_id')->whereIn('m.warehouse_id', $scope);
            if ($this->search !== '') {
                $term = '%'.mb_substr($this->search, 0, 200).'%';
                $q->where(fn ($q) => $q->where('r.reason', 'ilike', $term)->orWhere('m.sku', 'ilike', $term)->orWhere('m.product_name', 'ilike', $term));
            }
            $q->select('r.*', 'm.sku', 'm.product_name', 'm.warehouse_id as wh', 'm.operation as movement_operation');
            $rows = $q->orderByDesc('r.id')->paginate(15);
        }
        $executeIds = $this->screen === 'serials' ? [] : app(InventoryAccess::class)->ids(auth()->id(), $this->screen === 'isolations' ? 'isolation_manage' : 'correction_execute');

        $releasing = $this->releasingId
            ? DB::table('inventory_isolations as r')->join('inventory_movements as m', 'm.id', '=', 'r.source_movement_id')->where('r.id', $this->releasingId)->select('r.*', 'm.sku', 'm.product_name')->first()
            : null;
        $releasingHasSerials = $this->releasingId
            ? DB::table('inventory_serials')->where('isolation_id', $this->releasingId)->where('state', 'unavailable')->exists()
            : false;

        $sourceTerm = mb_substr($this->sourceSearch, 0, 100);
        $sources = $this->screen === 'serials' ? collect() : DB::table('inventory_movements')
            ->whereIn('warehouse_id', array_values(array_intersect($scope, $executeIds)))
            ->when($this->screen === 'isolations', fn ($q) => $q->where('quantity', '>', '0'))
            ->when($sourceTerm !== '', fn ($q) => $q->where(fn ($q) => $q->where('id', ctype_digit($sourceTerm) ? (int) $sourceTerm : -1)
                ->orWhere('sku', 'ilike', '%'.$sourceTerm.'%')
                ->orWhere('product_name', 'ilike', '%'.$sourceTerm.'%')
                ->orWhere('id', (int) $this->form['source_movement_id'])))
            ->orderByDesc('id')->limit(100)->get();

        $viewableTransferIds = [];
        if ($this->screen === 'serials') {
            $transferIds = $rows->pluck('transfer_id')->filter()->unique()->values()->all();
            if ($selectedSerialRecord?->transfer_id) {
                $transferIds[] = $selectedSerialRecord->transfer_id;
            }
            $transferIds = array_values(array_unique($transferIds));
            if ($transferIds !== []) {
                try {
                    $viewableTransferIds = app(InventoryQuery::class)->documents(auth()->id(), 'transfer')->whereIn('id', $transferIds)->pluck('id')->all();
                } catch (HttpException) {
                    $viewableTransferIds = [];
                }
            }
        }

        return view('inventory.operations', [
            'selectedSerialRecord' => $selectedSerialRecord,
            'viewableTransferIds' => $viewableTransferIds,
            'releasing' => $releasing,
            'releasingHasSerials' => $releasingHasSerials,
            'canExecute' => $executeIds !== [],
            'rows' => $rows,
            'history' => $history,
            'warehouses' => DB::table('inventory_warehouses')->whereIn('id', $ids)->orderBy('name')->get(),
            'access' => app(InventoryAccess::class),
            'sources' => $sources,
        ])->layout('layouts::app', ['title' => match ($this->screen) {
            'serials' => 'الأرقام المسلسلة', 'isolations' => 'المخزون غير المتاح', default => 'تصحيح حركات المخزون'
        }]);
    }
}
