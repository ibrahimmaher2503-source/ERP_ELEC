<?php

namespace App\Modules\Purchasing\Presentation;

use App\Application\PostPurchaseReceipt;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\Purchasing\Application\PurchasingAccess;
use App\Modules\Purchasing\Application\SupplierOffers;
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
        $this->form['lines'] = DB::table('purchasing_receipt_lines')->where('receipt_id', $doc->id)->orderBy('id')->get(['order_line_id', 'quantity', 'serials'])->map(fn ($l) => ['order_line_id' => $l->order_line_id, 'quantity' => $l->quantity, 'serial_text' => implode("\n", json_decode($l->serials, true, 512, JSON_THROW_ON_ERROR))])->all();
    }

    public function loadOrder(): void
    {
        abort_if($this->receipt !== null, 422);
        $doc = app(PurchaseOrders::class)->record(auth()->id(), (int) $this->form['order_id']);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'receipt_create', $doc->branch_id);
        abort_unless($doc->status === 'approved', 422);
        $this->form['lines'] = DB::table('purchasing_order_lines')->where('order_id', $doc->id)->orderBy('id')->get()->map(function ($line) {
            $allocated = (string) DB::table('purchasing_receipt_lines')->where('order_line_id', $line->id)->sum('quantity');

            return ['order_line_id' => $line->id, 'quantity' => bcsub($line->quantity, $allocated, 8), 'serial_text' => ''];
        })->filter(fn ($line) => bccomp($line['quantity'], '0', 8) > 0)->values()->all();
        if ($this->form['lines'] === []) {
            app(SupplierOffers::class)->fail('كمية الأمر مخصصة بالكامل لاستلامات مرحلة أو مسودات أخرى.');
        }
        $this->form['warehouse_id'] = '';
    }

    public function removeLine(int $i): void
    {
        unset($this->form['lines'][$i]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(): void
    {
        $data = $this->form;
        foreach ($data['lines'] as &$line) {
            $line['serials'] = preg_split('/\R/u', $line['serial_text'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        }
        unset($line);
        $id = app(PurchaseReceiving::class)->save(auth()->id(), array_replace($data, ['creation_key' => $this->creationKey]), $this->receipt, $this->version);
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

    public function post(): void
    {
        $this->document();
        app(PostPurchaseReceipt::class)->execute(auth()->id(), $this->receipt, $this->version);
        $this->loadDocument();
        session()->flash('receipt_posted', 'تم ترحيل المخزون وFIFO وGRNI معًا.');
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
