<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\ReceivablesPayables\Application\Contracts\OpeningPayables;
use Illuminate\Support\Facades\DB;

final class PostOpeningPayables implements OpeningPayables
{
    public function postOpening(int $actor, int $batch, string $date, array $lines): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.financial_activate'));
        abort_if(DB::table('payable_opening_items')->exists(), 409, 'Supplier openings already exist.');
        foreach ($lines as $line) {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.opening_review', $line['branch_id']));
            app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, $line['supplier_id']));
            $item = DB::table('payable_opening_items')->insertGetId(['source_key' => 'opening:'.$batch.':'.$line['reference'],
                'branch_id' => $line['branch_id'], 'supplier_id' => $line['supplier_id'], 'reference' => $line['reference'],
                'opening_date' => $date, 'amount' => $line['amount'], 'actor_id' => $actor, 'created_at' => now()]);
            if (bccomp($line['amount'], '0', 2) > 0) {
                DB::table('rp_open_items')->insert(['branch_id' => $line['branch_id'], 'party_type' => 'supplier',
                    'party_id' => $line['supplier_id'], 'source_type' => 'opening_payable', 'source_id' => $item,
                    'source_number' => $line['reference'], 'document_date' => $date, 'due_date' => $date,
                    'original_amount' => $line['amount'], 'created_at' => now()]);
            }
        }
    }
}
