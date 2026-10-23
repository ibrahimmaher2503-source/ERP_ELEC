<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\SupplierAward;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class SupplierAwardFactory extends Factory
{
    protected $model = SupplierAward::class;

    public function definition(): array
    {
        $offer = DB::table('purchasing_offers')->where('notes', 'like', 'DEMO-EG%')->where('status', 'recorded')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('purchasing_awards')->whereColumn('rfq_id', 'purchasing_offers.rfq_id')->whereIn('status', ['proposed', 'approved']))->orderBy('id')->first();
        if (! $offer) {
            throw new \RuntimeException('Actually recorded, unawarded synthetic offer required.');
        }
        $lines = DB::table('purchasing_offer_lines')->where('offer_id', $offer->id)->get();
        $requested = DB::table('purchasing_rfq_lines')->where('rfq_id', $offer->rfq_id)->get();
        if ($lines->count() !== $requested->count() || $requested->contains(fn ($r) => ! $lines->firstWhere('rfq_line_id', $r->id) || bccomp($lines->firstWhere('rfq_line_id', $r->id)->quantity, $r->quantity, 8) !== 0)) {
            throw new \RuntimeException('Full eligible coverage required; no partial award is invented.');
        }

        return ['rfq_id' => $offer->rfq_id, 'offer_id' => $offer->id, 'branch_id' => $offer->branch_id, 'created_by' => $offer->created_by, 'status' => 'proposed', 'version' => 1, 'rationale' => 'DEMO-EG — اقتراح تجريبي لمراجعة شروط المورد دون اعتماد', 'snapshot' => json_encode(['offer' => (array) $offer, 'lines' => $lines->all()], JSON_THROW_ON_ERROR)];
    }
}
