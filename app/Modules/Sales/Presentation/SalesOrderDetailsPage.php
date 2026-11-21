<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\CustomerAccess;
use App\Modules\Sales\Application\ApproveSalesOrder;
use App\Modules\Sales\Application\QuotationAccess;
use App\Modules\Sales\Application\SalesDeliveries;
use App\Modules\Sales\Application\SalesOrderAccess;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Sales\Application\SalesOrderCommercialReview;
use App\Modules\Sales\Application\SalesOrderExportDefinition;
use App\Modules\Sales\Application\SalesOrderProgress;
use App\Modules\Sales\Application\SalesOrderQuery;
use App\Modules\Sales\Application\SalesOrderReadiness;
use App\Modules\Sales\Domain\SalesOrderHistoryReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Ramsey\Uuid\Uuid;

class SalesOrderDetailsPage extends Component
{
    #[Locked]
    public int $orderId;

    #[Locked]
    public int $version;

    #[Locked]
    public string $screen = 'overview';

    public string $cancelReason = '';

    public string $rejectReason = '';

    public string $bulkWarehouse = '';

    public array $reservationWarehouses = [];

    public array $deliveryQuantities = [];

    public array $backorderQuantities = [];

    public string $deliveryReference = '';

    public string $exceptionDecisionReason = '';

    #[Locked]
    public string $deliveryKey;

    protected const SCREENS = ['overview', 'review', 'amendment', 'delivery', 'invoices', 'closure'];

    public function mount(int $order, string $screen = 'overview'): void
    {
        abort_unless(in_array($screen, self::SCREENS, true), 404);
        $this->screen = $screen;
        $record = $this->record($order);
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'view', (int) $record->branch_id);
        $this->orderId = (int) $record->id;
        $this->version = (int) $record->version;
        $this->deliveryKey = (string) Str::uuid();
    }

    private function record(?int $id = null)
    {
        return app(SalesOrderQuery::class)
            ->query(auth()->id())
            ->with(['lines', 'reviews'])
            ->findOrFail($id ?? $this->orderId);
    }

    private function authorizeRecord(string $action): object
    {
        $record = $this->record();
        app(SalesOrderAccess::class)->authorize(auth()->id(), $action, (int) $record->branch_id);
        abort_unless((int) $record->version === $this->version, 409, 'تغير أمر البيع منذ فتح الصفحة. أعد تحميل البيانات قبل المتابعة.');

        return $record;
    }

    public function refresh(): void
    {
        $record = $this->record();
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'view', (int) $record->branch_id);
        $this->version = (int) $record->version;
    }

    public function applyWarehouseToAll(): void
    {
        $record = $this->record();
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'view', (int) $record->branch_id);
        if (! ctype_digit($this->bulkWarehouse)) {
            return;
        }
        foreach ($record->lines as $line) {
            $this->reservationWarehouses[$line->id] = (int) $this->bulkWarehouse;
        }
    }

    public function fillAvailableDeliveries(): void
    {
        $record = $this->authorizeRecord('delivery_create');
        $progress = app(SalesOrderProgress::class)->read(auth()->id(), $record);
        foreach ($record->lines as $line) {
            $state = $progress['lines'][$line->id] ?? null;
            if ($state && $state['outstanding'] !== null && bccomp((string) $state['outstanding'], '0', 8) > 0) {
                $this->deliveryQuantities[$line->id] = SalesOrderDisplay::inputDecimal((string) $state['outstanding']);
            }
        }
    }

    public function operate(string $operation): void
    {
        abort_unless(in_array($operation, ['submit', 'withdraw', 'reject', 'approve', 'amend', 'delivery_create', 'delivery_confirm', 'invoice_view', 'close', 'return_create', 'convert'], true), 422);
        $record = $this->record();
        app(SalesOrderAccess::class)->authorize(auth()->id(), $operation, (int) $record->branch_id);
        abort_unless((int) $record->version === $this->version, 409, 'تغير أمر البيع منذ فتح الصفحة. أعد تحميل البيانات قبل المتابعة.');

        if ($operation === 'submit') {
            app(SalesOrderActions::class)->submit(auth()->id(), $this->orderId, $this->version);
            $this->refresh();
            session()->flash('status', 'تم تقديم أمر البيع للمراجعة.');

            return;
        }

        if ($operation === 'withdraw') {
            app(SalesOrderActions::class)->withdraw(auth()->id(), $this->orderId, $this->version);
            $this->refresh();
            session()->flash('status', 'تم سحب طلب المراجعة.');

            return;
        }

        if ($operation === 'reject') {
            app(SalesOrderActions::class)->reject(auth()->id(), $this->orderId, $this->version, $this->rejectReason);
            $this->refresh();
            $this->rejectReason = '';
            session()->flash('status', 'تم رفض المراجعة وإرجاع أمر البيع إلى المسودة.');

            return;
        }

        if ($operation === 'approve') {
            app(ApproveSalesOrder::class)->execute(auth()->id(), $this->orderId, $this->version, $this->reservationWarehouses);
            $this->refresh();
            session()->flash('status', 'تم اعتماد أمر البيع وتسجيل الحجز والطلب المؤجل الفعلي.');

            return;
        }

        app(SalesOrderReadiness::class)->guard(auth()->id(), $this->orderId, $this->version, $operation);
    }

    public function createDelivery(): void
    {
        $this->authorizeRecord('delivery_create');
        $lines = [];
        foreach ($this->deliveryQuantities as $id => $quantity) {
            if ($quantity !== '') {
                $lines[] = ['line_id' => $id, 'quantity' => $quantity];
            }
        }
        app(SalesDeliveries::class)->create(
            auth()->id(),
            $this->orderId,
            ['creation_key' => $this->deliveryKey, 'reference' => $this->deliveryReference, 'lines' => $lines]
        );
        $this->deliveryKey = (string) Str::uuid();
        $this->deliveryQuantities = [];
        $this->deliveryReference = '';
        session()->flash('status', 'تم حفظ طلب التسليم؛ تأكيده يرحل المخزون والتكلفة.');
    }

    public function approveCommercialExceptions(): void
    {
        $this->authorizeRecord('exception_approve');
        app(SalesOrderCommercialReview::class)->decide(auth()->id(), $this->orderId, $this->version, $this->exceptionDecisionReason);
        $this->exceptionDecisionReason = '';
        session()->flash('status', 'تم تسجيل القرار الإضافي؛ يظل اعتماد الأمر إجراءً مستقلًا.');
    }

    public function confirmDelivery(int $instruction): void
    {
        $this->authorizeRecord('delivery_confirm');
        abort_unless(DB::table('sales_delivery_instructions')->where('order_id', $this->orderId)->where('id', $instruction)->exists(), 404);
        app(SalesDeliveries::class)->confirm(auth()->id(), $instruction);
        session()->flash('status', 'تم تأكيد التسليم وترحيل المخزون وFIFO وقيد التكلفة.');
    }

    public function allocateBackorder(int $line): void
    {
        $this->authorizeRecord('delivery_create');
        $allocation = DB::table('sales_order_allocations')->where('order_id', $this->orderId)->where('line_id', $line)->first();
        abort_unless($allocation, 404);
        app(InventoryApi::class)->replenishReservation(
            auth()->id(),
            $allocation->reservation_id,
            (string) ($this->backorderQuantities[$line] ?? ''),
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'sales-backorder:'.$this->deliveryKey.':'.$line)->toString()
        );
        $this->deliveryKey = (string) Str::uuid();
        $this->backorderQuantities[$line] = '';
        session()->flash('status', 'تم تخصيص مخزون متاح للطلب المؤجل.');
    }

    public function cancel(): void
    {
        $record = $this->authorizeRecord('cancel');
        app(SalesOrderActions::class)->cancel(auth()->id(), $record->id, $this->version, $this->cancelReason);
        $this->refresh();
        $this->cancelReason = '';
        session()->flash('status', 'تم إلغاء أمر البيع.');
    }

    public function export()
    {
        $record = $this->authorizeRecord('export');
        $path = app(SalesOrderExportDefinition::class)->export(auth()->id(), [], $record->id);

        return response()->download($path, 'sales-order-'.$record->id.'.xlsx', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate()->deleteFileAfterSend();
    }

    private function blockers($record, string $operation): array
    {
        $messages = app(SalesOrderReadiness::class)->blockers($record, $operation);
        if ($messages === []) {
            $messages[] = 'هذا الإجراء غير متاح حتى يكتمل عقده التشغيلي في الوحدة المالكة.';
        }

        return $messages;
    }

    public function render()
    {
        $record = $this->record();
        $access = app(SalesOrderAccess::class);
        $actor = auth()->id();
        $progress = app(SalesOrderProgress::class)->read($actor, $record);
        $access->authorize($actor, 'view', (int) $record->branch_id);
        $actions = $access;
        $history = $access->allows($actor, 'history', (int) $record->branch_id)
            ? app(SalesOrderHistoryReader::class)->read($actor, $record->id)
            : [];
        $operation = match ($this->screen) {
            'review' => 'approve',
            'amendment' => 'amend',
            'delivery' => 'delivery_create',
            'invoices' => 'invoice_view',
            'closure' => 'close',
            default => null,
        };

        $sourceQuotation = null;
        if ($record->source_type === 'quotation') {
            $sourceQuotation = DB::table('sales_quotation_conversions as c')
                ->join('sales_quotations as q', 'q.id', '=', 'c.quotation_id')
                ->where('c.order_id', $record->id)
                ->first(['c.quotation_id', 'q.number', 'q.draft_key']);
        }

        $orderIssue = $record->status === 'approved'
            ? DB::table('sales_order_issues')->where('order_id', $record->id)->first(['approved_by', 'approved_at', 'tax_snapshot'])
            : null;

        $latestReview = $record->reviews->first();
        $exceptionDecision = $record->status === 'pending_approval' && $latestReview
            ? DB::table('sales_order_exception_decisions')->where('review_id', $latestReview->id)->first()
            : null;

        $deliveryInstructions = DB::table('sales_delivery_instructions')->where('order_id', $record->id)->orderByDesc('id')->get();
        $deliveryLinesByInstruction = $deliveryInstructions->isNotEmpty()
            ? DB::table('sales_delivery_instruction_lines')->whereIn('instruction_id', $deliveryInstructions->pluck('id'))->orderBy('id')->get()->groupBy('instruction_id')
            : collect();

        $warehouseNames = [];
        $inventoryApi = app(InventoryApi::class);
        foreach ($progress['lines'] as $lineState) {
            $wid = (int) ($lineState['warehouse_id'] ?? 0);
            if ($wid > 0 && ! isset($warehouseNames[$wid])) {
                try {
                    $w = $inventoryApi->warehouse($wid);
                    $warehouseNames[$wid] = $w['name'] ?? ('#'.$wid);
                } catch (\Throwable) {
                    $warehouseNames[$wid] = '#'.$wid;
                }
            }
        }

        $customerCredit = null;
        if ($this->screen === 'review' && $record->status === 'pending_approval') {
            try {
                $customerCredit = app(CustomersApi::class)->commercialProfile($actor, (int) $record->customer_id);
            } catch (\Throwable) {
                $customerCredit = null;
            }
        }

        return view('sales.orders.details', [
            'record' => $record,
            'progress' => $progress,
            'commercialReview' => $record->source_type === 'direct' && $record->status === 'pending_approval'
                ? app(SalesOrderCommercialReview::class)->evaluate($actor, $record)
                : ['blockers' => [], 'exceptions' => []],
            'warehouseChoices' => $inventoryApi->reservationWarehouses($actor, $record->branch_id),
            'warehouseNames' => $warehouseNames,
            'deliveryInstructions' => $deliveryInstructions,
            'deliveryLinesByInstruction' => $deliveryLinesByInstruction,
            'sourceQuotation' => $sourceQuotation,
            'orderIssue' => $orderIssue,
            'exceptionDecision' => $exceptionDecision,
            'customerCredit' => $customerCredit,
            'history' => $history,
            'operation' => $operation,
            'blockers' => $operation ? $this->blockers($record, $operation) : [],
            'dependencyBlockers' => $this->screen === 'closure' ? [
                'close' => $this->blockers($record, 'close'),
                'return_create' => $this->blockers($record, 'return_create'),
            ] : [],
            'stale' => (int) $record->version !== $this->version,
            'isReviewRequester' => (int) ($latestReview?->requested_by ?? 0) === (int) $actor,
            'canSelfApprove' => app(OrganizationApi::class)->allowsSelfApproval($actor, 'sales_orders'),
            'canViewCustomer' => app(CustomerAccess::class)->allows($actor, 'M03.customer_view'),
            'canViewQuotation' => app(QuotationAccess::class)->allows($actor),
            'canViewReturns' => $access->allows($actor, 'return_view', (int) $record->branch_id),
            'can' => fn (string $action): bool => $actions->allows($actor, $action, (int) $record->branch_id),
        ])->layout('layouts::app', ['title' => $record->label()]);
    }
}
