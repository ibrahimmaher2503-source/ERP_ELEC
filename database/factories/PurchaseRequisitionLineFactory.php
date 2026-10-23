<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\PurchaseRequisition;
use App\Modules\Purchasing\Domain\PurchaseRequisitionLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionLineFactory extends Factory
{
    protected $model = PurchaseRequisitionLine::class;

    public function definition(): array
    {
        $p = DB::table('products')->where('sku', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->first();
        if (! $p) {
            throw new \RuntimeException('Existing Egyptian demo product required.');
        }

        return ['requisition_id' => PurchaseRequisition::factory(), 'product_id' => $p->id, 'unit_id' => $p->base_unit_id, 'sku' => $p->sku, 'product_name' => $p->name, 'quantity' => '12.00000000', 'notes' => 'كمية تجريبية بوحدة الصنف الأساسية'];
    }
}
