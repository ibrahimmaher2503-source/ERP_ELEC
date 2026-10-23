<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierCategory;
use Database\Factories\Support\EgyptianMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        $data = EgyptianMarket::party(fake()->unique()->numberBetween(100000, 999999), 'supplier');
        $data['category_id'] = SupplierCategory::factory();

        $data['status'] = 'active';

        return $data;
    }
}
