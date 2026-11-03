<?php

namespace Database\Factories;

use App\Modules\Accounting\Domain\AccountingAccount;
use App\Modules\Accounting\Domain\AccountingJournalLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountingJournalLineFactory extends Factory
{
    protected $model = AccountingJournalLine::class;

    public function definition(): array
    {
        return ['journal_id' => AccountingJournalFactory::new(), 'account_id' => AccountingAccountFactory::new(), 'account_code' => fn (array $a) => AccountingAccount::findOrFail($a['account_id'])->code, 'account_name' => fn (array $a) => AccountingAccount::findOrFail($a['account_id'])->name, 'debit' => '100.00', 'credit' => '0.00', 'description' => 'بند مسودة تجريبي'];
    }
}
