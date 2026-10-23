<?php

namespace Database\Factories;

use App\Modules\Inventory\Domain\InventoryDocument;
use App\Modules\Inventory\Domain\InventoryDocumentLine;
use App\Modules\Products\Domain\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryDocumentLineFactory extends Factory
{
    protected $model = InventoryDocumentLine::class;

    public function definition(): array
    {
        $p = Product::with('baseUnit')->where('sku', 'like', 'DEMO%')->where('tracking', 'quantity')->where('active', true)->first();
        if (! $p) {
            throw new \RuntimeException('Inventory draft fixtures require an existing synthetic quantity-tracked Product.');
        }

        return ['document_id' => InventoryDocument::factory(), 'product_id' => $p->id, 'sku' => $p->sku, 'product_name' => $p->name, 'base_unit_id' => $p->base_unit_id, 'unit_name' => $p->baseUnit->name, 'quantity' => '12.00000000', 'recorded' => '0.00000000'];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (InventoryDocumentLine $line) {
            $p = Product::with('baseUnit')->findOrFail($line->product_id);
            $line->sku = $p->sku;
            $line->product_name = $p->name;
            $line->base_unit_id = $p->base_unit_id;
            $line->unit_name = $p->baseUnit->name;
        });
    }
}
