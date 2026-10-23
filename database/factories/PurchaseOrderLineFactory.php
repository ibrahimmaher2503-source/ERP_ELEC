<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\PurchaseOrder;
use App\Modules\Purchasing\Domain\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class PurchaseOrderLineFactory extends Factory
{
    protected $model = PurchaseOrderLine::class;

    public function definition(): array
    {
        $p = DB::table('products')->where('sku', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->first();
        if (! $p) {
            throw new \RuntimeException('Existing Egyptian demo product required.');
        }

        return ['order_id' => PurchaseOrder::factory(), 'product_id' => $p->id, 'unit_id' => $p->base_unit_id, 'sku' => $p->sku, 'product_name' => $p->name, 'quantity' => '12.00000000', 'unit_price' => '75.00000000', 'discount_amount' => '0.00000000', 'net_amount' => '900.00000000'];
    }
}
