<?php

namespace App\Modules\Inventory\Application;

use Illuminate\Support\Facades\DB;

final class InventorySerials
{
    public function normalize(string $entered): string
    {
        $text = class_exists(\Normalizer::class) ? \Normalizer::normalize($entered, \Normalizer::FORM_KC) : $entered;
        $text = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
        if ($text === '' || mb_strlen($entered) > 200 || mb_strlen($text) > 200 || preg_match('/[\x00-\x1f\x7f]/u', $text)) {
            InventoryActions::fail('هوية الرقم التسلسلي غير صالحة.');
        }

        return $text;
    }

    public function selections(mixed $values, string $quantity): array
    {
        if (is_string($values)) {
            $values = preg_split('/\R/u', $values, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (! is_array($values) || count($values) > 1000 || bccomp($quantity, (string) count($values), 8) !== 0) {
            InventoryActions::fail('اختر رقمًا تسلسليًا واحدًا بالضبط لكل وحدة أساس كاملة.');
        }
        $out = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                InventoryActions::fail('الرقم التسلسلي غير صالح.');
            }
            $normalized = $this->normalize($value);
            if (isset($out[$normalized])) {
                InventoryActions::fail('الرقم التسلسلي مكرر.');
            }
            $out[$normalized] = trim($value);
        }
        ksort($out);

        return $out;
    }

    private function event(int $actor, int $serial, ?int $movement, string $action): void
    {
        $row = DB::table('inventory_serials')->find($serial);
        DB::table('inventory_serial_events')->insert(['serial_id' => $serial, 'movement_id' => $movement, 'action' => $action, 'snapshot' => json_encode($row, JSON_THROW_ON_ERROR), 'actor_id' => $actor, 'occurred_at' => now()]);
    }

    private function update(int $actor, object $row, array $change, string $action, ?int $movement = null): void
    {
        DB::table('inventory_serials')->where('id', $row->id)->update($change + ['version' => $row->version + 1, 'updated_at' => now()]);
        $this->event($actor, $row->id, $movement, $action);
    }

    /** Called only by InventoryLedger in its authorized, locked transaction. */
    public function post(int $actor, array $line, object $movement): void
    {
        $amount = bccomp($movement->quantity, '0', 8) < 0 ? bcsub('0', $movement->quantity, 8) : $movement->quantity;
        $values = $this->selections($line['serials'] ?? [], $amount);
        foreach ($values as $normalized => $entered) {
            $row = DB::table('inventory_serials')->where('product_id', $movement->product_id)->where('normalized', $normalized)->lockForUpdate()->first();
            $positive = bccomp($movement->quantity, '0', 8) > 0;
            if ($positive && ! $row) {
                if ($movement->operation === 'transfer_receive' || $movement->reversal_id !== null) {
                    InventoryActions::fail('هوية الرقم التسلسلي الأصلي مطلوبة.');
                }
                $id = DB::table('inventory_serials')->insertGetId(['product_id' => $movement->product_id, 'normalized' => $normalized, 'entered' => $entered, 'warehouse_id' => $movement->warehouse_id, 'state' => 'available', 'source_movement_id' => $movement->id, 'last_movement_id' => $movement->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $this->event($actor, $id, $movement->id, 'received');

                continue;
            }
            if (! $row) {
                InventoryActions::fail('الرقم التسلسلي غير محتفظ به في المخزون.');
            }
            if ($positive) {
                $transfer = $movement->operation === 'transfer_receive' && $row->state === 'transit' && $row->transfer_id === $movement->document_id;
                $reversal = $movement->reversal_id !== null && $row->state === 'issued' && $row->last_movement_id === $movement->reversal_id;
                if (! $transfer && ! $reversal) {
                    InventoryActions::fail('هوية الرقم التسلسلي مسجلة مسبقًا؛ يُمنع استلامه مكررًا بشكل مستقل.');
                }
                $change = ['warehouse_id' => $movement->warehouse_id, 'state' => 'available', 'transfer_id' => null, 'reservation_id' => null];
            } else {
                $expected = isset($line['reservation_id']) ? 'reserved' : 'available';
                if ($row->warehouse_id !== $movement->warehouse_id || $row->state !== $expected || ($expected === 'reserved' && $row->reservation_id !== ($line['reservation_id'] ?? null))) {
                    InventoryActions::fail('الرقم التسلسلي غير متاح لهذا المصدر والمستودع.');
                }
                if ($movement->reversal_id !== null && ! DB::table('inventory_serial_events')->where('serial_id', $row->id)->where('movement_id', $movement->reversal_id)->exists()) {
                    InventoryActions::fail('الرقم التسلسلي للتصحيح لا يتبع الحركة الأصلية.');
                }
                $change = ['state' => $movement->operation === 'transfer_dispatch' ? 'transit' : 'issued', 'warehouse_id' => $movement->operation === 'transfer_dispatch' ? null : $movement->warehouse_id, 'transfer_id' => $movement->operation === 'transfer_dispatch' ? $movement->document_id : null, 'reservation_id' => null];
            }
            $this->update($actor, $row, $change + ['last_movement_id' => $movement->id], $movement->operation, $movement->id);
        }
    }

    public function reserve(int $actor, int $reservation, int $warehouse, int $product, string $amount): void
    {
        if (bccomp($amount, (string) (int) $amount, 8) !== 0) {
            InventoryActions::fail('حجوزات الأرقام التسلسلية تتطلب كميات صحيحة.');
        }
        $rows = DB::table('inventory_serials')->where('warehouse_id', $warehouse)->where('product_id', $product)->where('state', 'available')->orderBy('id')->limit((int) $amount)->lockForUpdate()->get();
        if ($rows->count() !== (int) $amount) {
            InventoryActions::fail('إشغال الأرقام التسلسلية لا يطابق الكمية القابلة للتخصيص.');
        }
        foreach ($rows as $row) {
            $this->update($actor, $row, ['state' => 'reserved', 'reservation_id' => $reservation], 'reserved');
        }
    }

    public function reserved(int $reservation, string $amount): array
    {
        if (bccomp($amount, (string) (int) $amount, 8) !== 0) {
            InventoryActions::fail('صرف الأرقام التسلسلية يتطلب كميات صحيحة.');
        }
        $values = DB::table('inventory_serials')->where('reservation_id', $reservation)->where('state', 'reserved')->orderBy('id')->limit((int) $amount)->pluck('entered')->all();
        if (count($values) !== (int) $amount) {
            InventoryActions::fail('الأرقام التسلسلية المحجوزة غير متاحة.');
        }

        return $values;
    }

    public function releaseReservation(int $actor, int $reservation): void
    {
        foreach (DB::table('inventory_serials')->where('reservation_id', $reservation)->where('state', 'reserved')->lockForUpdate()->get() as $row) {
            $this->update($actor, $row, ['state' => 'available', 'reservation_id' => null], 'reservation_released');
        }
    }

    public function condition(int $actor, int $isolation, int $warehouse, int $product, string $quantity, array $values, bool $release): void
    {
        foreach ($this->selections($values, $quantity) as $normalized => $entered) {
            $row = DB::table('inventory_serials')->where('product_id', $product)->where('normalized', $normalized)->lockForUpdate()->first();
            if (! $row || $row->warehouse_id !== $warehouse || $row->state !== ($release ? 'unavailable' : 'available') || ($release && $row->isolation_id !== $isolation)) {
                InventoryActions::fail('حالة الرقم التسلسلي أو مصدره غير مطابقة.');
            }
            $this->update($actor, $row, ['state' => $release ? 'available' : 'unavailable', 'isolation_id' => $release ? null : $isolation], $release ? 'isolation_released' : 'isolated');
        }
    }
}
