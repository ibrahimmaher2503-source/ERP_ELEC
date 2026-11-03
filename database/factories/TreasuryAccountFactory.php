<?php

namespace Database\Factories;

use App\Modules\Treasury\Domain\TreasuryAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

final class TreasuryAccountFactory extends Factory
{
    protected $model = TreasuryAccount::class;

    public function definition(): array
    {
        $kind = DB::table('treasury_accounts')->where('kind', 'bank')->exists() ? 'cash' : 'bank';
        $account = DB::table('accounting_account_mappings')->where('purpose', $kind)->value('account_id');
        if (! $account) {
            throw new \RuntimeException('Configured synthetic '.$kind.' account mapping required.');
        }

        return ['code' => 'DEMO-EG-'.strtoupper($kind), 'name' => $kind === 'bank' ? 'الحساب البنكي التشغيلي التجريبي' : 'الخزينة النقدية التشغيلية التجريبية',
            'kind' => $kind, 'gl_account_id' => $account, 'active' => true];
    }
}
