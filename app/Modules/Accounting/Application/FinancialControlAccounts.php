<?php

namespace App\Modules\Accounting\Application;

use Illuminate\Support\Facades\DB;

final class FinancialControlAccounts
{
    public function ids(): array
    {
        $definitions = FinancialActivation::MAPPINGS + FinancialActivation::OPTIONAL_MAPPINGS;
        $purposes = array_keys(array_filter($definitions, fn (array $definition) => $definition[2] ?? false));

        return DB::table('accounting_account_mappings')->whereIn('purpose', $purposes)->pluck('account_id')->all();
    }

    public function assertManual(array $accounts): void
    {
        if (array_intersect($accounts, $this->ids()) !== []) {
            FinancialActivation::fail('الحسابات الرقابية تُرحل وتُصحح من مصدرها فقط لحماية مصالحة المخزون والذمم والخزينة.');
        }
    }

    public function assertJournal(int $journal): void
    {
        $this->assertManual(DB::table('accounting_journal_lines')->where('journal_id', $journal)->pluck('account_id')->all());
    }

    public function assertMapping(int $account): void
    {
        if (DB::table('accounting_journal_lines as l')->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.account_id', $account)->where('j.status', '<>', 'draft')
            ->where(fn ($q) => $q->whereNull('j.source_key')->orWhere('j.source_key', 'like', 'journal-reversal:%'))->exists()) {
            FinancialActivation::fail('الحساب مستخدم في قيد يدوي مرسل أو معتمد أو مرحل ولا يمكن تحويله إلى حساب رقابي للمصدر.');
        }
    }
}
