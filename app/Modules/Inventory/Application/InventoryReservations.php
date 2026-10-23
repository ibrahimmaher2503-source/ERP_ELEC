<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\InventoryChanged;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

final class InventoryReservations
{
    private function result(object $r): array
    {
        $out = (array) $r;
        $out['outstanding'] = bcsub(bcsub($r->reserved, $r->fulfilled, 8), $r->released, 8);
        $out['unreserved'] = bcsub($r->requested, $r->reserved, 8);

        return $out;
    }

    public function request(int $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            InventoryActions::lock();
            $d = Validator::make($data, ['warehouse_id' => ['required', 'integer'], 'product_id' => ['required', 'integer'], 'quantity' => ['required', 'string'], 'source_module' => ['required', 'in:sales'], 'source_id' => ['required', 'string', 'max:100', 'not_regex:/\x00/'], 'source_line' => ['required', 'string', 'max:100', 'not_regex:/\x00/'], 'idempotency_key' => ['required', 'uuid']])->validate();
            app(InventoryAccess::class)->authorize($actor, 'reservation_manage', (int) $d['warehouse_id']);
            $quantity = app(InventoryLedger::class)->quantity($d['quantity']);
            $existing = DB::table('inventory_reservations')->where('idempotency_key', $d['idempotency_key'])->first();
            if ($existing) {
                foreach (['warehouse_id', 'product_id', 'source_module', 'source_id', 'source_line'] as $key) {
                    if ((string) $existing->$key !== (string) $d[$key]) {
                        InventoryActions::fail('مرجع الحجز مستخدم لطلب مختلف.');
                    }
                }
                if (bccomp($existing->requested, $quantity, 8) !== 0) {
                    InventoryActions::fail('مرجع الحجز مستخدم لكمية مختلفة.');
                }

                return $this->result($existing);
            }
            $w = DB::table('inventory_warehouses')->where('id', $d['warehouse_id'])->first();
            if (! $w->active) {
                InventoryActions::fail('المستودع غير نشط.');
            }
            $demand = app(SalesOrdersApi::class)->inventoryDemand($actor, $d['source_id'], $d['source_line']);
            if (! $demand['eligible'] || $demand['product_id'] !== (int) $d['product_id'] || $demand['branch_id'] !== $w->branch_id) {
                InventoryActions::fail('مصدر الحجز غير صالح للصنف أو الفرع.');
            }
            $already = '0';
            foreach (DB::table('inventory_reservations')->where('source_module', $d['source_module'])->where('source_id', $d['source_id'])->where('source_line', $d['source_line'])->get() as $r) {
                $already = bcadd($already, bcsub($r->reserved, $r->released, 8), 8);
            }
            if (bccomp(bcadd($already, $quantity, 8), $demand['quantity'], 8) > 0) {
                InventoryActions::fail('الحجز المطلوب يتجاوز طلب البيع المسموح.');
            }
            $b = app(InventoryLedger::class)->balance($actor, (int) $d['warehouse_id'], (int) $d['product_id']);
            $reserve = bccomp($quantity, $b->available, 8) > 0 ? $b->available : $quantity;
            $id = DB::table('inventory_reservations')->insertGetId(['idempotency_key' => $d['idempotency_key'], 'source_module' => $d['source_module'], 'source_id' => $d['source_id'], 'source_line' => $d['source_line'], 'warehouse_id' => $d['warehouse_id'], 'product_id' => $d['product_id'], 'requested' => $quantity, 'reserved' => $reserve, 'actor_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $p=app(ProductsApi::class)->inventoryProduct($actor,(int)$d['product_id']);
            if ($p['tracking']==='serial') { app(InventorySerials::class)->reserve($actor,$id,(int)$d['warehouse_id'],(int)$d['product_id'],$reserve); }
            DB::table('inventory_balances')->where('id', $b->id)->update(['reserved' => bcadd($b->reserved, $reserve, 8), 'version' => $b->version + 1, 'updated_at' => now()]);
            DB::table('inventory_reservation_events')->insert(['reservation_id' => $id, 'action' => 'requested', 'quantity' => $reserve, 'idempotency_key' => $d['idempotency_key'], 'actor_id' => $actor, 'occurred_at' => now()]);
            $out = $this->result(DB::table('inventory_reservations')->where('id', $id)->first());
            event(new InventoryChanged($actor, 'reservation', $id, 'requested', null, $out));

            return $out;
        });
    }

    public function status(int $actor, int $id): array
    {
        $r = DB::table('inventory_reservations')->where('id', $id)->first();
        abort_unless($r, 404);
        app(InventoryAccess::class)->authorize($actor, 'reservation_view', $r->warehouse_id);

        return $this->result($r);
    }

    /** Replaces only outstanding demand; already fulfilled stock remains immutable. */
    public function adjust(int $actor, int $id, string $quantity, string $key): array
    {
        return DB::transaction(function () use ($actor, $id, $quantity, $key) {
            InventoryActions::lock();
            $r = DB::table('inventory_reservations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($r, 404);
            app(InventoryAccess::class)->authorize($actor, 'reservation_manage', $r->warehouse_id);
            Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->validate();
            $quantity = app(InventoryLedger::class)->quantity($quantity);
            $existing = DB::table('inventory_reservations')->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->source_module !== $r->source_module || $existing->source_id !== $r->source_id || $existing->source_line !== $r->source_line || $existing->warehouse_id !== $r->warehouse_id || bccomp($existing->requested, $quantity, 8) !== 0) {
                    InventoryActions::fail('مرجع تعديل الحجز مستخدم لمحتوى مختلف.');
                }

                return $this->result($existing);
            }
            $this->release($actor, $id, Uuid::uuid5(Uuid::NAMESPACE_URL, 'inventory-adjust-release:'.$key)->toString());

            return $this->request($actor, ['warehouse_id' => $r->warehouse_id, 'product_id' => $r->product_id, 'quantity' => $quantity, 'source_module' => $r->source_module, 'source_id' => $r->source_id, 'source_line' => $r->source_line, 'idempotency_key' => $key]);
        });
    }

    public function release(int $actor, int $id, string $key): array
    {
        return $this->settle($actor, $id, $key, null);
    }

    public function fulfill(int $actor, int $id, string $quantity, string $key): array
    {
        return $this->settle($actor, $id, $key, $quantity);
    }

    private function settle(int $actor, int $id, string $key, ?string $quantity): array
    {
        return DB::transaction(function () use ($actor, $id, $key, $quantity) {
            InventoryActions::lock();
            Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->validate();
            $r = DB::table('inventory_reservations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($r, 404);
            app(InventoryAccess::class)->authorize($actor, $quantity === null ? 'reservation_manage' : 'issue_post', $r->warehouse_id);
            $action = $quantity === null ? 'released' : 'fulfilled';
            $event = DB::table('inventory_reservation_events')->where('idempotency_key', $key)->first();
            if ($event) {
                if ($event->reservation_id !== $id || $event->action !== $action || ($quantity !== null && bccomp($event->quantity, $quantity, 8) !== 0)) {
                    InventoryActions::fail('مرجع التنفيذ مستخدم لإجراء مختلف.');
                }

                return $this->result($r);
            }
            if ($quantity === null && ! app(SalesOrdersApi::class)->inventoryReleaseAllowed($actor, $r->source_id, $r->source_line)) {
                InventoryActions::fail('فك الحجز يحتاج إلغاءً أو تخفيضًا مؤهلًا من مصدر الطلب.');
            }
            $outstanding = $this->result($r)['outstanding'];
            $amount = $quantity === null ? $outstanding : app(InventoryLedger::class)->quantity($quantity);
            if (bccomp($amount, $outstanding, 8) > 0) {
                InventoryActions::fail('الكمية تتجاوز الحجز المتبقي.');
            }
            $b = DB::table('inventory_balances')->where('warehouse_id', $r->warehouse_id)->where('product_id', $r->product_id)->lockForUpdate()->first();
            if ($quantity !== null) {
                // Inventory does not infer delivery eligibility from a generic stock permission.
                $demand = app(SalesOrdersApi::class)->inventoryDemand($actor, $r->source_id, $r->source_line);
                $fulfilled = (string) DB::table('inventory_reservations')->where('source_module', $r->source_module)->where('source_id', $r->source_id)->where('source_line', $r->source_line)->sum('fulfilled');
                if (! $demand['eligible'] || $demand['product_id'] !== $r->product_id || bccomp(bcadd($fulfilled, $amount, 8), $demand['quantity'], 8) > 0) {
                    InventoryActions::fail('مصدر الطلب لم يعد يسمح بصرف هذه الكمية.');
                }
                $p = app(ProductsApi::class)->inventoryProduct($actor, $r->product_id);
                $w = DB::table('inventory_warehouses')->where('id', $r->warehouse_id)->first();
                if (! $w->active) {
                    InventoryActions::fail('المستودع غير نشط.');
                }
                app(InventoryLedger::class)->post($actor, $r->warehouse_id, ['product_id' => $r->product_id, 'base_unit_id' => $p['base_unit_id'], 'sku' => $p['sku'], 'product_name' => $p['name'], 'reservation_id'=>$id, 'serials'=>$p['tracking']==='serial'?app(InventorySerials::class)->reserved($id,$amount):[]], bcsub('0', $amount, 8), 'reservation_issue', 'Sales', $r->source_id, $r->source_line, 'reservation:'.$key, null, $amount);
            } else {
                app(InventorySerials::class)->releaseReservation($actor,$id);
                DB::table('inventory_balances')->where('id', $b->id)->update(['reserved' => bcsub($b->reserved, $amount, 8), 'version' => $b->version + 1, 'updated_at' => now()]);
            }
            DB::table('inventory_reservations')->where('id', $id)->update([$action => bcadd($r->$action, $amount, 8), 'updated_at' => now()]);
            DB::table('inventory_reservation_events')->insert(['reservation_id' => $id, 'action' => $action, 'quantity' => $amount, 'idempotency_key' => $key, 'actor_id' => $actor, 'occurred_at' => now()]);
            $out = $this->result(DB::table('inventory_reservations')->where('id', $id)->first());
            event(new InventoryChanged($actor, 'reservation', $id, $action, (array) $r, $out));

            return $out;
        });
    }
}
