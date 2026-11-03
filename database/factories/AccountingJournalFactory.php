<?php

namespace Database\Factories;

use App\Modules\Accounting\Domain\AccountingJournal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AccountingJournalFactory extends Factory
{
    protected $model = AccountingJournal::class;

    public function definition(): array
    {
        return ['creation_key' => (string) Str::uuid(), 'branch_id' => BranchFactory::new(), 'created_by' => UserFactory::new()->disabledDemo(), 'journal_date' => '2040-01-15', 'description' => 'مسودة مصرية تجريبية غير مرحلة', 'currency' => 'EGP', 'status' => 'draft'];
    }
}
