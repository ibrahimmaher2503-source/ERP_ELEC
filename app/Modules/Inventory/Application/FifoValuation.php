<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FifoValuation
{
    public function receive(int $movement, string $key, string $date, string $amount, ?string $unitCost = null): int
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $m = DB::table('inventory_movements')->find($movement);
        abort_unless($m && bccomp($m->quantity, '0', 8) > 0, 422);

        return DB::table('inventory_fifo_layers')->insertGetId(['source_key' => $key, 'movement_id' => $movement, 'warehouse_id' => $m->warehouse_id,
            'product_id' => $m->product_id, 'received_on' => $date, 'original_quantity' => $m->quantity, 'remaining_quantity' => $m->quantity,
            'original_value' => $amount, 'remaining_value' => $amount, 'unit_cost' => $unitCost ?? bcdiv($amount, $m->quantity, 16), 'created_at' => now()]);
    }

    /** Used only by an authorized owner transaction with its matching الأستاذ العام effect. */
    public function consume(int $movement, ?int $onlyLayer = null): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $m = DB::table('inventory_movements')->find($movement);
        abort_unless($m && bccomp($m->quantity, '0', 8) < 0, 422);
        $left = bcsub('0', $m->quantity, 8);
        $total = '0.00';
        $rawTotal = '0.0000000000000000';
        $date = CarbonImmutable::parse($m->posted_at)->setTimezone('Africa/Cairo')->toDateString();
        $layers = DB::table('inventory_fifo_layers')->where('warehouse_id', $m->warehouse_id)->where('product_id', $m->product_id)->where('received_on', '<=', $date)->where('remaining_quantity', '>', '0')
            ->when($onlyLayer !== null, fn ($q) => $q->where('id', $onlyLayer))->orderBy('received_on')->orderBy('id')->lockForUpdate()->get();
        if (DB::table('inventory_fifo_allocations')->where('movement_id', $movement)->exists()) {
            InventoryActions::fail('الصرف من الأقدم أولًا allocation already exists for this movement.');
        }
        foreach ($layers as $layer) {
            if (bccomp($left, '0', 8) === 0) {
                break;
            }
            $take = bccomp($left, $layer->remaining_quantity, 8) < 0 ? $left : $layer->remaining_quantity;
            $full = bccomp($take, $layer->remaining_quantity, 8) === 0;
            $raw = $full ? $layer->remaining_value : bcdiv(bcmul($layer->remaining_value, $take, Decimal::SCALE), $layer->remaining_quantity, 16);
            $remaining = bcsub($layer->remaining_value, $raw, 16);
            $cumulative = bcsub($layer->original_value, $remaining, 16);
            $amount = bcsub(Decimal::round($cumulative), $layer->allocated_amount, 2);
            DB::table('inventory_fifo_allocations')->insert(['movement_id' => $movement, 'layer_id' => $layer->id, 'quantity' => $take, 'raw_value' => $raw, 'amount' => $amount]);
            DB::table('inventory_fifo_layers')->where('id', $layer->id)->update(['remaining_quantity' => bcsub($layer->remaining_quantity, $take, 8),
                'remaining_value' => $remaining, 'allocated_amount' => bcadd($layer->allocated_amount, $amount, 2)]);
            $left = bcsub($left, $take, 8);
            $total = bcadd($total, $amount, 2);
            $rawTotal = bcadd($rawTotal, $raw, 16);
        }
        if (bccomp($left, '0', 8) !== 0) {
            InventoryActions::fail('طبقات الصرف من الأقدم أولًا لا تكفي. لن يتم إنشاء تكلفة أو مخزون بديل.');
        }

        return ['amount' => $total, 'raw_value' => $rawTotal];
    }
}
