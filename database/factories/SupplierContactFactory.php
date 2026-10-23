<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierContact;
use Database\Factories\Support\EgyptianMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierContact> */
class SupplierContactFactory extends Factory
{
    protected $model = SupplierContact::class;

    public function definition(): array
    {
        return ['supplier_id' => Supplier::factory(), ...EgyptianMarket::contact(fake()->unique()->numberBetween(100000, 999999)), 'is_primary' => false];
    }
}
