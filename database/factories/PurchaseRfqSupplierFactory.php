<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\PurchaseRfq;
use App\Modules\Purchasing\Domain\PurchaseRfqSupplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class PurchaseRfqSupplierFactory extends Factory
{
    protected $model = PurchaseRfqSupplier::class;

    public function definition(): array
    {
        $s = DB::table('suppliers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->orderBy('id')->first();
        if (! $s) {
            throw new \RuntimeException('Existing active Egyptian synthetic supplier required.');
        }

        return ['rfq_id' => PurchaseRfq::factory(), 'supplier_id' => $s->id, 'supplier_name' => $s->name];
    }
}
