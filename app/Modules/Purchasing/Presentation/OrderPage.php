<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SearchSuppliersRequest;
use App\Modules\Parties\Application\Contracts\SupplierContractException;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Purchasing\Application\OrderLineImport;
use App\Modules\Purchasing\Application\PurchaseOrderProjection;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\Purchasing\Application\PurchasingAccess;
use App\Modules\Purchasing\Application\SupplierOffers;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

final class OrderPage extends Component
{
    use WithFileUploads;

    public $lineFile = null;

    #[Locked]
    public array $linePreview = [];

    #[Locked]
    public ?int $previewBranch = null;

    public function lineTemplate()
    {
        abort_if(! empty($this->form['award_id']), 403);
        $import = app(OrderLineImport::class);
        $import->authorize(auth()->id(), (int) $this->form['branch_id'], $this->order);
        $path = app(WorkbookCodec::class)->template('purchasing-direct-order-lines', 'M05-v1', $import->columns(), []);

        return response()->download($path, 'purchase-order-lines-template.xlsx')->setPrivate()->deleteFileAfterSend();
    }

    public function previewLines(): void
    {
        $this->linePreview = [];
        try {
            abort_if(! empty($this->form['award_id']), 403);
            $this->validate(['lineFile' => 'required|file|max:10240']);
            $this->previewBranch = (int) $this->form['branch_id'];
            $this->linePreview = app(OrderLineImport::class)->preview(auth()->id(), $this->previewBranch, $this->order, $this->lineFile);
        } finally {
            $this->lineFile?->delete();
            $this->lineFile = null;
        }
    }

    public function applyLines(): void
    {
        abort_unless(empty($this->form['award_id']) && $this->previewBranch === (int) $this->form['branch_id'] && $this->linePreview && ! collect($this->linePreview)->contains(fn ($r) => $r['error'] !== null), 422);
        app(OrderLineImport::class)->authorize(auth()->id(), $this->previewBranch, $this->order);
        $this->form['lines'] = array_map(fn ($r) => array_intersect_key($r, array_flip(['product_id', 'quantity', 'unit_price', 'discount_amount'])), $this->linePreview);
        $this->linePreview = [];
    }

    #[Locked]
    public ?int $order = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey = '';

    public array $form = [];

    public string $search = '';

    public string $supplierSearch = '';

    public string $reason = '';

    public string $tab = 'lines';

    #[Locked]
    public string $amendmentKey = '';

    public array $amendmentQuantities = [];

    public string $amendmentReason = '';

    public function mount(?int $order = null): void
    {
        $this->order = $order;
        $this->creationKey = (string) Str::uuid();
        $this->amendmentKey = (string) Str::uuid();
        $this->form = ['branch_id' => '', 'supplier_id' => '', 'award_id' => '', 'order_date' => now('Africa/Cairo')->toDateString(), 'expected_date' => '', 'currency' => 'EGP', 'tax_mode' => 'exclusive', 'reported_tax' => '', 'payment_terms' => '', 'notes' => '', 'lines' => []];
        if ($order) {
            $this->loadDocument();
        } else {
            abort_unless(app(PurchasingAccess::class)->branches(auth()->id(), 'order_create'), 403);
            if (request()->filled('award')) {
                $input = request()->validate(['award' => 'required|integer|min:1']);
                $this->form['award_id'] = $input['award'];
                $this->loadAward();
            }
        }
    }

    private function loadDocument(): void
    {
        $doc = app(PurchaseOrders::class)->record(auth()->id(), $this->order);
        $this->version = $doc->version;
        $this->creationKey = $doc->creation_key;
        foreach (array_keys($this->form) as $key) {
            if ($key !== 'lines') {
                $this->form[$key] = $doc->$key;
            }
        }
        $this->form['lines'] = DB::table('purchasing_order_lines')->where('order_id', $doc->id)->orderBy('id')->get()->map(fn ($l) => ['product_id' => $l->product_id, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'discount_amount' => $l->discount_amount])->all();
    }

    public function loadAward(): void
    {
        abort_if($this->order !== null, 422);
        $award = DB::table('purchasing_awards')->where('status', 'approved')->find((int) $this->form['award_id']);
        abort_unless($award, 404);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'order_create', $award->branch_id);
        $offer = app(SupplierOffers::class)->record(auth()->id(), $award->offer_id);
        $this->form['branch_id'] = $award->branch_id;
        $this->form['supplier_id'] = $offer->supplier_id;
        foreach (['currency', 'reported_tax', 'payment_terms'] as $key) {
            $this->form[$key] = $offer->$key;
        }
        $this->form['lines'] = DB::table('purchasing_offer_lines as l')->join('purchasing_rfq_product_lines as r', 'r.id', '=', 'l.rfq_line_id')->where('l.offer_id', $offer->id)->orderBy('l.id')->get(['r.product_id', 'l.quantity', 'l.unit_price', 'l.discount_amount'])->map(fn ($l) => (array) $l)->all();
    }

    public function addLine(): void
    {
        abort_if(count($this->form['lines']) >= 100, 422);
        $this->form['lines'][] = ['product_id' => '', 'quantity' => '1', 'unit_price' => '0', 'discount_amount' => '0'];
    }

    public function removeLine(int $i): void
    {
        unset($this->form['lines'][$i]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(): void
    {
        $id = app(PurchaseOrders::class)->save(auth()->id(), array_replace($this->form, ['creation_key' => $this->creationKey]), $this->order, $this->version);
        $this->order = $id;
        $this->version = (int) DB::table('purchasing_orders')->where('id', $id)->value('version');
        $this->redirectRoute('purchasing.orders.show', ['order' => $id], navigate: true);
    }

    public function applyAction(string $action): void
    {
        app(PurchaseOrders::class)->transition(auth()->id(), $this->order, $this->version, $action, $this->reason);
        $this->loadDocument();
    }

    public function issue(): void
    {
        abort_unless($this->order, 404);
        app(PurchaseOrders::class)->issue(auth()->id(), $this->order);
        session()->flash('purchasing_issue_message', 'تم حفظ أمر الشراء الرسمي وملف PDF الثابت.');
        $this->loadDocument();
    }

    public function requestAmendment(): void
    {
        $changes = [];
        foreach ($this->amendmentQuantities as $line => $quantity) {
            $changes[] = ['order_line_id' => (int) $line, 'quantity' => $quantity];
        }
        app(PurchaseReceiving::class)->requestAmendment(auth()->id(), $this->order, $this->amendmentKey, $this->amendmentReason, $changes);
    }

    public function render()
    {
        $access = app(PurchasingAccess::class);
        $doc = $this->order ? app(PurchaseOrders::class)->record(auth()->id(), $this->order) : null;
        $editable = ! $doc || ($doc->status === 'draft' && $doc->created_by === auth()->id() && $access->allows(auth()->id(), 'order_update', $doc->branch_id));
        $branches = $access->branches(auth()->id(), $doc ? 'order_view' : 'order_create');
        $awards = DB::table('purchasing_awards')->whereIn('branch_id', array_column($branches, 'id'))->where('status', 'approved')->orderByDesc('id')->limit(200)->get();
        if (! empty($this->form['award_id']) && ! $awards->contains('id', (int) $this->form['award_id'])) {
            $source = DB::table('purchasing_awards')->whereIn('branch_id', array_column($branches, 'id'))->where('status', 'approved')->find((int) $this->form['award_id']);
            if ($source) {
                $awards->push($source);
            }
        }
        $products = $editable ? app(ProductsApi::class)->listMasters(new ListMastersRequest(auth()->id(), search: mb_substr($this->search, 0, 200), pageSize: 100))->items : [];
        if ($editable) {
            foreach (array_unique(array_filter(array_column($this->form['lines'], 'product_id'))) as $id) {
                if (! collect($products)->contains('id', (int) $id)) {
                    $products[] = app(ProductsApi::class)->getProduct(new GetProductRequest(auth()->id(), (int) $id))->product;
                }
            }
        }
        try {
            $suppliers = $editable ? app(SuppliersApi::class)->searchSuppliers(new SearchSuppliersRequest(auth()->id(), mb_substr($this->supplierSearch, 0, 200), 50)) : [];
            if ($editable && ! empty($this->form['supplier_id']) && ! collect($suppliers)->contains('id', (int) $this->form['supplier_id'])) {
                $suppliers[] = app(SuppliersApi::class)->getSupplier(new GetSupplierRequest(auth()->id(), (int) $this->form['supplier_id']));
            }
        } catch (SupplierContractException $e) {
            abort($e->getCode(), 'صلاحية عرض الموردين مطلوبة.');
        }
        $savedLines = $doc ? DB::table('purchasing_order_lines')->where('order_id', $doc->id)->orderBy('id')->get() : collect();
        $officialIssue = $doc ? DB::table('purchasing_order_issues')->where('order_id', $doc->id)->first() : null;
        $receiving = $doc ? app(PurchaseOrderProjection::class)->query()->find($doc->id) : null;
        $history = $doc ? DB::table('purchasing_order_reviews')->where('order_id', $doc->id)->orderByDesc('id')->get() : collect();
        $amendments = $doc ? DB::table('purchasing_amendment_requests')->where('order_id', $doc->id)->orderByDesc('id')->get() : collect();
        $receiptIds = array_column($access->branches(auth()->id(), 'receipt_view'), 'id');
        $warehouseIds = array_column(app(PurchaseReceiving::class)->warehouses(auth()->id()), 'id');
        $receipts = $doc && in_array($doc->branch_id, $receiptIds, true) ? DB::table('purchasing_receipts')->where('order_id', $doc->id)->whereIn('warehouse_id', $warehouseIds)->orderByDesc('id')->get() : collect();

        return view('purchasing.order', compact('doc', 'editable', 'branches', 'awards', 'products', 'suppliers', 'savedLines', 'history', 'amendments', 'receipts', 'officialIssue', 'receiving'))->layout('layouts::app', ['title' => 'أمر الشراء']);
    }
}
