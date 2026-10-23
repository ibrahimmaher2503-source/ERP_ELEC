<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\OfferFiles;
use App\Modules\Purchasing\Application\PurchasingAccess;
use App\Modules\Purchasing\Application\SupplierOffers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

final class OfferPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $offer = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey = '';

    #[Locked]
    public ?int $previous = null;

    public array $form = [];

    public $original = null;

    public function mount(?int $offer = null): void
    {
        $this->offer = $offer;
        $this->creationKey = (string) Str::uuid();
        $this->form = ['rfq_id' => '', 'supplier_id' => '', 'supplier_reference' => '', 'document_date' => now('Africa/Cairo')->toDateString(), 'valid_until' => '', 'currency' => '', 'lead_days' => '', 'payment_terms' => '', 'warranty' => '', 'notes' => '', 'reported_tax' => '', 'lines' => []];
        if ($offer) {
            $this->loadDocument();
        } else {
            abort_unless(app(PurchasingAccess::class)->branches(auth()->id(), 'offer_create'), 403);
            if (request()->filled('rfq')) {
                $input = request()->validate(['rfq' => 'required|integer|min:1']);
                $this->form['rfq_id'] = $input['rfq'];
                $this->loadRfq();
            }
        }
    }

    private function loadDocument(): void
    {
        $doc = app(SupplierOffers::class)->record(auth()->id(), $this->offer);
        $this->version = $doc->version;
        $this->creationKey = $doc->creation_key;
        $this->previous = $doc->previous_id;
        foreach (array_keys($this->form) as $k) {
            if ($k !== 'lines') {
                $this->form[$k] = $doc->$k;
            }
        }
        $this->form['lines'] = DB::table('purchasing_offer_lines')->where('offer_id', $doc->id)->orderBy('id')->get()->map(fn ($l) => array_intersect_key((array) $l, array_flip(['rfq_line_id', 'quantity', 'unit_price', 'discount_amount', 'specification'])))->all();
    }

    public function loadRfq(): void
    {
        abort_if($this->offer !== null || $this->previous !== null, 422);
        $rfq = DB::table('purchasing_rfqs')->find((int) $this->form['rfq_id']);
        abort_unless($rfq, 404);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'offer_create', $rfq->branch_id);
        abort_unless($rfq->status === 'issued', 422);
        $this->form['lines'] = DB::table('purchasing_rfq_lines')->where('rfq_id', $rfq->id)->orderBy('id')->get()->map(fn ($l) => ['rfq_line_id' => $l->id, 'quantity' => $l->quantity, 'unit_price' => '0', 'discount_amount' => '0', 'specification' => ''])->all();
        $this->form['supplier_id'] = '';
    }

    public function removeLine(int $i): void
    {
        unset($this->form['lines'][$i]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(): void
    {
        $id = app(SupplierOffers::class)->save(auth()->id(), array_replace($this->form, ['creation_key' => $this->creationKey, 'previous_id' => $this->previous]), $this->offer, $this->version);
        $this->offer = $id;
        $this->version = (int) DB::table('purchasing_offers')->where('id', $id)->value('version');
        $this->redirectRoute('purchasing.offers.show', ['offer' => $id], navigate: true);
    }

    public function freeze(): void
    {
        app(SupplierOffers::class)->freeze(auth()->id(), $this->offer, $this->version);
        $this->loadDocument();
    }

    public function revise(): void
    {
        $doc = app(SupplierOffers::class)->record(auth()->id(), $this->offer);
        app(PurchasingAccess::class)->authorize(auth()->id(), 'offer_create', $doc->branch_id);
        abort_unless($doc->status === 'recorded', 422);
        $this->previous = $doc->id;
        $this->offer = null;
        $this->version = 0;
        $this->creationKey = (string) Str::uuid();
    }

    public function uploadOriginal(): void
    {
        $this->validate(['original' => 'required|file|max:10240']);
        try {
            app(OfferFiles::class)->attach(auth()->id(), $this->offer, $this->original);
        } finally {
            $this->original?->delete();
            $this->original = null;
        }
    }

    public function render()
    {
        $access = app(PurchasingAccess::class);
        $doc = $this->offer ? app(SupplierOffers::class)->record(auth()->id(), $this->offer) : null;
        $editable = ! $doc || ($doc->status === 'draft' && $doc->created_by === auth()->id() && $access->allows(auth()->id(), 'offer_update', $doc->branch_id));
        $branches = array_column($access->branches(auth()->id(), 'offer_view'), 'id');
        $rfqs = DB::table('purchasing_rfqs')->whereIn('branch_id', $branches)->where('status', 'issued')->orderByDesc('id')->limit(200)->get();
        if (! empty($this->form['rfq_id']) && ! $rfqs->contains('id', (int) $this->form['rfq_id'])) {
            $source = DB::table('purchasing_rfqs')->whereIn('branch_id', $branches)->where('status', 'issued')->find((int) $this->form['rfq_id']);
            if ($source) {
                $rfqs->push($source);
            }
        }
        $selected = $rfqs->firstWhere('id', (int) $this->form['rfq_id']);
        $suppliers = $selected ? DB::table('purchasing_rfq_suppliers')->where('rfq_id', $selected->id)->get() : collect();
        $sourceLines = $selected ? DB::table('purchasing_rfq_product_lines as r')->where('r.rfq_id', $selected->id)->get(['r.id', 'r.sku', 'r.product_name', 'r.quantity']) : collect();
        $files = $doc ? DB::table('purchasing_offer_files')->where('offer_id', $doc->id)->get() : collect();

        return view('purchasing.offer', compact('doc', 'editable', 'rfqs', 'suppliers', 'sourceLines', 'files'))->layout('layouts::app', ['title' => 'عرض المورد']);
    }
}
