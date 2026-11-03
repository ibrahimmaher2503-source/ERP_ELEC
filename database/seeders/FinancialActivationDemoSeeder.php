<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Explicit, repeat-safe composition; no fake activation, tax or posting evidence. */
final class FinancialActivationDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
        $this->call(AccountingDemoSeeder::class);
        $this->call([OpeningPayablesDemoSeeder::class, OpeningTreasuryDemoSeeder::class]);
        // Opening drafts/factories are opt-in. No Go-Live date, balances, tax rates,
        // supplier liabilities, approvals, stock layers or Treasury entries are seeded.
    }
}
