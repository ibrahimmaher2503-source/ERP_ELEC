<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;

final class SubledgerFinancialHistory
{
    public function values(int $actor, array $branches, ?string $to): array
    {
        $to ??= now('Africa/Cairo')->toDateString();
        $movements = array_column(app(TreasuryApi::class)->reconciliationMovements($actor, $branches, $to), 'id');
        $balances = [];
        $credits = [];
        foreach (['supplier', 'customer'] as $party) {
            $original = (string) DB::table('rp_open_items')->where('party_type', $party)->whereIn('branch_id', $branches)
                ->where('document_date', '<=', $to)->sum('original_amount');
            $settled = (string) DB::table('rp_settlement_allocations as a')->join('rp_open_items as i', 'i.id', '=', 'a.open_item_id')
                ->where('i.party_type', $party)->whereIn('i.branch_id', $branches)->whereIn('a.movement_id', $movements)->sum('a.amount');
            $reversed = (string) DB::table('rp_settlement_allocations as a')->join('rp_cash_reversals as r', 'r.original_movement_id', '=', 'a.movement_id')
                ->join('rp_open_items as i', 'i.id', '=', 'a.open_item_id')->where('i.party_type', $party)->whereIn('i.branch_id', $branches)->where('r.effective_date', '<=', $to)->sum('a.amount');
            $settled = bcsub($settled, $reversed, 2);
            if ($party === 'customer') {
                $applied = (string) DB::table('rp_customer_advance_application_lines as l')->join('rp_customer_advance_applications as a', 'a.id', '=', 'l.application_id')
                    ->join('rp_open_items as i', 'i.id', '=', 'l.open_item_id')->whereIn('i.branch_id', $branches)->where('a.effective_date', '<=', $to)->sum('l.amount');
                $settled = bcadd($settled, $applied, 2);
            }
            $notes = (string) DB::table('rp_credit_notes')->where('kind', $party)->whereIn('branch_id', $branches)
                ->where('status', 'posted')->where('note_date', '<=', $to)->sum('amount');
            // A note reduces the control by its full amount, including its unapplied party-credit portion.
            $balances[$party] = bcsub(bcsub($original, $settled, 2), $notes, 2);
            $credits[$party] = bcadd((string) DB::table('rp_party_credits as c')->join('rp_credit_notes as n', 'n.id', '=', 'c.credit_note_id')
                ->where('c.party_type', $party)->whereIn('c.branch_id', $branches)->where('n.status', 'posted')->where('n.note_date', '<=', $to)->sum('c.original_amount'), '0', 2);
            $refunded = (string) DB::table('rp_party_credit_refunds as r')->join('rp_party_credits as c', 'c.id', '=', 'r.credit_id')
                ->where('c.party_type', $party)->whereIn('c.branch_id', $branches)->where('r.refund_date', '<=', $to)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rp_cash_reversals')->whereColumn('original_movement_id', 'r.movement_id')->where('effective_date', '<=', $to))->sum('r.amount');
            $balances[$party] = bcadd($balances[$party], $refunded, 2);
            $credits[$party] = bcsub($credits[$party], $refunded, 2);
        }

        return ['ap' => $balances['supplier'], 'ar' => $balances['customer'],
            'supplier_credits' => $credits['supplier'], 'customer_credits' => $credits['customer'],
            'customer_advances' => bcsub(bcsub((string) DB::table('rp_customer_advances')->whereIn('branch_id', $branches)->whereIn('movement_id', $movements)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rp_cash_reversals')->whereColumn('original_movement_id', 'rp_customer_advances.movement_id')->where('effective_date', '<=', $to))->sum('original_amount'),
                (string) DB::table('rp_customer_advance_applications as a')->join('rp_customer_advances as p', 'p.id', '=', 'a.advance_id')->whereIn('p.branch_id', $branches)->where('a.effective_date', '<=', $to)->sum('a.amount'), 2),
                (string) DB::table('rp_customer_advance_refunds as r')->join('rp_customer_advances as p', 'p.id', '=', 'r.advance_id')->whereIn('p.branch_id', $branches)->where('r.refund_date', '<=', $to)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rp_cash_reversals')->whereColumn('original_movement_id', 'r.movement_id')->where('effective_date', '<=', $to))->sum('r.amount'), 2),
            'grni_cleared' => bcadd((string) DB::table('rp_supplier_invoice_matches as m')->join('rp_supplier_invoice_lines as l', 'l.id', '=', 'm.invoice_line_id')
                ->join('rp_supplier_invoices as i', 'i.id', '=', 'l.invoice_id')->whereIn('i.branch_id', $branches)
                ->whereIn('i.status', ['posted', 'partially_paid', 'paid', 'credited'])->where('i.invoice_date', '<=', $to)->sum('m.grni_amount'), '0', 2)];
    }
}
