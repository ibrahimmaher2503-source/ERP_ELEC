<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Customer;
use Database\Factories\Support\EgyptianMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $data = EgyptianMarket::party(fake()->unique()->numberBetween(100000, 999999), 'customer');

        $data['status'] = 'active';

        return $data;
    }
}
