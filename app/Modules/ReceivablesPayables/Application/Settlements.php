<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Settlements
{
    public function prepare(int $actor, string $partyType, int $partyId, array $allocations): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        Validator::make(compact('partyType', 'partyId', 'allocations'), [
            'partyType' => 'required|in:supplier,customer', 'partyId' => 'required|integer|min:1',
            'allocations' => 'required|array|min:1|max:500', 'allocations.*.open_item_id' => 'required|integer|min:1',
            'allocations.*.amount' => 'required|string',
        ])->validate();
        $permission = $partyType === 'supplier' ? 'ap_view' : 'ar_view';
        $ids = array_map(fn ($allocation) => (int) $allocation['open_item_id'], $allocations);
        if (count($ids) !== count(array_unique($ids))) {
            $this->fail('لا يجوز تكرار بند الذمة في التخصيص.');
        }
        $items = DB::table('rp_open_items')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($items->count() !== count($ids)) {
            $this->fail('أحد بنود الذمة غير موجود.');
        }
        $branch = null;
        $total = '0.00';
        foreach ($allocations as $allocation) {
            $amount = Decimal::round(Decimal::value($allocation['amount'], 2));
            $item = $items[(int) $allocation['open_item_id']];
            $branch ??= (int) $item->branch_id;
            app(ReceivablesPayablesAccess::class)->authorize($actor, $permission, (int) $item->branch_id);
            if ($item->party_type !== $partyType || (int) $item->party_id !== $partyId || (int) $item->branch_id !== $branch
                || bccomp($amount, '0', 2) <= 0 || bccomp($amount, $item->remaining_amount, 2) > 0) {
                $this->fail('التخصيص لا يطابق الطرف والفرع أو يتجاوز الرصيد المتبقي.');
            }
            $total = bcadd($total, $amount, 2);
        }

        return ['branch_id' => $branch, 'amount' => $total, 'items' => $items->map(fn ($item) => (array) $item)->all()];
    }

    public function complete(int $actor, int $movementId, string $partyType, array $allocations): void
    {
        abort_unless(DB::transactionLevel() > 0 && DB::table('treasury_movements')->where('id', $movementId)->exists(), 500);
        foreach ($allocations as $allocation) {
            $item = DB::table('rp_open_items')->lockForUpdate()->find((int) $allocation['open_item_id']);
            abort_unless($item && $item->party_type === $partyType, 409);
            $amount = Decimal::round($allocation['amount']);
            if (bccomp($amount, $item->remaining_amount, 2) > 0) {
                $this->fail('تغير رصيد الذمة أثناء التنفيذ؛ ألغيت المعاملة كاملة.');
            }
            DB::table('rp_settlement_allocations')->insert(['movement_id' => $movementId, 'open_item_id' => $item->id, 'amount' => $amount, 'created_at' => now()]);
            DB::table('rp_open_items')->where('id', $item->id)->update(['settled_amount' => bcadd($item->settled_amount, $amount, 2)]);
            $remaining = bcsub($item->remaining_amount, $amount, 2);
            $table = $partyType === 'supplier' ? 'rp_supplier_invoices' : 'rp_customer_invoices';
            if (in_array($item->source_type, ['supplier_invoice', 'customer_invoice'], true)) {
                DB::table($table)->where('id', $item->source_id)->update(['status' => bccomp($remaining, '0', 2) === 0
                    ? ($partyType === 'supplier' ? 'paid' : 'collected')
                    : ($partyType === 'supplier' ? 'partially_paid' : 'partially_collected'), 'updated_at' => now()]);
            }
            app(RecordAudit::class)->execute($actor, 'open_item', $item->id, 'open_item.settled', null,
                ['movement_id' => $movementId, 'amount' => $amount, 'remaining' => $remaining], 'succeeded', 'ReceivablesPayables');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['allocation' => $message]);
    }
}
