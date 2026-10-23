<?php

namespace Database\Factories;

use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\ProductConversion;
use App\Modules\Products\Domain\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductConversion> */
class ProductConversionFactory extends Factory
{
    protected $model = ProductConversion::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'unit_id' => function (array $attributes) {
                $dimension = Product::findOrFail($attributes['product_id'])->baseUnit()->firstOrFail()->dimension;

                return Unit::factory()->create(['name' => match ($dimension) {
                    'count' => 'عبوة 10 قطع','length' => 'عبوة 10 أمتار','mass' => 'عبوة 10 كيلوجرامات','volume' => 'عبوة 10 لترات',default => 'فترة 10 ساعات'
                }, 'dimension' => $dimension])->id;
            },
            'factor' => '10.00000000',
        ];
    }
}
