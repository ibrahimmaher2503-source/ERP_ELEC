<?php

namespace App\Modules\Treasury\Application;

use Illuminate\Support\Facades\DB;

final class PartyMovements
{
    public function read(int $actor, string $type, int $party, array $branchIds, int $page): array
    {
        abort_unless(in_array($type, ['customer', 'supplier'], true) && $party > 0, 422);
        $permission = $type === 'customer' ? 'collection_execute' : 'payment_prepare';
        $allowed = array_map('intval', array_column(app(TreasuryAccess::class)->branches($actor, $permission), 'id'));
        $ids = array_values(array_intersect($allowed, array_map('intval', $branchIds)));
        if (! $ids) {
            return ['authorized' => false];
        }
        $query = DB::table('treasury_movements')->whereIn('branch_id', $ids)->where('party_id', $party)
            ->where('kind', $type === 'customer' ? 'customer_collection' : 'supplier_payment');
        $summary = (clone $query)->selectRaw('count(*) as count, coalesce(sum(amount),0)::numeric(20,2) as amount, max(movement_date) as last_date')->first();
        $rows = (clone $query)->orderByDesc('movement_date')->orderByDesc('id')->paginate(10, ['id', 'branch_id', 'movement_date', 'amount', 'reference', 'journal_id', 'account_id'], 'movements', max(1, $page));

        return ['authorized' => true, 'total_amount' => $summary->amount, 'last_date' => $summary->last_date,
            'rows' => ['items' => $rows->items(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]];
    }
}
