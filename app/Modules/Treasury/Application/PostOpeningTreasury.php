<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Treasury\Application\Contracts\OpeningTreasury;
use Illuminate\Support\Facades\DB;

final class PostOpeningTreasury implements OpeningTreasury
{
    public function postOpening(int $actor, int $batch, string $date, array $lines): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.financial_activate'));
        abort_if(DB::table('treasury_opening_balances')->exists(), 409, 'Treasury openings already exist.');
        foreach ($lines as $line) {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.opening_review', $line['branch_id']));
            DB::table('treasury_opening_balances')->insert(['source_key' => 'opening:'.$batch.':'.$line['kind'].':'.$line['reference'],
                'branch_id' => $line['branch_id'], 'kind' => $line['kind'], 'reference' => $line['reference'],
                'opening_date' => $date, 'amount' => $line['amount'], 'actor_id' => $actor, 'created_at' => now()]);
        }
    }
}
