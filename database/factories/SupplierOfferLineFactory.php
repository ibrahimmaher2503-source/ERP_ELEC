<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\SupplierOffer;
use App\Modules\Purchasing\Domain\SupplierOfferLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class SupplierOfferLineFactory extends Factory
{
    protected $model = SupplierOfferLine::class;

    public function definition(): array
    {
        return ['offer_id' => SupplierOffer::factory(), 'quantity' => '1.00000000', 'unit_price' => '75.00000000', 'discount_amount' => '0.00000000', 'net_amount' => '75.00000000', 'specification' => 'مواصفات تجريبية غير معتمدة للتوريد'];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SupplierOfferLine $line) {
            $offer = SupplierOffer::findOrFail($line->offer_id);
            $source = DB::table('purchasing_rfq_lines')->where('rfq_id', $offer->rfq_id)->where('quantity', '>=', '1')->orderBy('id')->first();
            if (! $source) {
                throw new \RuntimeException('Eligible source line of at least one base unit required.');
            }
            $line->rfq_line_id = $source->id;
        });
    }
}
