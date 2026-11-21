<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SearchSuppliersRequest;
use App\Modules\Parties\Application\Contracts\SupplierContractException;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Parties\Application\SupplierAccess;
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
use Livewire\Attributes\Url;
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

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public bool $editing = false;

    #[Locked]
    public string $amendmentKey = '';

    public array $amendmentQuantities = [];

    public string $amendmentReason = '';

    public function mount(?int $order = null): void
    {
        $this->order = $order;
        $this->creationKey = (string) Str::uuid();
        $this->amendmentKey = (string) Str::uuid();
        if (! in_array($this->tab, ['overview', 'review', 'issue', 'receipts', 'amendments'], true)) {
            $this->tab = 'overview';
        }
        $this->form = [
            'branch_id' => '',
            'supplier_id' => '',
            'award_id' => '',
            'order_date' => now('Africa/Cairo')->toDateString(),
            'expected_date' => '',
            'currency' => 'EGP',
            'tax_mode' => 'exclusive',
            'reported_tax' => '',
            'payment_terms' => '',
            'notes' => '',
            'lines' => [],
        ];
        if ($order) {
            $this->loadDocument();
            $this->editing = request()->boolean('edit');
        } else {
            abort_unless(app(PurchasingAccess::class)->branches(auth()->id(), 'order_create'), 403);
            $this->editing = true;
            if (request()->filled('award')) {
                $input = request()->validate(['award' => 'required|integer|min:1']);
                $this->form['award_id'] = $input['award'];
                $this->loadAward();
            } else {
                $this->form['lines'] = [$this->emptyLine()];
            }
        }
    }

    private function emptyLine(): array
    {
        return [
            'category_id' => '',
            'brand_id' => '',
            'product_id' => '',
            'quantity' => '1',
            'unit_price' => '0',
            'discount_amount' => '0',
            'spec_model' => '',
            'spec_type' => '',
            'spec_ampere' => '',
            'spec_kilo' => '',
            'serial_reference' => '',
            'accessories_note' => '',
        ];
    }

    private function hydrateLineProductMeta(array $line): array
    {
        $line = array_replace($this->emptyLine(), $line);
        if (! empty($line['product_id'])) {
            try {
                $p = app(ProductsApi::class)->getProduct(new GetProductRequest(auth()->id(), (int) $line['product_id']))->product;
                $specs = app(ProductsApi::class)->extractGuidedSpec($p);
                $line['category_id'] = (string) ($p['category_id'] ?? '');
                $line['brand_id'] = (string) ($p['brand_id'] ?? '');
                $line['spec_model'] = trim((string) ($line['spec_model'] ?? '')) ?: $specs['spec_model'];
                $line['spec_type'] = trim((string) ($line['spec_type'] ?? '')) ?: $specs['spec_type'];
                $line['spec_ampere'] = trim((string) ($line['spec_ampere'] ?? '')) ?: $specs['spec_ampere'];
                $line['spec_kilo'] = trim((string) ($line['spec_kilo'] ?? '')) ?: $specs['spec_kilo'];
            } catch (\Throwable) {
            }
        }

        return $line;
    }

    public function updatedForm($value, $key): void
    {
        if (preg_match('/^lines\.(\d+)\.product_id$/D', (string) $key, $m)) {
            $i = (int) $m[1];
            if (! isset($this->form['lines'][$i])) {
                return;
            }
            if (empty($value)) {
                $this->form['lines'][$i]['spec_model'] = '';
                $this->form['lines'][$i]['spec_type'] = '';
                $this->form['lines'][$i]['spec_ampere'] = '';
                $this->form['lines'][$i]['spec_kilo'] = '';

                return;
            }
            try {
                $p = app(ProductsApi::class)->getProduct(new GetProductRequest(auth()->id(), (int) $value))->product;
                $specs = app(ProductsApi::class)->extractGuidedSpec($p);
                $this->form['lines'][$i]['category_id'] = (string) ($p['category_id'] ?? '');
                $this->form['lines'][$i]['brand_id'] = (string) ($p['brand_id'] ?? '');
                $this->form['lines'][$i]['spec_model'] = $specs['spec_model'];
                $this->form['lines'][$i]['spec_type'] = $specs['spec_type'];
                $this->form['lines'][$i]['spec_ampere'] = $specs['spec_ampere'];
                $this->form['lines'][$i]['spec_kilo'] = $specs['spec_kilo'];
            } catch (\Throwable) {
            }
        }
    }

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
        $this->form['lines'] = array_map(fn ($r) => $this->hydrateLineProductMeta([
            'product_id' => $r['product_id'],
            'quantity' => PurchaseOrderDisplay::inputDecimal((string) $r['quantity']),
            'unit_price' => PurchaseOrderDisplay::inputDecimal((string) $r['unit_price']),
            'discount_amount' => PurchaseOrderDisplay::inputDecimal((string) $r['discount_amount']) ?: '0',
        ]), $this->linePreview);
        $this->linePreview = [];
        session()->flash('status', 'تم تطبيق بنود الاستيراد على النموذج؛ اضغط حفظ لتثبيت المسودة.');
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
        $this->form['reported_tax'] = $doc->reported_tax !== null ? PurchaseOrderDisplay::inputDecimal((string) $doc->reported_tax) : '';
        $this->form['lines'] = DB::table('purchasing_order_lines')->where('order_id', $doc->id)->orderBy('id')->get()->map(fn ($l) => $this->hydrateLineProductMeta([
            'product_id' => $l->product_id,
            'quantity' => PurchaseOrderDisplay::inputDecimal((string) $l->quantity),
            'unit_price' => PurchaseOrderDisplay::inputDecimal((string) $l->unit_price),
            'discount_amount' => PurchaseOrderDisplay::inputDecimal((string) $l->discount_amount) ?: '0',
            'spec_model' => $l->spec_model ?? '',
            'spec_type' => $l->spec_type ?? '',
            'spec_ampere' => $l->spec_ampere ?? '',
            'spec_kilo' => $l->spec_kilo ?? '',
            'serial_reference' => $l->serial_reference ?? '',
            'accessories_note' => $l->accessories_note ?? '',
        ]))->all();
    }

    public function loadAward(): void
    {
        abort_if($this->order !== null && (! $this->editing || (string) app(PurchaseOrders::class)->record(auth()->id(), $this->order)->status !== 'draft'), 422);
        $award = DB::table('purchasing_awards')->where('status', 'approved')->find((int) $this->form['award_id']);
        abort_unless($award, 404);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'order_create', $award->branch_id);
        $offer = app(SupplierOffers::class)->record(auth()->id(), $award->offer_id);
        $this->form['branch_id'] = $award->branch_id;
        $this->form['supplier_id'] = $offer->supplier_id;
        $this->form['currency'] = $offer->currency;
        $this->form['reported_tax'] = $offer->reported_tax !== null ? PurchaseOrderDisplay::inputDecimal((string) $offer->reported_tax) : '';
        $this->form['payment_terms'] = $offer->payment_terms ?? '';
        $this->form['lines'] = DB::table('purchasing_offer_lines as l')
            ->join('purchasing_rfq_product_lines as r', 'r.id', '=', 'l.rfq_line_id')
            ->where('l.offer_id', $offer->id)
            ->orderBy('l.id')
            ->get(['r.product_id', 'l.quantity', 'l.unit_price', 'l.discount_amount'])
            ->map(fn ($l) => $this->hydrateLineProductMeta([
                'product_id' => $l->product_id,
                'quantity' => PurchaseOrderDisplay::inputDecimal((string) $l->quantity),
                'unit_price' => PurchaseOrderDisplay::inputDecimal((string) $l->unit_price),
                'discount_amount' => PurchaseOrderDisplay::inputDecimal((string) $l->discount_amount) ?: '0',
            ]))->all();
    }

    public function startEdit(): void
    {
        $this->loadDocument();
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->loadDocument();
        $this->editing = false;
    }

    public function setTab(string $tab): void
    {
        abort_unless(in_array($tab, ['overview', 'review', 'issue', 'receipts', 'amendments'], true), 422);
        $this->tab = $tab;
    }

    public function addLine(): void
    {
        abort_if(! empty($this->form['award_id']), 403);
        abort_if(count($this->form['lines']) >= 100, 422);
        $this->form['lines'][] = $this->emptyLine();
    }

    public function moveLine(int $i, int $delta): void
    {
        abort_if(! empty($this->form['award_id']), 403);
        abort_unless(in_array($delta, [-1, 1], true), 422);
        $target = $i + $delta;
        if (isset($this->form['lines'][$i], $this->form['lines'][$target])) {
            [$this->form['lines'][$i], $this->form['lines'][$target]] = [$this->form['lines'][$target], $this->form['lines'][$i]];
        }
    }

    public function duplicateLine(int $i): void
    {
        abort_if(! empty($this->form['award_id']), 403);
        abort_if(count($this->form['lines']) >= 100, 422);
        if (isset($this->form['lines'][$i])) {
            array_splice($this->form['lines'], $i + 1, 0, [$this->form['lines'][$i]]);
        }
    }

    public function removeLine(int $i): void
    {
        abort_if(! empty($this->form['award_id']), 403);
        unset($this->form['lines'][$i]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(): void
    {
        $id = app(PurchaseOrders::class)->save(auth()->id(), array_replace($this->form, ['creation_key' => $this->creationKey]), $this->order, $this->version);
        $this->order = $id;
        $this->version = (int) DB::table('purchasing_orders')->where('id', $id)->value('version');
        $this->editing = false;
        session()->flash('status', 'تم حفظ مسودة أمر الشراء بنجاح.');
        $this->redirectRoute('purchasing.orders.show', ['order' => $id], navigate: true);
    }

    public function applyAction(string $action): void
    {
        app(PurchaseOrders::class)->transition(auth()->id(), $this->order, $this->version, $action, $this->reason);
        $this->reason = '';
        $this->editing = false;
        $messages = [
            'submit' => 'تم إرسال أمر الشراء للاعتماد المستقل.',
            'approve' => 'تم اعتماد أمر الشراء داخليًا.',
            'reject' => 'تم رفض أمر الشراء وتسجيل السبب.',
            'reopen' => 'تمت إعادة أمر الشراء إلى حالة المسودة.',
        ];
        session()->flash('status', $messages[$action] ?? 'تم تحديث حالة أمر الشراء.');
        $this->loadDocument();
    }

    public function issue(): void
    {
        abort_unless($this->order, 404);
        app(PurchaseOrders::class)->issue(auth()->id(), $this->order);
        session()->flash('status', 'تم إصدار أمر الشراء الرسمي وحفظ الرقم والضريبة وملف PDF الثابت.');
        $this->tab = 'issue';
        $this->loadDocument();
    }

    public function requestAmendment(): void
    {
        $changes = [];
        foreach ($this->amendmentQuantities as $line => $quantity) {
            if (trim((string) $quantity) !== '') {
                $changes[] = ['order_line_id' => (int) $line, 'quantity' => $quantity];
            }
        }
        app(PurchaseReceiving::class)->requestAmendment(auth()->id(), $this->order, $this->amendmentKey, $this->amendmentReason, $changes);
        $this->amendmentReason = '';
        $this->amendmentQuantities = [];
        $this->amendmentKey = (string) Str::uuid();
        $this->tab = 'amendments';
        session()->flash('status', 'تم تسجيل طلب التعديل دون تغيير نسخة الأمر المعتمدة.');
    }

    private function isValidDecimal(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D', $value) === 1;
    }

    private function calculateDraft(array $lines): array
    {
        $lineCalc = [];
        $gross = '0.00000000';
        $discounts = '0.00000000';
        $net = '0.00000000';
        $complete = $lines !== [];
        foreach ($lines as $i => $l) {
            $q = (string) ($l['quantity'] ?? '');
            $p = (string) ($l['unit_price'] ?? '');
            $d = (string) ($l['discount_amount'] ?? '0');
            if ($this->isValidDecimal($q) && $this->isValidDecimal($p) && $this->isValidDecimal($d)) {
                $lineGross = bcmul($q, $p, 8);
                $lineNet = bcsub($lineGross, $d, 8);
                $lineCalc[$i] = ['gross' => $lineGross, 'discount' => $d, 'net' => $lineNet];
                $gross = bcadd($gross, $lineGross, 8);
                $discounts = bcadd($discounts, $d, 8);
                $net = bcadd($net, $lineNet, 8);
            } else {
                $lineCalc[$i] = ['gross' => null, 'discount' => null, 'net' => null];
                $complete = false;
            }
        }

        return ['lines' => $lineCalc, 'complete' => $complete, 'gross' => $complete ? $gross : null, 'discounts' => $complete ? $discounts : null, 'net' => $complete ? $net : null];
    }

    public function render()
    {
        $actor = auth()->id();
        $access = app(PurchasingAccess::class);
        $doc = $this->order ? app(PurchaseOrders::class)->record($actor, $this->order) : null;
        $editable = ! $doc || ($doc->status === 'draft' && $doc->created_by === $actor && $access->allows($actor, 'order_update', $doc->branch_id));
        $isEditing = (! $doc) || ($editable && $this->editing);
        $branches = $access->branches($actor, $doc ? 'order_view' : 'order_create');

        $awards = collect();
        $products = [];
        $categories = [];
        $brands = [];
        $suppliers = [];
        $draftCalc = ['lines' => [], 'complete' => false, 'gross' => null, 'discounts' => null, 'net' => null];

        if ($isEditing) {
            $awards = DB::table('purchasing_awards as a')
                ->leftJoin('purchasing_offers as o', 'o.id', '=', 'a.offer_id')
                ->whereIn('a.branch_id', array_column($branches, 'id'))
                ->where('a.status', 'approved')
                ->orderByDesc('a.id')
                ->limit(200)
                ->get(['a.*', 'o.supplier_name', 'o.net_amount as offer_net', 'o.currency as offer_currency']);
            if (! empty($this->form['award_id']) && ! $awards->contains('id', (int) $this->form['award_id'])) {
                $sourceAward = DB::table('purchasing_awards as a')
                    ->leftJoin('purchasing_offers as o', 'o.id', '=', 'a.offer_id')
                    ->whereIn('a.branch_id', array_column($branches, 'id'))
                    ->where('a.status', 'approved')
                    ->where('a.id', (int) $this->form['award_id'])
                    ->first(['a.*', 'o.supplier_name', 'o.net_amount as offer_net', 'o.currency as offer_currency']);
                if ($sourceAward) {
                    $awards->push($sourceAward);
                }
            }

            $productsApi = app(ProductsApi::class);
            $guided = $productsApi->guidedCatalog($actor, mb_substr($this->search, 0, 200));
            $categories = $guided['categories'];
            $brands = $guided['brands'];
            $products = $guided['products'];
            foreach ($this->form['lines'] as $line) {
                $catId = ! empty($line['category_id']) ? (int) $line['category_id'] : null;
                $brId = ! empty($line['brand_id']) ? (int) $line['brand_id'] : null;
                if ($catId || $brId) {
                    foreach ($productsApi->guidedCatalog($actor, mb_substr($this->search, 0, 200), $catId, $brId)['products'] as $fp) {
                        if (! collect($products)->contains('id', (int) $fp['id'])) {
                            $products[] = $fp;
                        }
                    }
                }
            }
            foreach (array_unique(array_filter(array_column($this->form['lines'], 'product_id'))) as $id) {
                if (! collect($products)->contains('id', (int) $id)) {
                    $p = $productsApi->getProduct(new GetProductRequest($actor, (int) $id))->product;
                    $products[] = $p + $productsApi->extractGuidedSpec($p);
                }
            }
            try {
                $suppliers = app(SuppliersApi::class)->searchSuppliers(new SearchSuppliersRequest($actor, mb_substr($this->supplierSearch, 0, 200), 50));
                if (! empty($this->form['supplier_id']) && ! collect($suppliers)->contains('id', (int) $this->form['supplier_id'])) {
                    $suppliers[] = app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, (int) $this->form['supplier_id']));
                }
            } catch (SupplierContractException $e) {
                abort($e->getCode(), 'صلاحية عرض الموردين مطلوبة.');
            }
            $draftCalc = $this->calculateDraft($this->form['lines']);
        }

        $savedLines = $doc ? DB::table('purchasing_order_lines')->where('order_id', $doc->id)->orderBy('id')->get() : collect();
        $savedLinesById = $savedLines->keyBy('id');
        $officialIssue = $doc ? DB::table('purchasing_order_issues')->where('order_id', $doc->id)->first() : null;
        $officialTax = $officialIssue ? json_decode($officialIssue->tax_snapshot, true) : null;
        $receiving = $doc ? app(PurchaseOrderProjection::class)->query()->find($doc->id) : null;
        $history = $doc ? DB::table('purchasing_order_reviews')->where('order_id', $doc->id)->orderByDesc('id')->get() : collect();
        $amendments = $doc ? DB::table('purchasing_amendment_requests')->where('order_id', $doc->id)->orderByDesc('id')->get() : collect();

        $receivedByLine = [];
        $unitNames = [];
        $awardInfo = null;
        $branchName = $doc ? (collect($branches)->firstWhere('id', $doc->branch_id)['name'] ?? '—') : '—';
        $receipts = collect();

        if ($doc) {
            $receivedByLine = DB::table('purchasing_receipt_lines as rl')
                ->join('purchasing_receipts as r', 'r.id', '=', 'rl.receipt_id')
                ->where('r.order_id', $doc->id)
                ->where('r.status', 'posted')
                ->selectRaw('rl.order_line_id, coalesce(sum(rl.quantity),0)::text as qty')
                ->groupBy('rl.order_line_id')
                ->pluck('qty', 'order_line_id')
                ->all();

            try {
                $unitNames = collect(app(ProductsApi::class)->listMasters(new ListMastersRequest($actor, 'units', activeOnly: false, pageSize: 100))->items)->pluck('name', 'id')->all();
            } catch (\Throwable) {
                $unitNames = [];
            }

            if ($doc->award_id) {
                $awardInfo = DB::table('purchasing_awards as a')
                    ->leftJoin('purchasing_offers as o', 'o.id', '=', 'a.offer_id')
                    ->where('a.id', $doc->award_id)
                    ->first(['a.id', 'a.offer_id', 'o.rfq_id', 'o.supplier_reference']);
            }

            $receiptIds = array_column($access->branches($actor, 'receipt_view'), 'id');
            $warehouseIds = array_column(app(PurchaseReceiving::class)->warehouses($actor), 'id');
            $receipts = in_array($doc->branch_id, $receiptIds, true) && $warehouseIds !== []
                ? DB::table('purchasing_receipts')->where('order_id', $doc->id)->whereIn('warehouse_id', $warehouseIds)->orderByDesc('id')->get()
                : collect();
        }

        $permissions = $doc ? [
            'submit' => $doc->status === 'draft' && $doc->created_by === $actor && $access->allows($actor, 'order_submit', $doc->branch_id),
            'approve' => $doc->status === 'submitted' && ($doc->created_by !== $actor || app(OrganizationApi::class)->allowsSelfApproval($actor, 'purchasing')) && $access->allows($actor, 'order_approve', $doc->branch_id),
            'reopen' => $doc->status === 'rejected' && $doc->created_by === $actor && $access->allows($actor, 'order_update', $doc->branch_id),
            'issue' => $doc->status === 'approved' && ! $officialIssue && $access->allows($actor, 'order_issue', $doc->branch_id),
            'receipt_create' => $doc->status === 'approved' && $access->allows($actor, 'receipt_create', $doc->branch_id),
            'amend' => $doc->status === 'approved' && $amendments->isEmpty() && $access->allows($actor, 'order_amend', $doc->branch_id),
            'supplier_view' => app(SupplierAccess::class)->allows($actor, 'M03.supplier_view'),
            'offer_view' => $access->allows($actor, 'offer_view', $doc->branch_id),
            'return_view' => $access->branches($actor, 'return_view') !== [],
            'accounting_setup' => app(AccountingAccess::class)->allows($actor, 'setup_view'),
        ] : [
            'submit' => false, 'approve' => false, 'reopen' => false, 'issue' => false, 'receipt_create' => false,
            'amend' => false, 'supplier_view' => false, 'offer_view' => false, 'return_view' => false,
            'accounting_setup' => app(AccountingAccess::class)->allows($actor, 'setup_view'),
        ];

        return view('purchasing.order', compact(
            'doc', 'editable', 'isEditing', 'branches', 'branchName', 'awards', 'awardInfo',
            'products', 'categories', 'brands', 'suppliers', 'savedLines', 'savedLinesById', 'unitNames', 'receivedByLine',
            'history', 'amendments', 'receipts', 'officialIssue', 'officialTax', 'receiving',
            'draftCalc', 'permissions'
        ))->layout('layouts::app', ['title' => $doc ? ($officialIssue?->official_number ?? 'أمر الشراء #'.$doc->id) : 'أمر شراء جديد']);
    }
}
