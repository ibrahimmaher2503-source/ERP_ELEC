<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Purchasing\Application\PurchasingAccess;
use App\Modules\Purchasing\Application\RequisitionActions;
use App\Modules\Purchasing\Domain\PurchaseRequisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class RequisitionPage extends Component
{
    #[Locked]
    public ?int $requisition = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey = '';

    public array $form = [];

    public string $productSearch = '';

    public string $reviewReason = '';

    public function mount(?int $requisition = null): void
    {
        $this->requisition = $requisition;
        $this->creationKey = (string) Str::uuid();
        $this->form = ['branch_id' => '', 'request_date' => now('Africa/Cairo')->toDateString(), 'required_date' => '', 'department' => '', 'purpose' => '', 'notes' => '', 'lines' => []];
        if ($requisition) {
            $this->loadDocument();
        } else {
            abort_unless(app(PurchasingAccess::class)->branches(auth()->id(), 'requisition_create'), 403);
        }
    }

    private function document(): PurchaseRequisition
    {
        $doc = PurchaseRequisition::findOrFail($this->requisition);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'requisition_view', $doc->branch_id);

        return $doc;
    }

    private function loadDocument(): void
    {
        $doc = $this->document();
        $this->version = $doc->version;
        $this->creationKey = $doc->creation_key;
        $this->form = $doc->only(['branch_id', 'request_date', 'required_date', 'department', 'purpose', 'notes']);
        $this->form['lines'] = $doc->lines()->orderBy('id')->get()->map(fn ($l) => $l->only(['product_id', 'quantity', 'required_date', 'notes']))->all();
    }

    public function addLine(): void
    {
        abort_if(count($this->form['lines']) >= 100, 422);
        $this->form['lines'][] = ['product_id' => '', 'quantity' => '1', 'required_date' => '', 'notes' => ''];
    }

    public function removeLine(int $index): void
    {
        unset($this->form['lines'][$index]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(): void
    {
        $doc = app(RequisitionActions::class)->save(auth()->id(), array_replace($this->form, ['creation_key' => $this->creationKey]), $this->requisition, $this->version);
        $this->requisition = $doc->id;
        $this->version = $doc->version;
        $this->redirectRoute('purchasing.requisitions.show', ['requisition' => $doc->id], navigate: true);
    }

    public function applyAction(string $action): void
    {
        $this->document();
        app(RequisitionActions::class)->transition(auth()->id(), $this->requisition, $this->version, $action, $this->reviewReason);
        $this->loadDocument();
    }

    public function render()
    {
        $doc = $this->requisition ? $this->document()->load('lines') : null;
        $editable = ! $doc || ($doc->status === 'draft' && $doc->created_by === auth()->id() && app(PurchasingAccess::class)->allows(auth()->id(), 'requisition_update', $doc->branch_id));
        $products = $editable ? app(ProductsApi::class)->listMasters(new ListMastersRequest(auth()->id(), search: mb_substr($this->productSearch, 0, 200), pageSize: 100))->items : [];
        if ($editable) {
            foreach (array_unique(array_filter(array_column($this->form['lines'], 'product_id'))) as $id) {
                if (! collect($products)->contains('id', (int) $id)) {
                    $products[] = app(ProductsApi::class)->getProduct(new GetProductRequest(auth()->id(), (int) $id))->product;
                }
            }
        }

        return view('purchasing.requisition', ['doc' => $doc, 'editable' => $editable, 'products' => $products, 'branches' => app(PurchasingAccess::class)->branches(auth()->id(), $doc ? 'requisition_view' : 'requisition_create'), 'history' => $doc ? DB::table('purchase_requisition_reviews')->where('requisition_id', $doc->id)->orderByDesc('id')->get() : collect()])->layout('layouts::app', ['title' => 'طلب الاحتياج']);
    }
}
