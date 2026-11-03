<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use Illuminate\Support\Facades\DB;

final class ValuedCorrections
{
    /** Reverse one original FIFO allocation at a time, with exact cumulative cents. */
    public function post(int $actor, object $source, string $quantity, string $key, array $serials, string $operation = 'correction'): int
    {
        $line = ['product_id' => $source->product_id, 'base_unit_id' => $source->base_unit_id,
            'sku' => $source->sku, 'product_name' => $source->product_name, 'serials' => $serials];
        $allocation = null;
        $sourceLayer = null;
        $raw = '0';
        $serialMappings = null;
        if ($serials !== []) {
            $names = array_keys(app(InventorySerials::class)->selections($serials, $quantity));
            $serialMappings = DB::table('inventory_fifo_serial_allocations as a')->join('inventory_serials as s', 's.id', '=', 'a.serial_id')
                ->where('a.movement_id', $source->id)->whereIn('s.normalized', $names)->get(['a.*']);
            if (bccomp($source->quantity, '0', 8) < 0) {
                abort_unless($serialMappings->count() === count($names) && $serialMappings->pluck('allocation_id')->unique()->count() === 1, 422, 'قسم عودة الأرقام حسب تخصيص FIFO الأصلي.');
            }
        }
        if (bccomp($source->quantity, '0', 8) < 0) {
            foreach (DB::table('inventory_fifo_allocations')->where('movement_id', $source->id)->orderBy('id')->get() as $candidate) {
                if ($serialMappings !== null && (int) $candidate->id !== (int) $serialMappings->first()->allocation_id) {
                    continue;
                }
                $used = DB::table('inventory_fifo_corrections')->where('allocation_id', $candidate->id)
                    ->selectRaw('coalesce(sum(quantity),0) as quantity, coalesce(sum(raw_value),0) as raw, coalesce(sum(amount),0) as amount')->first();
                $remaining = bcsub($candidate->quantity, $used->quantity, 8);
                if (bccomp($remaining, '0', 8) === 0) {
                    continue;
                }
                abort_unless(bccomp($quantity, $remaining, 8) <= 0, 422, 'قسم التصحيح بحسب الكمية المتبقية لطبقة FIFO الأصلية.');
                $allocation = $candidate;
                $sourceLayer = DB::table('inventory_fifo_layers')->find($candidate->layer_id);
                $raw = bccomp($quantity, $remaining, 8) === 0 ? bcsub($candidate->amount, $used->raw, 16)
                    : bcdiv(bcmul($candidate->amount, $quantity, Decimal::SCALE), $candidate->quantity, 16);
                $amount = bcsub(Decimal::round(bcadd($used->raw, $raw, 16)), $used->amount, 2);
                if ($serialMappings !== null) {
                    $amount = $serialMappings->reduce(fn ($sum, $row) => bcadd($sum, $row->amount, 2), '0.00');
                    $raw = $amount;
                    $line['serial_allocation_id'] = (int) $candidate->id;
                }
                $line += ['correction_amount' => $amount, 'correction_date' => $sourceLayer->received_on, 'correction_unit_cost' => $sourceLayer->unit_cost];
                break;
            }
            abort_unless($allocation, 422, 'لا توجد تكلفة أصلية قابلة للتصحيح.');
            $delta = $quantity;
        } else {
            $sourceLayer = DB::table('inventory_fifo_layers')->where('movement_id', $source->id)->first();
            abort_unless($sourceLayer && bccomp($quantity, $sourceLayer->remaining_quantity, 8) <= 0, 422);
            $line['correction_layer_id'] = $sourceLayer->id;
            $delta = bcsub('0', $quantity, 8);
        }
        app(InventoryLedger::class)->post($actor, $source->warehouse_id, $line, $delta, $operation, 'Inventory',
            (string) $source->id, (string) $source->id, $key, $source->document_id, '0', $source->id);
        $movement = DB::table('inventory_movements')->where('idempotency_key', $key)->value('id');
        if ($allocation === null) {
            $consumed = DB::table('inventory_fifo_allocations')->where('movement_id', $movement)->first();
            abort_unless($consumed && $consumed->layer_id === $sourceLayer->id, 422);
            $raw = $consumed->raw_value;
            $amount = $consumed->amount;
        }
        DB::table('inventory_fifo_corrections')->insert(['movement_id' => $movement, 'original_movement_id' => $source->id,
            'allocation_id' => $allocation?->id, 'source_layer_id' => $sourceLayer->id, 'quantity' => $quantity, 'raw_value' => $raw, 'amount' => $amount]);

        return $movement;
    }
}
