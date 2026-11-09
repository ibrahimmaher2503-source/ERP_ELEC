<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SearchSuppliersRequest;
use App\Modules\Parties\Application\Contracts\SupplierContractException;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Purchasing\Application\PurchasingAccess;
use App\Modules\Purchasing\Application\RfqActions;
use App\Modules\Purchasing\Domain\PurchaseRequisition;
use App\Modules\Purchasing\Domain\PurchaseRfq;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class RfqPage extends Component
{
    #[Locked]
    public ?int $rfq = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey = '';

    public array $form = [];

    public string $supplierSearch = '';

    public string $productSearch = '';

    public function addStandaloneLine(): void
    {
        abort_unless(($this->form['source_type'] ?? '') === 'STANDALONE', 422);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'rfq_standalone', (int) ($this->form['branch_id'] ?? 0));
        abort_if(count($this->form['lines']) >= 100, 422);
        $this->form['lines'][] = ['product_id' => '', 'base_unit_id' => '', 'quantity' => '1'];
    }

    public function selectProduct(int $index): void
    {
        app(PurchasingAccess::class)->authorize(auth()->id(), 'rfq_standalone', (int) ($this->form['branch_id'] ?? 0));
        $p = app(ProductsApi::class)->getProduct(new GetProductRequest(auth()->id(), (int) ($this->form['lines'][$index]['product_id'] ?? 0)))->product;
        $this->form['lines'][$index]['base_unit_id'] = $p['base_unit_id'];
    }

    public function updatedFormSourceType(): void
    {
        abort_if($this->rfq !== null, 422);
        $this->form['requisition_id'] = '';
        $this->form['lines'] = [];
    }

    public array $dispatchReferences = [];

    public function mount(?int $rfq = null): void
    {
        $this->rfq = $rfq;
        $this->creationKey = (string) Str::uuid();
        $this->form = ['source_type' => 'REQUISITION', 'branch_id' => '', 'justification' => '', 'requisition_id' => '', 'subject' => '', 'request_date' => now('Africa/Cairo')->toDateString(), 'deadline' => '', 'requirements' => '', 'supplier_ids' => [], 'lines' => []];
        if ($rfq) {
            $this->loadDocument();
        } else {
            abort_unless(app(PurchasingAccess::class)->branches(auth()->id(), 'rfq_create'), 403);
            if (request()->filled('requisition')) {
                $input = request()->validate(['requisition' => 'required|integer|min:1']);
                $this->form['requisition_id'] = $input['requisition'];
                $this->loadSource();
            }
        }
    }

    private function document(): PurchaseRfq
    {
        $doc = PurchaseRfq::findOrFail($this->rfq);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'rfq_view', $doc->branch_id);

        return $doc;
    }

    private function loadDocument(): void
    {
        $doc = $this->document();
        $this->version = $doc->version;
        $this->creationKey = $doc->creation_key;
        $this->form = $doc->only(['source_type', 'branch_id', 'justification', 'requisition_id', 'subject', 'request_date', 'deadline', 'requirements']);
        $this->form['supplier_ids'] = $doc->suppliers()->pluck('supplier_id')->map(fn ($v) => (string) $v)->all();
        $this->form['lines'] = $doc->lines()->orderBy('id')->get()->map(fn ($l) => $l->only(['requisition_line_id', 'product_id', 'base_unit_id', 'product_name', 'sku', 'unit_name', 'quantity']))->all();
    }

    public function loadSource(): void
    {
        abort_if($this->rfq !== null || ($this->form['source_type'] ?? 'REQUISITION') !== 'REQUISITION', 422);
        $source = PurchaseRequisition::where('status', 'approved')->findOrFail((int) ($this->form['requisition_id'] ?? 0));
        app(PurchasingAccess::class)->authorize(auth()->id(), 'requisition_view', $source->branch_id);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'rfq_create', $source->branch_id);
        $this->form['lines'] = $source->lines()->orderBy('id')->get()->map(fn ($l) => ['requisition_line_id' => $l->id, 'quantity' => $l->quantity])->all();
    }

    public function removeLine(int $index): void
    {
        unset($this->form['lines'][$index]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(): void
    {
        $doc = app(RfqActions::class)->save(auth()->id(), array_replace($this->form, ['creation_key' => $this->creationKey]), $this->rfq, $this->version);
        $this->rfq = $doc->id;
        $this->version = $doc->version;
        $this->redirectRoute('purchasing.rfqs.show', ['rfq' => $doc->id], navigate: true);
    }

    public function issue(): void
    {
        $this->document();
        app(RfqActions::class)->issue(auth()->id(), $this->rfq, $this->version);
        $this->loadDocument();
    }

    public function recordDispatch(int $supplier): void
    {
        $this->document();
        app(RfqActions::class)->dispatch(auth()->id(), $this->rfq, $supplier, $this->dispatchReferences[$supplier] ?? '');
    }

    public function render()
    {
        $access = app(PurchasingAccess::class);
        $doc = $this->rfq ? $this->document()->load(['lines', 'suppliers']) : null;
        $editable = ! $doc || ($doc->status === 'draft' && $doc->created_by === auth()->id() && $access->allows(auth()->id(), 'rfq_update', $doc->branch_id));
        $sourceIds = array_column($access->branches(auth()->id(), 'requisition_view'), 'id');
        $requisitions = PurchaseRequisition::where('status', 'approved')->whereIn('branch_id', $sourceIds)->orderByDesc('id')->limit(100)->get();
        if ($this->form['requisition_id'] && ! $requisitions->contains('id', (int) $this->form['requisition_id'])) {
            $selectedRequisition = PurchaseRequisition::where('status', 'approved')->whereIn('branch_id', $sourceIds)->find((int) $this->form['requisition_id']);
            if ($selectedRequisition) {
                $requisitions->push($selectedRequisition);
            }
        }
        $source = null;
        if (! empty($this->form['requisition_id'])) {
            $source = PurchaseRequisition::whereIn('branch_id', $sourceIds)->with('lines')->find((int) $this->form['requisition_id']);
        }
        try {
            $suppliers = $editable ? app(SuppliersApi::class)->searchSuppliers(new SearchSuppliersRequest(auth()->id(), mb_substr($this->supplierSearch, 0, 200), 50)) : [];
            if ($editable) {
                foreach (array_unique(array_filter($this->form['supplier_ids'])) as $id) {
                    if (! collect($suppliers)->contains('id', (int) $id)) {
                        $suppliers[] = app(SuppliersApi::class)->getSupplier(new GetSupplierRequest(auth()->id(), (int) $id, false));
                    }
                }
            }
        } catch (SupplierContractException $e) {
            abort($e->getCode(), 'صلاحية عرض دليل الموردين مطلوبة لإنشاء الطلب.');
        }

        $canViewResponses = $doc && $access->allows(auth()->id(), 'offer_view', $doc->branch_id);
        $responses = $canViewResponses
            ? DB::table('purchasing_offers')->where('rfq_id', $doc->id)->where('status', 'recorded')->orderByDesc('id')->get(['id', 'supplier_id', 'supplier_reference', 'currency', 'document_date'])->unique('supplier_id')->keyBy('supplier_id')
            : collect();

        return view('purchasing.rfq', ['standaloneBranches' => $access->branches(auth()->id(), 'rfq_standalone'), 'products' => $editable && ($this->form['source_type'] ?? '') === 'STANDALONE' ? array_map(fn ($p) => $p + ['unit_name' => (string) $p['base_unit_id']], app(ProductsApi::class)->listMasters(new ListMastersRequest(auth()->id(), search: mb_substr($this->productSearch, 0, 200), pageSize: 100))->items) : [], 'doc' => $doc, 'editable' => $editable, 'requisitions' => $requisitions, 'source' => $source, 'suppliers' => $suppliers, 'canViewResponses' => $canViewResponses, 'responses' => $responses])->layout('layouts::app', ['title' => 'طلب عروض الموردين']);
    }
}
