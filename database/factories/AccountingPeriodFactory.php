<?php

namespace Database\Factories;

use App\Modules\Accounting\Domain\AccountingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountingPeriodFactory extends Factory
{
    protected $model = AccountingPeriod::class;

    public function definition(): array
    {
        $start = fake()->unique()->dateTimeBetween('2040-01-01', '2090-12-01')->format('Y-m-d');

        return ['name' => 'فترة تجريبية '.$start, 'starts_on' => $start, 'ends_on' => $start, 'status' => 'open'];
    }
}
