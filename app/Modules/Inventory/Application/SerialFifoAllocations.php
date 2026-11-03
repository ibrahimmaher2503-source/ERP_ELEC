<?php

namespace App\Modules\Inventory\Application;

use Illuminate\Support\Facades\DB;

final class SerialFifoAllocations
{
    public function receipt(int $movement, int $allocation): void
    {
        $original = DB::table('inventory_fifo_allocations')->find($allocation);
        abort_unless(DB::transactionLevel() > 0 && $original, 500);
        $ids = DB::table('inventory_serial_events')->where('movement_id', $movement)->pluck('serial_id');
        $source = DB::table('inventory_fifo_serial_allocations')->where('allocation_id', $allocation)->where('movement_id', $original->movement_id)->whereIn('serial_id', $ids)->get();
        abort_unless($source->count() === $ids->count(), 422, 'الأرقام لا تطابق تخصيص التكلفة الأصلي.');
        $total = '0.00';
        foreach ($source as $row) {
            DB::table('inventory_fifo_serial_allocations')->insert(['serial_id' => $row->serial_id, 'movement_id' => $movement, 'allocation_id' => $allocation, 'amount' => $row->amount]);
            $total = bcadd($total, $row->amount, 2);
        }
        abort_unless(bccomp($total, (string) DB::table('inventory_fifo_layers')->where('movement_id', $movement)->value('original_value'), 2) === 0, 422, 'قيمة عودة الأرقام لا تطابق تكلفتها الأصلية.');
    }

    /** Physical identities inherit the cents actually allocated by the approved FIFO cost flow. */
    public function record(int $movement): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $serials = DB::table('inventory_serial_events as e')->join('inventory_serials as s', 's.id', '=', 'e.serial_id')->where('e.movement_id', $movement)->orderByRaw('s.normalized COLLATE "C"')->pluck('s.id')->all();
        $position = 0;
        foreach (DB::table('inventory_fifo_allocations')->where('movement_id', $movement)->orderBy('id')->get() as $allocation) {
            $count = (int) $allocation->quantity;
            abort_unless($count > 0 && bccomp((string) $count, $allocation->quantity, 8) === 0 && $position + $count <= count($serials), 422, 'تكلفة FIFO لا تطابق الوحدات المسلسلة.');
            $each = bcdiv($allocation->amount, (string) $count, 2);
            $assigned = '0.00';
            for ($i = 0; $i < $count; $i++) {
                $amount = $i === $count - 1 ? bcsub($allocation->amount, $assigned, 2) : $each;
                DB::table('inventory_fifo_serial_allocations')->insert(['serial_id' => $serials[$position++], 'movement_id' => $movement, 'allocation_id' => $allocation->id, 'amount' => $amount]);
                $assigned = bcadd($assigned, $amount, 2);
            }
        }
        abort_unless($position === count($serials), 422, 'رقم مسلسل بلا تكلفة مصدر.');
    }
}
