<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\SupplierOffer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierOfferFactory extends Factory
{
    protected $model = SupplierOffer::class;

    public function definition(): array
    {
        $rfq = DB::table('purchasing_rfqs')->where('requirements', 'like', 'DEMO-EG%')->where('status', 'issued')->orderBy('id')->first();
        $recipient = $rfq ? DB::table('purchasing_rfq_suppliers')->where('rfq_id', $rfq->id)->orderBy('id')->first() : null;
        $user = DB::table('users')->where('active', false)->orderBy('id')->value('id');
        if (! $rfq || ! $recipient || ! $user) {
            throw new \RuntimeException('Actually issued synthetic RFQ and invited supplier required. No issue/dispatch history is fabricated.');
        }

        return ['creation_key' => (string) Str::uuid(), 'rfq_id' => $rfq->id, 'branch_id' => $rfq->branch_id, 'supplier_id' => $recipient->supplier_id, 'supplier_name' => $recipient->supplier_name, 'supplier_reference' => 'DEMO-EG-OFFER-'.Str::upper(Str::random(8)), 'created_by' => $user, 'status' => 'draft', 'version' => 1, 'document_date' => now('Africa/Cairo')->toDateString(), 'currency' => 'EGP', 'payment_terms' => 'شروط تجريبية مقدمة من مورد وهمي', 'net_amount' => '75.00000000', 'notes' => 'DEMO-EG — مسودة عرض تجريبية'];
    }
}
