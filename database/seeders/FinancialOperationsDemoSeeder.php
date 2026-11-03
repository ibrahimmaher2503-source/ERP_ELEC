<?php

namespace Database\Seeders;

use App\Modules\Treasury\Domain\TreasuryAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Idempotent owner-safe configuration fixtures; never fabricates invoices, payments, journals or balances. */
final class FinancialOperationsDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
        DB::transaction(function () {
            foreach (['bank', 'cash'] as $kind) {
                if (DB::table('treasury_accounts')->where('kind', $kind)->where('active', true)->exists()
                    || ! DB::table('accounting_account_mappings')->where('purpose', $kind)->exists()) {
                    continue;
                }
                TreasuryAccount::factory()->create(['kind' => $kind, 'code' => 'DEMO-EG-'.strtoupper($kind),
                    'name' => $kind === 'bank' ? 'الحساب البنكي التشغيلي التجريبي' : 'الخزينة النقدية التشغيلية التجريبية']);
            }
        });
    }
}
