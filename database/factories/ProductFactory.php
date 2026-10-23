<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use Database\Factories\Support\EgyptianMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $data = EgyptianMarket::product(fake()->unique()->numberBetween(100000, 999999));
        $base = $data['fixture_base'];
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, 'fixture_')) {
                unset($data[$key]);
            }
        }

        $data['active'] = true;

        return $data + ['category_id' => Category::factory(), 'brand_id' => Brand::factory(), 'base_unit_id' => Unit::factory()->state(['name' => $base === 'METER' ? 'متر' : 'قطعة', 'dimension' => $base === 'METER' ? 'length' : 'count']), 'purchase_unit_id' => fn (array $a) => $a['base_unit_id'], 'sales_unit_id' => fn (array $a) => $a['base_unit_id'], 'created_by' => User::factory()->disabledDemo(), 'updated_by' => fn (array $a) => $a['created_by']];
    }
}
