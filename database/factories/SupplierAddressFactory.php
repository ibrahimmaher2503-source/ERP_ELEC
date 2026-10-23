<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierAddress;
use Database\Factories\Support\EgyptianMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierAddress> */
class SupplierAddressFactory extends Factory
{
    protected $model = SupplierAddress::class;

    public function definition(): array
    {
        return ['supplier_id' => Supplier::factory(), ...EgyptianMarket::address(fake()->numberBetween(1, 100000), fake()->randomElement(['registered', 'office', 'pickup', 'other'])), 'is_primary' => false];
    }
}
