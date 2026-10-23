<?php

namespace Database\Factories;

use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuotationLine> */
class QuotationLineFactory extends Factory
{
    protected $model = QuotationLine::class;

    public function definition(): array
    {
        return [
            'quotation_id' => Quotation::factory(),
            'position' => 1,
            'product_id' => Product::factory(),
            'unit_id' => fn (array $attributes): int => (int) Product::query()->findOrFail($attributes['product_id'])->base_unit_id,
            'product_snapshot' => [],
            'description' => 'Synthetic catalog line fixture.',
            'quantity' => '2.00000000',
            'suggested_price' => null,
            'requested_price' => null,
            'price_source' => null,
            'discount_mode' => 'percent',
            'discount_value' => '0.00000000',
            'override_reason' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (QuotationLine $line): void {
            $product = Product::query()->findOrFail($line->product_id);
            $unit = Unit::query()->findOrFail($line->unit_id);
            $line->update(['product_snapshot' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'description' => $product->short_description,
                'unit_id' => $unit->id,
                'unit_code' => $unit->code,
                'unit_name' => $unit->name,
                'version' => $product->version,
            ], 'description' => $line->description ?: $product->name]);
        });
    }
}
