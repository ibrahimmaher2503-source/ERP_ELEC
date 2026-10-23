<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchasingAccess;
use App\Modules\Purchasing\Application\SupplierOffers;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class ComparisonPage extends Component
{
    public string $rfq = '';

    public string $rationale = '';

    public function mount(): void
    {
        if (request()->filled('rfq')) {
            $input = request()->validate(['rfq' => 'required|integer|min:1']);
            $row = DB::table('purchasing_rfqs')->find($input['rfq']);
            abort_unless($row, 404);
            app(PurchasingAccess::class)->authorize(auth()->id(), 'comparison_view', $row->branch_id);
            $this->rfq = (string) $row->id;
        }
    }

    public function propose(int $offer): void
    {
        app(SupplierOffers::class)->propose(auth()->id(), $offer, $this->rationale);
    }

    public function decide(int $award, int $version, bool $approve): void
    {
        app(SupplierOffers::class)->decide(auth()->id(), $award, $version, $approve);
    }

    public function render()
    {
        $ids = array_column(app(PurchasingAccess::class)->branches(auth()->id(), 'comparison_view'), 'id');
        abort_unless($ids, 403);
        $rfqs = DB::table('purchasing_rfqs')->whereIn('branch_id', $ids)->where('status', 'issued')->orderByDesc('id')->limit(200)->get();
        if ($this->rfq !== '' && ! $rfqs->contains('id', (int) $this->rfq)) {
            $source = DB::table('purchasing_rfqs')->whereIn('branch_id', $ids)->where('status', 'issued')->find((int) $this->rfq);
            if ($source) {
                $rfqs->push($source);
            }
        }
        $selected = $rfqs->firstWhere('id', (int) $this->rfq);
        $offerIds = array_column(app(PurchasingAccess::class)->branches(auth()->id(), 'offer_view'), 'id');
        $offers = $selected && in_array($selected->branch_id, $offerIds, true) ? DB::table('purchasing_offers')->where('rfq_id', $selected->id)->where('status', 'recorded')->orderBy('supplier_id')->orderByDesc('id')->get() : collect();
        $lines = DB::table('purchasing_offer_lines')->whereIn('offer_id', $offers->pluck('id'))->get()->groupBy('offer_id');
        $source = $selected ? DB::table('purchasing_rfq_product_lines as r')->where('rfq_id', $selected->id)->get(['r.id', 'r.quantity', 'r.product_name', 'r.sku']) : collect();
        $awards = $selected ? DB::table('purchasing_awards')->where('rfq_id', $selected->id)->orderByDesc('id')->get() : collect();

        return view('purchasing.comparison', compact('rfqs', 'selected', 'offers', 'lines', 'source', 'awards'))->layout('layouts::app', ['title' => 'مقارنة عروض الموردين']);
    }
}
