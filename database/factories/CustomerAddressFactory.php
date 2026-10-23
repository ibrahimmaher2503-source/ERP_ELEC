<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\CustomerAddress;
use Database\Factories\Support\EgyptianMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAddress> */
class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), ...EgyptianMarket::address(fake()->numberBetween(1, 100000), fake()->randomElement(['billing', 'shipping', 'other'])), 'is_primary' => false];
    }
}
