<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\ReceivablesPayables\Application\Contracts\OpeningReceivables;
use Illuminate\Support\Facades\DB;

final class PostOpeningReceivables implements OpeningReceivables
{
    public function postOpening(int $actor, int $batch, string $date, array $lines): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.financial_activate'));
        abort_if(DB::table('receivable_opening_items')->exists(), 409, 'Customer openings already exist.');
        foreach ($lines as $line) {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.opening_review', $line['branch_id']));
            app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, $line['customer_id']));
            $item = DB::table('receivable_opening_items')->insertGetId(['source_key' => 'opening:'.$batch.':'.$line['reference'],
                'branch_id' => $line['branch_id'], 'customer_id' => $line['customer_id'], 'reference' => $line['reference'],
                'opening_date' => $date, 'amount' => $line['amount'], 'actor_id' => $actor, 'created_at' => now()]);
            if (bccomp($line['amount'], '0', 2) > 0) {
                DB::table('rp_open_items')->insert(['branch_id' => $line['branch_id'], 'party_type' => 'customer',
                    'party_id' => $line['customer_id'], 'source_type' => 'opening_receivable', 'source_id' => $item,
                    'source_number' => $line['reference'], 'document_date' => $date, 'due_date' => $date,
                    'original_amount' => $line['amount'], 'created_at' => now()]);
            }
        }
    }
}
