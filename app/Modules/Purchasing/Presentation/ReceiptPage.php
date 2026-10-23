<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\Purchasing\Application\PurchasingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ReceiptPage extends Component
{
    #[Locked]
    public ?int $receipt = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey = '';

    public array $form = [];

    public function mount(?int $receipt = null): void
    {
        $this->receipt = $receipt;
        $this->creationKey = (string) Str::uuid();
        $this->form = ['order_id' => '', 'warehouse_id' => '', 'receipt_date' => now('Africa/Cairo')->toDateString(), 'delivery_reference' => '', 'notes' => '', 'lines' => []];
        if ($receipt) {
            $this->loadDocument();
        } else {
            abort_unless(app(PurchasingAccess::class)->branches(auth()->id(), 'receipt_create'), 403);
            if (request()->filled('order')) {
                $input = request()->validate(['order' => 'required|integer|min:1']);
                $this->form['order_id'] = $input['order'];
                $this->loadOrder();
            }
        }
    }

    private function document(): object
    {
        $row = DB::table('purchasing_receipts')->find($this->receipt);
        abort_unless($row, 404);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'receipt_view', $row->branch_id);
        abort_unless(in_array($row->warehouse_id, array_column(app(PurchaseReceiving::class)->warehouses(auth()->id()), 'id'), true), 403, 'نطاق المستودع غير متاح.');

        return $row;
    }

    private function loadDocument(): void
    {
        $doc = $this->document();
        $this->version = $doc->version;
        $this->creationKey = $doc->creation_key;
        foreach (array_keys($this->form) as $k) {
            if ($k !== 'lines') {
                $this->form[$k] = $doc->$k;
            }
        }
        $this->form['lines'] = DB::table('purchasing_receipt_lines')->where('receipt_id', $doc->id)->orderBy('id')->get(['order_line_id', 'quantity'])->map(fn ($l) => (array) $l)->all();
    }

    public function loadOrder(): void
    {
        abort_if($this->receipt !== null, 422);
        $doc = app(PurchaseOrders::class)->record(auth()->id(), (int) $this->form['order_id']);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'receipt_create', $doc->branch_id);
        abort_unless($doc->status === 'approved', 422);
        $this->form['lines'] = DB::table('purchasing_order_lines')->where('order_id', $doc->id)->orderBy('id')->get()->map(fn ($l) => ['order_line_id' => $l->id, 'quantity' => $l->quantity])->all();
        $this->form['warehouse_id'] = '';
    }

    public function removeLine(int $i): void
    {
        unset($this->form['lines'][$i]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(): void
    {
        $id = app(PurchaseReceiving::class)->save(auth()->id(), array_replace($this->form, ['creation_key' => $this->creationKey]), $this->receipt, $this->version);
        $this->receipt = $id;
        $this->version = (int) DB::table('purchasing_receipts')->where('id', $id)->value('version');
        $this->redirectRoute('purchasing.receipts.show', ['receipt' => $id], navigate: true);
    }

    public function submit(): void
    {
        $this->document();
        app(PurchaseReceiving::class)->submit(auth()->id(), $this->receipt, $this->version);
        $this->loadDocument();
    }

    public function render()
    {
        $access = app(PurchasingAccess::class);
        $doc = $this->receipt ? $this->document() : null;
        $editable = ! $doc || ($doc->status === 'draft' && $doc->created_by === auth()->id() && $access->allows(auth()->id(), 'receipt_create', $doc->branch_id));
        $branchIds = array_intersect(array_column($access->branches(auth()->id(), 'receipt_create'), 'id'), array_column($access->branches(auth()->id(), 'order_view'), 'id'));
        $orders = DB::table('purchasing_orders')->whereIn('branch_id', $branchIds)->where('status', 'approved')->orderByDesc('id')->limit(200)->get();
        $selected = $orders->firstWhere('id', (int) $this->form['order_id']);
        if ($doc && ! $selected) {
            $selected = app(PurchaseOrders::class)->record(auth()->id(), $doc->order_id);
        }
        $warehouses = collect(app(PurchaseReceiving::class)->warehouses(auth()->id()))->when($selected, fn ($q) => $q->where('branch_id', $selected->branch_id))->all();
        $source = $selected ? DB::table('purchasing_order_lines')->where('order_id', $selected->id)->get() : collect();

        return view('purchasing.receipt', compact('doc', 'editable', 'orders', 'warehouses', 'source'))->layout('layouts::app', ['title' => 'مسودة استلام مشتريات']);
    }
}
