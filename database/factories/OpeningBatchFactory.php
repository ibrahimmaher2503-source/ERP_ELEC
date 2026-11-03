<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Accounting\Domain\OpeningBatch;
use App\Modules\Organization\Domain\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class OpeningBatchFactory extends Factory
{
    protected $model = OpeningBatch::class;

    public function definition(): array
    {
        return ['creation_key' => (string) Str::uuid(), 'configuration_version' => 1, 'status' => 'draft', 'no_balances' => false,
            'evidence_reference' => 'DEMO-EG: حزمة إعداد اصطناعية غير معتمدة', 'created_by' => User::factory()->disabledDemo()];
    }

    public function balancedDraft(): static
    {
        return $this->afterCreating(function (OpeningBatch $batch) {
            $branch = Branch::factory()->create();
            OpeningLineFactory::new()->create(['batch_id' => $batch->id, 'branch_id' => $branch->id,
                'account_id' => AccountingAccountFactory::new()->state(['kind' => 'asset', 'name' => 'معدات كهربائية اصطناعية']), 'debit' => '1250.00', 'credit' => '0.00']);
            OpeningLineFactory::new()->create(['batch_id' => $batch->id, 'branch_id' => $branch->id,
                'account_id' => AccountingAccountFactory::new()->state(['kind' => 'equity', 'name' => 'حقوق ملكية اصطناعية']), 'debit' => '0.00', 'credit' => '1250.00']);
        });
    }
}
