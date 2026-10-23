<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\CustomerContact;
use Database\Factories\Support\EgyptianMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerContact> */
class CustomerContactFactory extends Factory
{
    protected $model = CustomerContact::class;

    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), ...EgyptianMarket::contact(fake()->unique()->numberBetween(100000, 999999)), 'is_primary' => false];
    }
}
