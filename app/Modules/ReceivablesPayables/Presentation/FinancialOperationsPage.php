<?php

namespace App\Modules\ReceivablesPayables\Presentation;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Parties\Application\Contracts\SearchSuppliersRequest;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ReceivablesPayables\Application\ReceivablesPayablesAccess;
use App\Modules\Sales\Application\Contracts\SalesDeliverySources;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

final class FinancialOperationsPage extends Component
{
    #[Locked]
    public string $screen;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: '')]
    public string $partyType = '';

    #[Url(except: '')]
    public string $agingBucket = '';

    #[Url(except: '')]
    public string $openStatus = 'open';

    #[Locked]
    public string $creationKey = '';

    public array $form = [];

    public string $customerSearch = '';

    public string $supplierSearch = '';

    public string $receiptChoice = '';

    /** @var array<int,array{on:bool,qty:string,price:string}> receipt line id => chosen quantity and price */
    public array $receiptPicked = [];

    public function updatedFormSupplierId(): void
    {
        $this->receiptChoice = '';
        $this->receiptPicked = [];
    }

    public function updatedReceiptChoice(): void
    {
        $this->receiptPicked = [];
    }

    public string $orderChoice = '';

    /** @var array<int,array{on:bool,qty:string}> delivery line id => chosen quantity */
    public array $picked = [];

    public function updatedFormCustomerId(): void
    {
        $this->orderChoice = '';
        $this->picked = [];
        $this->form['tax_treatment'] = 'inherit';
        $this->form['tax_exempt_reason'] = '';
    }

    public function updatedOrderChoice(): void
    {
        $this->picked = [];
        if ($this->orderChoice === '') {
            $this->form['tax_treatment'] = 'inherit';
            $this->form['tax_exempt_reason'] = '';

            return;
        }
        $first = collect($this->invoiceableLines())->firstWhere('order_id', (int) $this->orderChoice);
        $orderTax = $first['tax_treatment'] ?? 'rule';
        $this->form['tax_treatment'] = in_array($orderTax, ['vat_14', 'exempt_0'], true) ? $orderTax : 'inherit';
        $this->form['tax_exempt_reason'] = (string) ($first['tax_exempt_reason'] ?? '');
    }

    public function mount(string $screen): void
    {
        $this->screen = $screen;
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->creationKey = (string) Str::uuid();
        $today = now('Africa/Cairo')->toDateString();
        $this->form = ['supplier_id' => '', 'supplier_invoice_number' => '', 'invoice_date' => $today, 'due_date' => $today,
            'tax_mode' => 'exclusive', 'tax_treatment' => 'inherit', 'tax_exempt_reason' => '',
            'order_line_id' => '', 'receipt_line_id' => '', 'delivery_line_id' => '', 'customer_id' => '',
            'quantity' => '', 'unit_price' => '', 'discount_amount' => '0.00', 'kind' => 'supplier', 'invoice_id' => '',
            'note_date' => $today, 'reason' => '', 'amount' => ''];
    }

    public function createSupplier(): void
    {
        $available = collect($this->invoiceableReceipts())->keyBy('receipt_line_id');
        $byOrderLine = [];
        foreach ($this->receiptPicked as $receiptLineId => $choice) {
            if (empty($choice['on']) || ! $available->has((int) $receiptLineId)) {
                continue;
            }
            $row = $available[(int) $receiptLineId];
            $qty = trim((string) ($choice['qty'] ?? ''));
            $qty = $qty !== '' ? $qty : (string) $row['remaining'];
            $price = trim((string) ($choice['price'] ?? ''));
            $price = $price !== '' ? $price : rtrim(rtrim((string) $row['unit_price'], '0'), '.');
            $key = (int) $row['order_line_id'];
            $byOrderLine[$key] ??= ['order_line_id' => $key, 'quantity' => '0', 'unit_price' => $price, 'discount_amount' => '0.00', 'matches' => []];
            $byOrderLine[$key]['quantity'] = bcadd($byOrderLine[$key]['quantity'], $qty, 8);
            $byOrderLine[$key]['matches'][] = ['receipt_line_id' => (int) $receiptLineId, 'quantity' => $qty];
        }
        if ($byOrderLine === []) {
            throw ValidationException::withMessages(['receiptPicked' => 'اختر بندًا واحدًا على الأقل من الاستلامات.']);
        }
        $id = app(ReceivablesPayablesApi::class)->createSupplierInvoice(auth()->id(), ['creation_key' => $this->creationKey,
            'supplier_id' => $this->form['supplier_id'], 'supplier_invoice_number' => $this->form['supplier_invoice_number'],
            'invoice_date' => $this->form['invoice_date'], 'due_date' => $this->form['due_date'], 'tax_mode' => $this->form['tax_mode'],
            'lines' => array_values($byOrderLine)]);
        $this->resetForm();
        $this->receiptPicked = [];
        $this->receiptChoice = '';
        session()->flash('financial_message', 'تم إنشاء مسودة فاتورة المورد #'.$id.' من الاستلام المرحّل المحدد.');
    }

    /** Posted receipt lines that still have a quantity left to invoice. */
    private function invoiceableReceipts(): array
    {
        $supplierId = (int) ($this->form['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            return [];
        }
        $rows = app(PurchasingApi::class)->postedReceiptLines(auth()->id(), $supplierId);
        if ($rows === []) {
            return [];
        }
        $matched = DB::table('rp_supplier_invoice_matches')->whereIn('receipt_line_id', array_column($rows, 'receipt_line_id'))
            ->selectRaw('receipt_line_id, coalesce(sum(quantity),0) as quantity')->groupBy('receipt_line_id')->pluck('quantity', 'receipt_line_id')->all();
        $out = [];
        foreach ($rows as $row) {
            $remaining = bcsub((string) $row['received_quantity'], (string) ($matched[$row['receipt_line_id']] ?? '0'), 8);
            if (bccomp($remaining, '0', 8) > 0) {
                $row['remaining'] = rtrim(rtrim($remaining, '0'), '.');
                $out[] = $row;
            }
        }

        return $out;
    }

    public function createCustomer(): void
    {
        $remaining = array_column($this->invoiceableLines(), 'remaining', 'delivery_line_id');
        $lines = [];
        foreach ($this->picked as $lineId => $choice) {
            if (! empty($choice['on']) && isset($remaining[(int) $lineId])) {
                $qty = trim((string) ($choice['qty'] ?? ''));
                $lines[] = ['delivery_line_id' => (int) $lineId, 'quantity' => $qty !== '' ? $qty : (string) ($remaining[(int) $lineId] ?? '')];
            }
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['picked' => 'اختر بندًا واحدًا على الأقل من بنود التسليم.']);
        }
        $id = app(ReceivablesPayablesApi::class)->createCustomerInvoice(auth()->id(), ['creation_key' => $this->creationKey,
            'customer_id' => $this->form['customer_id'], 'invoice_date' => $this->form['invoice_date'], 'due_date' => $this->form['due_date'],
            'tax_treatment' => $this->form['tax_treatment'] ?? 'inherit',
            'tax_exempt_reason' => ($this->form['tax_treatment'] ?? 'inherit') === 'exempt_0' ? ($this->form['tax_exempt_reason'] ?? '') : null,
            'lines' => $lines]);
        $this->resetForm();
        $this->picked = [];
        $this->orderChoice = '';
        session()->flash('financial_message', 'تم إنشاء مسودة فاتورة العميل #'.$id.' من التسليم المؤكد المحدد.');
    }

    /** Confirmed delivery lines that still have a quantity left to invoice, grouped for the picker. */
    private function invoiceableLines(): array
    {
        $customerId = (int) ($this->form['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return [];
        }
        $rows = app(SalesDeliverySources::class)->confirmedDeliveryLines(auth()->id(), $customerId);
        if ($rows === []) {
            return [];
        }
        $invoiced = DB::table('rp_customer_invoice_lines')->whereIn('delivery_line_id', array_column($rows, 'delivery_line_id'))
            ->selectRaw('delivery_line_id, coalesce(sum(quantity),0) as quantity')->groupBy('delivery_line_id')->pluck('quantity', 'delivery_line_id')->all();
        $out = [];
        foreach ($rows as $row) {
            $remaining = bcsub((string) $row['delivered_quantity'], (string) ($invoiced[$row['delivery_line_id']] ?? '0'), 8);
            if (bccomp($remaining, '0', 8) > 0) {
                $row['remaining'] = rtrim(rtrim($remaining, '0'), '.');
                $out[] = $row;
            }
        }

        return $out;
    }

    public function createCredit(): void
    {
        $id = app(ReceivablesPayablesApi::class)->createCreditNote(auth()->id(), ['creation_key' => $this->creationKey,
            'kind' => $this->form['kind'], 'invoice_id' => $this->form['invoice_id'], 'note_date' => $this->form['note_date'],
            'reason' => $this->form['reason'], 'amount' => $this->form['amount']]);
        $this->resetForm();
        session()->flash('financial_message', 'تم إنشاء مسودة الإشعار #'.$id.' مرتبطة بالفاتورة الأصلية.');
    }

    public function supplierStep(int $id, int $version, string $step): void
    {
        $api = app(ReceivablesPayablesApi::class);
        match ($step) {
            'validate' => $api->validateSupplierInvoice(auth()->id(), $id, $version),
            'match' => $api->matchSupplierInvoice(auth()->id(), $id, $version),
            'approve' => $api->approveSupplierInvoice(auth()->id(), $id, $version),
            'post' => $api->postSupplierInvoice(auth()->id(), $id, $version),
            default => abort(404),
        };
        session()->flash('financial_message', 'تم تحديث فاتورة المورد عبر الإجراء المالي المعتمد.');
    }

    public function customerStep(int $id, int $version, string $step): void
    {
        $api = app(ReceivablesPayablesApi::class);
        match ($step) {
            'validate' => $api->validateCustomerInvoice(auth()->id(), $id, $version),
            'post' => $api->postCustomerInvoice(auth()->id(), $id, $version),
            default => abort(404),
        };
        session()->flash('financial_message', 'تم تحديث فاتورة العميل عبر الإجراء المالي المعتمد.');
    }

    public function creditStep(int $id, string $step): void
    {
        $api = app(ReceivablesPayablesApi::class);
        match ($step) {
            'approve' => $api->approveCreditNote(auth()->id(), $id),
            'post' => $api->postCreditNote(auth()->id(), $id),
            default => abort(404),
        };
        session()->flash('financial_message', 'تم تحديث الإشعار من إجراء M12 المرتبط.');
    }

    public function render()
    {
        $access = app(ReceivablesPayablesAccess::class);
        $nav = $access->navigation(auth()->id());
        abort_unless(isset($nav[$this->screen]), 403);
        $permission = $nav[$this->screen][1];
        $branches = $access->branches(auth()->id(), $permission);
        $ids = array_column($branches, 'id');
        if ($this->branch !== '') {
            abort_unless(in_array((int) $this->branch, $ids, true), 403);
            $ids = [(int) $this->branch];
        }
        $rows = match ($this->screen) {
            'supplier-invoices' => DB::table('rp_supplier_invoices')->whereIn('branch_id', $ids)->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('supplier_name', 'ilike', '%'.$this->search.'%')->orWhere('supplier_invoice_number', 'ilike', '%'.$this->search.'%')))->orderByDesc('id')->limit(100)->get(),
            'customer-invoices' => DB::table('rp_customer_invoices')->whereIn('branch_id', $ids)->when($this->search, fn ($q) => $q->where('customer_name', 'ilike', '%'.$this->search.'%'))->orderByDesc('id')->limit(100)->get(),
            'ap' => DB::table('rp_open_items')->where('party_type', 'supplier')->whereIn('branch_id', $ids)
                ->when($this->openStatus === 'open', fn ($q) => $q->where('remaining_amount', '>', 0))
                ->when($this->openStatus === 'settled', fn ($q) => $q->where('remaining_amount', '<=', 0))
                ->when($this->search !== '', fn ($q) => $q->where('source_number', 'ilike', '%'.$this->search.'%'))
                ->orderBy('due_date')->limit(200)->get(),
            'ar' => DB::table('rp_open_items')->where('party_type', 'customer')->whereIn('branch_id', $ids)
                ->when($this->openStatus === 'open', fn ($q) => $q->where('remaining_amount', '>', 0))
                ->when($this->openStatus === 'settled', fn ($q) => $q->where('remaining_amount', '<=', 0))
                ->when($this->search !== '', fn ($q) => $q->where('source_number', 'ilike', '%'.$this->search.'%'))
                ->orderBy('due_date')->limit(200)->get(),
            'credit-notes' => DB::table('rp_credit_notes')->whereIn('branch_id', $ids)->orderByDesc('id')->limit(100)->get(),
            'aging' => DB::table('rp_open_items')->whereIn('branch_id', $ids)->where('remaining_amount', '>', 0)
                ->when(in_array($this->partyType, ['supplier', 'customer'], true), fn ($q) => $q->where('party_type', $this->partyType))
                ->when($this->search !== '', fn ($q) => $q->where('source_number', 'ilike', '%'.$this->search.'%'))
                ->orderBy('due_date')->limit(300)->get(),
            default => collect(),
        };

        $agingBuckets = [
            'current' => ['label' => 'غير مستحق / جاري', 'amount' => '0.00', 'count' => 0, 'tone' => 'emerald'],
            'b1_30' => ['label' => 'متأخر 1 – 30 يوم', 'amount' => '0.00', 'count' => 0, 'tone' => 'blue'],
            'b31_60' => ['label' => 'متأخر 31 – 60 يوم', 'amount' => '0.00', 'count' => 0, 'tone' => 'amber'],
            'b61_90' => ['label' => 'متأخر 61 – 90 يوم', 'amount' => '0.00', 'count' => 0, 'tone' => 'orange'],
            'b90_plus' => ['label' => 'متأخر أكثر من 90 يوم', 'amount' => '0.00', 'count' => 0, 'tone' => 'red'],
        ];

        $totals = ['original' => '0.00', 'settled' => '0.00', 'credited' => '0.00', 'remaining' => '0.00', 'overdue' => '0.00'];
        if (in_array($this->screen, ['ap', 'ar', 'aging'], true)) {
            $today = now('Africa/Cairo')->startOfDay();
            $filteredRows = collect();
            foreach ($rows as $r) {
                $daysOverdue = (int) max(0, $today->diffInDays(CarbonImmutable::parse($r->due_date), false) * -1);
                $r->days_overdue = $daysOverdue;
                $bucketKey = match (true) {
                    $daysOverdue === 0 => 'current',
                    $daysOverdue <= 30 => 'b1_30',
                    $daysOverdue <= 60 => 'b31_60',
                    $daysOverdue <= 90 => 'b61_90',
                    default => 'b90_plus',
                };
                $r->aging_bucket = $bucketKey;
                $r->aging_label = $agingBuckets[$bucketKey]['label'];

                $rem = (string) ($r->remaining_amount ?? '0.00');
                $agingBuckets[$bucketKey]['amount'] = bcadd($agingBuckets[$bucketKey]['amount'], $rem, 2);
                $agingBuckets[$bucketKey]['count']++;

                if ($this->agingBucket !== '' && $this->agingBucket !== $bucketKey) {
                    continue;
                }
                $filteredRows->push($r);
                $totals['original'] = bcadd($totals['original'], (string) ($r->original_amount ?? '0.00'), 2);
                $totals['settled'] = bcadd($totals['settled'], (string) ($r->settled_amount ?? '0.00'), 2);
                $totals['credited'] = bcadd($totals['credited'], (string) ($r->credited_amount ?? '0.00'), 2);
                $totals['remaining'] = bcadd($totals['remaining'], $rem, 2);
                if ($daysOverdue > 0) {
                    $totals['overdue'] = bcadd($totals['overdue'], $rem, 2);
                }
            }
            $rows = $filteredRows;
        }

        $customers = $this->screen === 'customer-invoices' ? app(CustomersApi::class)->searchCustomers(new SearchCustomersRequest(auth()->id(), mb_substr($this->customerSearch, 0, 150), 30)) : [];
        $invoiceable = $this->screen === 'customer-invoices' ? $this->invoiceableLines() : [];
        $creditInvoices = [];
        if ($this->screen === 'credit-notes') {
            $table = ($this->form['kind'] ?? 'supplier') === 'customer' ? 'rp_customer_invoices' : 'rp_supplier_invoices';
            $nameColumn = $table === 'rp_customer_invoices' ? 'customer_name' : 'supplier_name';
            $creditInvoices = DB::table($table)->whereIn('branch_id', $ids)->whereNotIn('status', ['draft', 'cancelled'])
                ->orderByDesc('id')->limit(200)->get(['id', $nameColumn.' as party', 'invoice_date', 'total_amount'])->all();
        }
        $suppliers = $this->screen === 'supplier-invoices' ? app(SuppliersApi::class)->searchSuppliers(new SearchSuppliersRequest(auth()->id(), mb_substr($this->supplierSearch, 0, 150), 30)) : [];
        $receipts = $this->screen === 'supplier-invoices' ? $this->invoiceableReceipts() : [];
        $canSelfApproveCredit = $this->screen === 'credit-notes' && app(OrganizationApi::class)->allowsSelfApproval(auth()->id(), 'treasury_ar_ap');

        return view('receivables-payables.index', compact('access', 'nav', 'branches', 'rows', 'totals', 'agingBuckets', 'customers', 'invoiceable', 'suppliers', 'receipts', 'creditInvoices', 'canSelfApproveCredit'))->layout('layouts::app', ['title' => $nav[$this->screen][0]]);
    }
}
