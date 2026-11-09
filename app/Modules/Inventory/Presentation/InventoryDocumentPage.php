<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Inventory\Application\InventoryLineImport;
use App\Modules\Inventory\Application\InventoryQuery;
use App\Modules\Inventory\Application\InventoryTransfers;
use App\Modules\Inventory\Domain\InventoryHistoryReader;
use App\Modules\Products\Application\Contracts\FindByBarcodeRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class InventoryDocumentPage extends Component
{
    use WithFileUploads;

    public $importFile;

    #[Locked]
    public array $importPreview = [];

    #[Locked]
    public ?int $importWarehouse = null;

    #[Locked]
    public string $kind = 'opening';

    #[Locked]
    public ?int $document = null;

    #[Locked]
    public ?int $version = null;

    #[Locked]
    public string $creationKey = '';

    public string $productSearch = '';

    public string $barcode = '';

    #[Locked]
    public string $receiptKey = '';

    public array $receiptLines = [];

    public string $receiptReason = '';

    public string $receiptEvidence = '';

    public function receivePartial(): void
    {
        $this->row();
        $rows = $this->receiptLines;
        foreach ($rows as &$line) {
            foreach (['accepted_serials', 'damaged_serials'] as $field) {
                if (is_string($line[$field] ?? null)) {
                    $line[$field] = preg_split('/\R/u', $line[$field], -1, PREG_SPLIT_NO_EMPTY);
                }
            }
        }
        app(InventoryTransfers::class)->receive(Auth::id(), $this->document, ['idempotency_key' => $this->receiptKey, 'reason' => $this->receiptReason, 'evidence' => $this->receiptEvidence, 'lines' => array_values(array_filter($rows, fn ($l) => ! collect(['accepted', 'damaged', 'missing', 'recovered_missing'])->every(fn ($k) => preg_match('/^0(?:\.0+)?$/D', $l[$k] ?? '0'))))]);
        $this->reload();
        session()->flash('status', 'تم حفظ واقعة الاستلام. المخزون التالف غير متاح، والكمية الناقصة ما زالت معلّقة.');
    }

    public function addByBarcode(): void
    {
        abort_unless($this->kind === 'count', 422);
        $record = $this->row();
        abort_if($record && ($record->status !== 'draft' || $record->created_by !== Auth::id()), 403);
        app(InventoryAccess::class)->authorize(Auth::id(), 'count_create', (int) $this->form['warehouse_id']);
        $this->validate(['barcode' => ['required', 'string', 'max:100']]);
        try {
            $product = app(ProductsApi::class)->findByBarcode(new FindByBarcodeRequest(Auth::id(), $this->barcode))->product;
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['barcode' => 'لا يوجد صنف نشط بهذا الباركود.']);
        }
        if ($product['tracking'] !== 'quantity' || $product['kind'] === 'service') {
            throw ValidationException::withMessages(['barcode' => 'الجرد الحالي يدعم أصناف التتبع الكمي فقط.']);
        }
        foreach ($this->form['lines'] as $line) {
            if ((int) $line['product_id'] === $product['id']) {
                $this->barcode = '';
                session()->flash('status', 'الصنف موجود في الجرد. أدخل العدد الفعلي في بنده؛ لم تتغير الكمية.');

                return;
            }
        }
        $empty = array_search('', array_column($this->form['lines'], 'product_id'), true);
        if ($empty === false && count($this->form['lines']) >= 100) {
            throw ValidationException::withMessages(['barcode' => 'الحد الأقصى 100 صنف في الجرد.']);
        }
        $index = $empty === false ? count($this->form['lines']) : $empty;
        $this->form['lines'][$index] = ['product_id' => $product['id'], 'quantity' => ''];
        $this->barcode = '';
        session()->flash('status', 'تم تحديد الصنف بالباركود. أدخل العدد الفعلي ثم احفظ المسودة.');
    }

    public array $form = ['warehouse_id' => '', 'destination_id' => '', 'reason' => '', 'lines' => [['product_id' => '', 'quantity' => '']]];

    public function mount(string $kind = 'opening', ?int $document = null): void
    {
        abort_unless(isset(InventoryDocuments::KINDS[$kind]), 404);
        $this->kind = $kind;
        $this->creationKey = (string) Str::uuid();
        if ($document) {
            $this->document = $document;
            $this->reload();
        } else {
            abort_unless(app(InventoryAccess::class)->ids(Auth::id(), app(InventoryDocuments::class)->permission($kind, 'create')) !== [], 403);
        }
    }

    private function row(): ?object
    {
        if (! $this->document) {
            return null;
        }
        $r = DB::table('inventory_documents')->where('id', $this->document)->where('kind', $this->kind)->first();
        abort_unless($r, 404);
        app(InventoryQuery::class)->documents(Auth::id(), $this->kind)->where('id', $r->id)->firstOrFail();

        return $r;
    }

    private function reload(): void
    {
        $r = $this->row();
        $this->receiptKey = (string) Str::uuid();
        $this->receiptLines = [];
        if ($r->kind === 'transfer' && $r->status === 'dispatched') {
            foreach (app(InventoryTransfers::class)->quantities($r->id) as $entry) {
                if (bccomp($entry['in_transit'], '0', 8) > 0) {
                    $this->receiptLines[] = ['line_id' => $entry['line_id'], 'accepted' => '0', 'damaged' => '0', 'missing' => '0', 'recovered_missing' => '0', 'accepted_serials' => '', 'damaged_serials' => ''];
                }
            }
        }
        $this->version = $r->version;
        $this->creationKey = $r->creation_key;
        $this->form = ['warehouse_id' => $r->warehouse_id, 'destination_id' => $r->destination_id ?? '', 'reason' => $r->reason, 'lines' => DB::table('inventory_document_lines')->where('document_id', $r->id)->orderBy('id')->get()->map(fn ($l) => ['product_id' => $l->product_id, 'quantity' => $l->quantity, 'unit_cost' => $l->unit_cost ?? '', 'cost_reference' => $l->cost_reference ?? '', 'serials' => implode("\n", json_decode($l->serials, true))])->all()];
    }

    public function template()
    {
        $this->row();
        $path = app(InventoryLineImport::class)->template(Auth::id(), $this->kind, (int) $this->form['warehouse_id']);

        return response()->download($path, 'inventory-'.$this->kind.'-template.xlsx')->setPrivate()->deleteFileAfterSend();
    }

    public function previewImport(): void
    {
        $record = $this->row();
        abort_if($record && ($record->status !== 'draft' || $record->created_by !== Auth::id()), 403);
        $this->importPreview = [];
        try {
            $this->validate(['importFile' => ['required', 'file', 'max:10240']]);
            $this->importPreview = app(InventoryLineImport::class)->preview(Auth::id(), $this->kind, (int) $this->form['warehouse_id'], $this->importFile);
            $this->importWarehouse = (int) $this->form['warehouse_id'];
        } finally {
            if ($this->importFile instanceof TemporaryUploadedFile) {
                $this->importFile->delete();
            } $this->importFile = null;
        }
    }

    public function applyImport(): void
    {
        $record = $this->row();
        abort_if($record && ($record->status !== 'draft' || $record->created_by !== Auth::id()), 403);
        app(InventoryLineImport::class)->authorize(Auth::id(), $this->kind, (int) $this->form['warehouse_id']);
        abort_unless($this->importPreview !== [] && $this->importWarehouse === (int) $this->form['warehouse_id'] && ! collect($this->importPreview)->contains(fn ($l) => $l['error'] !== null), 422);
        $this->form['lines'] = array_map(fn ($r) => ['product_id' => $r['product_id'], 'quantity' => $r['quantity']], $this->importPreview);
        $this->importPreview = [];
        session()->flash('status', 'تم نقل المعاينة للنموذج؛ اضغط حفظ المسودة صراحةً. لم يحدث ترحيل.');
    }

    public function addLine(): void
    {
        if (count($this->form['lines']) < 100) {
            $this->form['lines'][] = ['product_id' => '', 'quantity' => ''];
        }
    }

    public function removeLine(int $index): void
    {
        unset($this->form['lines'][$index]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(): void
    {
        $r = app(InventoryDocuments::class)->save(Auth::id(), $this->kind, array_replace($this->form, ['creation_key' => $this->creationKey]), $this->document, $this->version);
        $this->document = $r['id'];
        $this->version = $r['version'];
        $this->reload();
        session()->flash('status', 'تم حفظ المسودة؛ لم يتغير رصيد المخزون.');
    }

    public function applyAction(string $action): void
    {
        abort_unless($this->document && $this->version, 422);
        app(InventoryDocuments::class)->transition(Auth::id(), $this->document, $action, $this->version);
        $this->reload();
        session()->flash('status', 'تم تنفيذ الإجراء وحفظ نتيجته.');
    }

    public function render()
    {
        $r = $this->row();
        $access = app(InventoryAccess::class);
        $docs = app(InventoryDocuments::class);
        $ids = [];
        foreach (['create', 'approve', ...($this->kind === 'transfer' ? ['dispatch', 'receive'] : ($this->kind === 'count' ? ['submit'] : ['post']))] as $a) {
            $ids = array_merge($ids, $access->ids(Auth::id(), $docs->permission($this->kind, $a)));
        }

        $products = app(ProductsApi::class)->inventoryProducts(Auth::id(), $this->productSearch);
        foreach (array_unique(array_filter(array_column($this->form['lines'], 'product_id'))) as $id) {
            if (! collect($products)->contains('id', (int) $id)) {
                $products[] = app(ProductsApi::class)->inventoryProduct(Auth::id(), (int) $id);
            }
        }

        return view('inventory.document', ['transferQuantities' => $r && $this->kind === 'transfer' ? app(InventoryTransfers::class)->quantities($r->id) : [], 'transferReceipts' => $r && $this->kind === 'transfer' ? DB::table('inventory_transfer_receipts')->where('document_id', $r->id)->orderByDesc('id')->get() : collect(), 'history' => $r ? app(InventoryHistoryReader::class)->read(Auth::id(), $r->id) : [], 'title' => InventoryDocuments::KINDS[$this->kind], 'record' => $r, 'warehouses' => DB::table('inventory_warehouses')->whereIn('id', array_unique($ids))->get(), 'products' => $products, 'lines' => $r ? DB::table('inventory_document_lines')->where('document_id', $r->id)->get() : collect(), 'access' => $access, 'docs' => $docs])->layout('layouts::app', ['title' => InventoryDocuments::KINDS[$this->kind]]);
    }
}
