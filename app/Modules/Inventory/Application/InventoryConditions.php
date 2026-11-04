<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\InventoryChanged;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

final class InventoryConditions
{
    public function isolate(int $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            InventoryActions::lock();
            $d = Validator::make($data, ['warehouse_id' => 'required|integer|min:1', 'product_id' => 'required|integer|min:1', 'source_movement_id' => 'required|integer|min:1', 'quantity' => 'required|string', 'reason' => 'required|string|max:2000|not_regex:/\x00/', 'idempotency_key' => 'required|uuid', 'serials' => 'sometimes|array'])->validate();
            app(InventoryAccess::class)->authorize($actor, 'isolation_manage', (int) $d['warehouse_id']);

            return $this->record($actor, $d);
        });
    }

    /** Transfer producer calls this only after destination receive authorization in the same transaction. */
    public function recordTransferDamage(int $actor, array $data): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        app(InventoryAccess::class)->authorize($actor, 'transfer_receive', (int) $data['warehouse_id']);
        $m = DB::table('inventory_movements')->find($data['source_movement_id']);
        abort_unless($m && $m->operation === 'transfer_receive' && $m->actor_id === $actor, 422);
        $expected = Uuid::uuid5(Uuid::NAMESPACE_URL, $m->idempotency_key.':damage')->toString();
        abort_unless(preg_match('/^transfer-receipt:([1-9][0-9]*):([1-9][0-9]*):damaged:lot:([1-9][0-9]*)$/D', $m->idempotency_key, $source) === 1, 422);
        $receipt = DB::table('inventory_transfer_receipts')->where('id', $source[1])->where('document_id', $m->document_id)->first();
        $lot = DB::table('inventory_transfer_lots as l')->join('inventory_transfer_allocations as a', 'a.lot_id', '=', 'l.id')
            ->where('l.id', $source[3])->where('l.document_id', $m->document_id)->where('l.line_id', $source[2])
            ->where('a.movement_id', $m->id)->first(['a.quantity']);
        abort_unless($receipt && $receipt->actor_id === $actor && $lot && $m->source_module === 'Inventory'
            && $m->source_id === (string) $m->document_id && $m->source_line === $source[2]
            && $data['idempotency_key'] === $expected && bccomp($data['quantity'], $m->quantity, 8) === 0
            && bccomp($lot->quantity, $m->quantity, 8) === 0, 422);

        return $this->record($actor, $data);
    }

    private function record(int $actor, array $d): array
    {
        $quantity = app(InventoryLedger::class)->quantity($d['quantity']);
        $values = $d['serials'] ?? [];
        sort($values);
        $hash = hash('sha256', json_encode([(int) $d['warehouse_id'], (int) $d['product_id'], (int) $d['source_movement_id'], $quantity, $d['reason'], $values], JSON_THROW_ON_ERROR));
        $old = DB::table('inventory_isolations')->where('idempotency_key', $d['idempotency_key'])->first();
        if ($old) {
            if ($old->payload_hash !== $hash) {
                InventoryActions::fail('مفتاح العزل مستخدم لمحتوى مختلف.');
            }

            return (array) $old;
        }
        $m = DB::table('inventory_movements')->find($d['source_movement_id']);
        if (! $m || $m->warehouse_id !== (int) $d['warehouse_id'] || $m->product_id !== (int) $d['product_id'] || bccomp($m->quantity, '0', 8) <= 0) {
            InventoryActions::fail('يلزم وجود حركة مصدر موجبة فعلية.');
        }
        $w = DB::table('inventory_warehouses')->find($m->warehouse_id);
        if (! $w->active) {
            InventoryActions::fail('المستودع غير نشط.');
        }
        $b = app(InventoryLedger::class)->balance($actor, $m->warehouse_id, $m->product_id);
        if (bccomp($quantity, $b->available, 8) > 0) {
            InventoryActions::fail('الكمية المعزولة تتجاوز الرصيد المتاح.');
        }
        $id = DB::table('inventory_isolations')->insertGetId(['idempotency_key' => $d['idempotency_key'], 'warehouse_id' => $m->warehouse_id, 'product_id' => $m->product_id, 'source_movement_id' => $m->id, 'quantity' => $quantity, 'released' => '0', 'reason' => $d['reason'], 'payload_hash' => $hash, 'actor_id' => $actor, 'occurred_at' => now()]);
        $p = app(ProductsApi::class)->inventoryProduct($actor, $m->product_id);
        if ($p['tracking'] === 'serial') {
            app(InventorySerials::class)->condition($actor, $id, $m->warehouse_id, $m->product_id, $quantity, $values, false);
        } elseif ($values !== []) {
            InventoryActions::fail('الأصناف بالكمية لا تقبل اختيارات أرقام تسلسلية.');
        }
        DB::table('inventory_balances')->where('id', $b->id)->update(['unavailable' => bcadd($b->unavailable, $quantity, 8), 'version' => $b->version + 1, 'updated_at' => now()]);
        DB::table('inventory_condition_events')->insert(['isolation_id' => $id, 'idempotency_key' => $d['idempotency_key'], 'action' => 'isolated', 'quantity' => $quantity, 'payload_hash' => $hash, 'actor_id' => $actor, 'occurred_at' => now()]);
        $out = (array) DB::table('inventory_isolations')->find($id);
        event(new InventoryChanged($actor, 'inventory_isolation', $id, 'isolated', null, $out));

        return $out;
    }

    public function release(int $actor, int $id, string $quantity, string $key, array $serials = []): array
    {
        return DB::transaction(function () use ($actor, $id, $quantity, $key, $serials) {
            InventoryActions::lock();
            Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();
            $row = DB::table('inventory_isolations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            app(InventoryAccess::class)->authorize($actor, 'isolation_release', $row->warehouse_id);
            $quantity = app(InventoryLedger::class)->quantity($quantity);
            sort($serials);
            $hash = hash('sha256', json_encode([$id, $quantity, $serials], JSON_THROW_ON_ERROR));
            $old = DB::table('inventory_condition_events')->where('idempotency_key', $key)->first();
            if ($old) {
                if ($old->action !== 'released' || $old->payload_hash !== $hash) {
                    InventoryActions::fail('تعارض في مفتاح الإفراج.');
                }

                return (array) $row;
            }
            if (bccomp($quantity, bcsub($row->quantity, $row->released, 8), 8) > 0) {
                InventoryActions::fail('كمية الإفراج تتجاوز المتبقي المعزول.');
            }
            $p = app(ProductsApi::class)->inventoryProduct($actor, $row->product_id);
            if ($p['tracking'] === 'serial') {
                app(InventorySerials::class)->condition($actor, $id, $row->warehouse_id, $row->product_id, $quantity, $serials, true);
            } elseif ($serials !== []) {
                InventoryActions::fail('أرقام تسلسلية غير متوقعة.');
            }
            $b = app(InventoryLedger::class)->balance($actor, $row->warehouse_id, $row->product_id);
            DB::table('inventory_balances')->where('id', $b->id)->update(['unavailable' => bcsub($b->unavailable, $quantity, 8), 'version' => $b->version + 1, 'updated_at' => now()]);
            DB::table('inventory_isolations')->where('id', $id)->update(['released' => bcadd($row->released, $quantity, 8)]);
            DB::table('inventory_condition_events')->insert(['isolation_id' => $id, 'idempotency_key' => $key, 'action' => 'released', 'quantity' => $quantity, 'payload_hash' => $hash, 'actor_id' => $actor, 'occurred_at' => now()]);
            $out = (array) DB::table('inventory_isolations')->find($id);
            event(new InventoryChanged($actor, 'inventory_isolation', $id, 'released', (array) $row, $out));

            return $out;
        });
    }
}
