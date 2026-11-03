<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\System\Application\RecordAudit;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;

final class CashReversals
{
    public function prepare(int $actor, int $original, string $date): array
    {
        $source = app(TreasuryApi::class)->cashCorrectionSource($actor, $original, $date);
        abort_unless($source['party_type'] !== null, 422);
        app(ReceivablesPayablesAccess::class)->authorize($actor, $source['party_type'] === 'customer' ? 'ar_view' : 'ap_view', $source['branch_id']);
        abort_if(DB::table('rp_cash_reversals')->where('original_movement_id', $original)->exists(), 409, 'حركة المصدر مصححة بالفعل.');
        if ($source['kind'] === 'customer_collection') {
            $advance = DB::table('rp_customer_advances')->where('movement_id', $original)->lockForUpdate()->first();
            if ($advance) {
                abort_unless(bccomp(app(CustomerAdvances::class)->remaining((int) $advance->id), $advance->original_amount, 2) === 0, 409, 'المقدم مستهلك؛ صحّح استخداماته المرتبطة قبل أصل التحصيل.');
            }
        }

        return $source;
    }

    public function complete(int $actor, int $original, int $movement, string $date): void
    {
        $source = $this->prepare($actor, $original, $date);
        app(TreasuryApi::class)->assertCashCorrection($actor, $original, $movement, $date);
        DB::table('rp_cash_reversals')->insert(['original_movement_id' => $original, 'movement_id' => $movement, 'effective_date' => $date, 'created_by' => $actor]);
        if (in_array($source['kind'], ['customer_collection', 'supplier_payment'], true)) {
            foreach (DB::table('rp_settlement_allocations')->where('movement_id', $original)->orderBy('open_item_id')->get() as $allocation) {
                $item = DB::table('rp_open_items')->lockForUpdate()->find($allocation->open_item_id);
                abort_unless($item && bccomp($item->settled_amount, $allocation->amount, 2) >= 0, 409);
                $settled = bcsub($item->settled_amount, $allocation->amount, 2);
                DB::table('rp_open_items')->where('id', $item->id)->update(['settled_amount' => $settled]);
                if (in_array($item->source_type, ['customer_invoice', 'supplier_invoice'], true)) {
                    $remaining = bcsub(bcsub($item->original_amount, $settled, 2), $item->credited_amount, 2);
                    $status = bccomp($remaining, '0', 2) === 0 ? ($source['party_type'] === 'customer' ? 'collected' : 'paid')
                        : (bccomp($settled, '0', 2) > 0 ? ($source['party_type'] === 'customer' ? 'partially_collected' : 'partially_paid') : 'posted');
                    $table = $source['party_type'] === 'customer' ? 'rp_customer_invoices' : 'rp_supplier_invoices';
                    $invoice = DB::table($table)->find($item->source_id);
                    DB::table($table)->where('id', $item->source_id)->update(['status' => $invoice->status === 'credited' ? 'credited' : $status, 'updated_at' => now()]);
                }
            }
        } elseif (in_array($source['kind'], ['customer_credit_refund', 'supplier_credit_refund'], true)) {
            DB::table('rp_party_credits')->where('id', $source['credit_id'])->lockForUpdate()->firstOrFail();
            DB::table('rp_party_credits')->where('id', $source['credit_id'])->decrement('allocated_amount', $source['amount']);
        }
        app(RecordAudit::class)->execute($actor, 'cash_reversal', $movement, 'cash.source_reversed', null,
            ['original_movement_id' => $original, 'movement_id' => $movement, 'effective_date' => $date], 'succeeded', 'ReceivablesPayables');
    }
}
