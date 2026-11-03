<?php

namespace App\Modules\ReceivablesPayables\Presentation;

use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ReceivablesPayables\Application\ReceivablesPayablesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class FinancialOperationsPage extends Component
{
    #[Locked]
    public string $screen;

    public string $search = '';

    public string $branch = '';

    #[Locked]
    public string $creationKey = '';

    public array $form = [];

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
            'tax_mode' => 'exclusive', 'order_line_id' => '', 'receipt_line_id' => '', 'delivery_line_id' => '', 'customer_id' => '',
            'quantity' => '', 'unit_price' => '', 'discount_amount' => '0.00', 'kind' => 'supplier', 'invoice_id' => '',
            'note_date' => $today, 'reason' => '', 'amount' => ''];
    }

    public function createSupplier(): void
    {
        $id = app(ReceivablesPayablesApi::class)->createSupplierInvoice(auth()->id(), ['creation_key' => $this->creationKey,
            'supplier_id' => $this->form['supplier_id'], 'supplier_invoice_number' => $this->form['supplier_invoice_number'],
            'invoice_date' => $this->form['invoice_date'], 'due_date' => $this->form['due_date'], 'tax_mode' => $this->form['tax_mode'],
            'lines' => [['order_line_id' => $this->form['order_line_id'], 'quantity' => $this->form['quantity'],
                'unit_price' => $this->form['unit_price'], 'discount_amount' => $this->form['discount_amount'],
                'matches' => [['receipt_line_id' => $this->form['receipt_line_id'], 'quantity' => $this->form['quantity']]]]]]);
        $this->resetForm();
        session()->flash('financial_message', 'تم إنشاء مسودة فاتورة المورد #'.$id.' من الاستلام المرحّل المحدد.');
    }

    public function createCustomer(): void
    {
        $id = app(ReceivablesPayablesApi::class)->createCustomerInvoice(auth()->id(), ['creation_key' => $this->creationKey,
            'customer_id' => $this->form['customer_id'], 'invoice_date' => $this->form['invoice_date'], 'due_date' => $this->form['due_date'],
            'lines' => [['delivery_line_id' => $this->form['delivery_line_id'], 'quantity' => $this->form['quantity']]]]);
        $this->resetForm();
        session()->flash('financial_message', 'تم إنشاء مسودة فاتورة العميل #'.$id.' من التسليم المؤكد المحدد.');
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
            'ap' => DB::table('rp_open_items')->where('party_type', 'supplier')->whereIn('branch_id', $ids)->orderBy('due_date')->limit(200)->get(),
            'ar' => DB::table('rp_open_items')->where('party_type', 'customer')->whereIn('branch_id', $ids)->orderBy('due_date')->limit(200)->get(),
            'credit-notes' => DB::table('rp_credit_notes')->whereIn('branch_id', $ids)->orderByDesc('id')->limit(100)->get(),
            'aging' => DB::table('rp_open_items')->whereIn('branch_id', $ids)->where('remaining_amount', '>', 0)->orderBy('due_date')->limit(300)->get(),
            default => collect(),
        };
        $totals = ['original' => $rows->sum('original_amount'), 'remaining' => $rows->sum('remaining_amount')];

        return view('receivables-payables.index', compact('access', 'nav', 'branches', 'rows', 'totals'))->layout('layouts::app', ['title' => $nav[$this->screen][0]]);
    }
}
