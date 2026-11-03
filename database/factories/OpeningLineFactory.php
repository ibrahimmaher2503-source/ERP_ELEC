<?php

namespace Database\Factories;

use App\Modules\Accounting\Domain\AccountingAccount;
use App\Modules\Accounting\Domain\OpeningBatch;
use App\Modules\Accounting\Domain\OpeningLine;
use App\Modules\Organization\Domain\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

final class OpeningLineFactory extends Factory
{
    protected $model = OpeningLine::class;

    public function definition(): array
    {
        return ['batch_id' => OpeningBatch::factory(), 'kind' => 'gl', 'reference' => 'DEMO-EG-OPEN-'.fake()->unique()->numerify('########'),
            'branch_id' => Branch::factory(), 'account_id' => AccountingAccount::factory(), 'debit' => '1250.00', 'credit' => '0.00', 'amount' => '0.00'];
    }
}
