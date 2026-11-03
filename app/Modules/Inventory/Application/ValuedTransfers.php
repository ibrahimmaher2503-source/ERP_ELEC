<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;

/** Inventory alone owns transit lots. Each lot retains its original FIFO date. */
final class ValuedTransfers
{
    public function dispatch(int $movement): void
    {
        $m = DB::table('inventory_movements')->find($movement);
        foreach (DB::table('inventory_fifo_allocations')->where('movement_id', $movement)->get() as $allocation) {
            $layer = DB::table('inventory_fifo_layers')->find($allocation->layer_id);
            DB::table('inventory_transfer_lots')->insert([
                'document_id' => $m->document_id, 'line_id' => (int) $m->source_line,
                'allocation_id' => $allocation->id, 'received_on' => $layer->received_on,
                'original_quantity' => $allocation->quantity, 'remaining_quantity' => $allocation->quantity,
                'original_value' => $allocation->amount, 'remaining_value' => $allocation->amount,
                'allocated_amount' => '0.00',
            ]);
        }
    }

    public function receive(int $actor, object $doc, object $line, string $quantity, string $key, array $serials = []): array
    {
        $left = $quantity;
        $movements = [];
        $product = app(ProductsApi::class)->inventoryProduct($actor, (int) $line->product_id);
        $serial = $product['tracking'] === 'serial';
        $selected = $serial ? app(InventorySerials::class)->selections($serials, $quantity) : [];
        abort_unless($serial || $serials === [], 422);
        $lots = DB::table('inventory_transfer_lots')->where('document_id', $doc->id)->where('line_id', $line->id)
            ->where('remaining_quantity', '>', '0')->orderBy('received_on')->orderBy('id')->lockForUpdate()->get();
        foreach ($lots as $lot) {
            if (bccomp($left, '0', 8) === 0) {
                break;
            }
            $take = bccomp($left, $lot->remaining_quantity, 8) < 0 ? $left : $lot->remaining_quantity;
            $raw = bccomp($take, $lot->remaining_quantity, 8) === 0 ? $lot->remaining_value
                : bcdiv(bcmul($lot->remaining_value, $take, Decimal::SCALE), $lot->remaining_quantity, 16);
            $remaining = bcsub($lot->remaining_value, $raw, 16);
            $amount = bcsub(Decimal::round(bcsub($lot->original_value, $remaining, 16)), $lot->allocated_amount, 2);
            $movementKey = $key.':lot:'.$lot->id;
            $originalAllocation = DB::table('inventory_fifo_allocations')->find($lot->allocation_id);
            $originalLayer = DB::table('inventory_fifo_layers')->find($originalAllocation->layer_id);
            $names = [];
            if ($serial) {
                $mapped = DB::table('inventory_fifo_serial_allocations as a')->join('inventory_serials as s', 's.id', '=', 'a.serial_id')
                    ->where('a.allocation_id', $originalAllocation->id)->where('a.movement_id', $originalAllocation->movement_id)->whereIn('s.normalized', array_keys($selected))->orderByRaw('s.normalized COLLATE "C"')->get(['s.normalized', 'a.amount']);
                if ($mapped->isEmpty()) {
                    continue;
                }
                $take = (string) $mapped->count();
                abort_unless(bccomp($take, $lot->remaining_quantity, 8) <= 0, 422);
                $amount = $mapped->reduce(fn ($sum, $row) => bcadd($sum, $row->amount, 2), '0.00');
                $raw = $amount;
                $remaining = bcsub($lot->remaining_value, $raw, 16);
                foreach ($mapped as $item) {
                    $names[] = $selected[$item->normalized];
                    unset($selected[$item->normalized]);
                }
            }
            app(InventoryLedger::class)->post($actor, $doc->destination_id,
                array_replace((array) $line, ['serials' => $names, 'serial_allocation_id' => $originalAllocation->id, 'transfer_lot_id' => $lot->id, 'transfer_amount' => $amount, 'fifo_date' => $lot->received_on, 'transfer_unit_cost' => $originalLayer->unit_cost]),
                $take, 'transfer_receive', 'Inventory', (string) $doc->id, (string) $line->id, $movementKey, $doc->id);
            $movement = DB::table('inventory_movements')->where('idempotency_key', $movementKey)->value('id');
            DB::table('inventory_transfer_allocations')->insert(['lot_id' => $lot->id, 'movement_id' => $movement, 'quantity' => $take, 'raw_value' => $raw, 'amount' => $amount]);
            DB::table('inventory_transfer_lots')->where('id', $lot->id)->update([
                'remaining_quantity' => bcsub($lot->remaining_quantity, $take, 8), 'remaining_value' => $remaining,
                'allocated_amount' => bcadd($lot->allocated_amount, $amount, 2),
            ]);
            $movements[] = $movement;
            $left = bcsub($left, $take, 8);
        }
        if (bccomp($left, '0', 8) !== 0) {
            InventoryActions::fail('طبقات المخزون بالطريق لا تكفي للاستلام.');
        }

        return $movements;
    }
}
