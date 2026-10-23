<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;

final class InventoryLedger
{
    /** Called only inside an Inventory transaction, after source and actor authorization. */
    public function balance(int $actor, int $warehouse, int $product): object
    {
        $p = app(ProductsApi::class)->inventoryProduct($actor, $product);
        if (! $p['active'] || $p['kind'] === 'service' || !in_array($p['tracking'],['quantity','serial'],true)) {
            InventoryActions::fail('يلزم صنف نشط بتتبع كمي؛ الأصناف المسلسلة تحتاج عقد إشغال الأرقام التسلسلية.');
        }
        DB::table('inventory_balances')->insertOrIgnore(['warehouse_id' => $warehouse, 'product_id' => $product, 'tracking'=>$p['tracking'],'sku' => $p['sku'], 'product_name' => $p['name'], 'unit_name' => $p['unit_name'], 'category_id' => $p['category_id'], 'category_name' => $p['category_name'], 'on_hand' => '0', 'reserved' => '0', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('inventory_balances')->where('warehouse_id', $warehouse)->where('product_id', $product)->lockForUpdate()->first();
    }

    public function quantity(mixed $value, bool $signed = false, bool $zero = false): string
    {
        if (! is_string($value) || ! preg_match($signed ? '/^-?(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D' : '/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D', $value) || (! $signed && bccomp($value, '0', 8) < 0) || (! $zero && bccomp($value, '0', 8) === 0)) {
            InventoryActions::fail('الكمية يجب أن تكون قيمة عشرية دقيقة ضمن ٨ خانات دون تقريب.');
        }

        return bcadd($value, '0', 8);
    }

    public function post(int $actor, int $warehouse, array $line, string $delta, string $operation, string $sourceModule, string $sourceId, string $sourceLine, string $key, ?int $document = null, string $consumeReserved = '0', ?int $reversal = null): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $delta = $this->quantity($delta, true);
        $consumeReserved = $this->quantity($consumeReserved, false, true);
        if (bccomp($consumeReserved, '0', 8) > 0 && bccomp($delta, bcsub('0', $consumeReserved, 8), 8) !== 0) {
            InventoryActions::fail('تسوية الحجز يجب أن تطابق كمية الصرف الفعلية.');
        }
        $existing = DB::table('inventory_movements')->where('idempotency_key', $key)->first();
        if ($existing) {
            if ($existing->warehouse_id !== $warehouse || $existing->product_id !== (int) $line['product_id'] || bccomp($existing->quantity, $delta, 8) !== 0
                || $existing->operation !== $operation || $existing->source_module !== $sourceModule
                || $existing->source_id !== $sourceId || $existing->source_line !== $sourceLine
                || $existing->reversal_id !== $reversal || $existing->document_id !== $document || $existing->base_unit_id !== (int) $line['base_unit_id']) {
                InventoryActions::fail('مرجع الترحيل مستخدم لمحتوى مختلف.');
            }

            $p=app(ProductsApi::class)->inventoryProduct($actor,(int)$line['product_id']);
            if ($p['tracking']==='serial') {
                $v=$line['serials']??[];
                if (is_string($v)) { $v=json_decode($v,true,512,JSON_THROW_ON_ERROR); }
                $expected=array_keys(app(InventorySerials::class)->selections($v,bccomp($delta,'0',8)<0?bcsub('0',$delta,8):$delta));
                $actual=DB::table('inventory_serial_events as e')->join('inventory_serials as s','s.id','=','e.serial_id')->where('e.movement_id',$existing->id)->orderBy('s.normalized')->pluck('s.normalized')->all();
                if ($expected!==$actual) { InventoryActions::fail('Posting key has different serial selections.'); }
            }
            return;
        }
        $b = $this->balance($actor, $warehouse, (int) $line['product_id']);
        $p=app(ProductsApi::class)->inventoryProduct($actor,(int)$line['product_id']);
        $values=$line['serials']??[];
        if (is_string($values)) { $values=json_decode($values,true,512,JSON_THROW_ON_ERROR); }
        $line['serials']=$values;
        if ($p['tracking']!=='serial' && $values!==[]) { InventoryActions::fail('Unexpected serial selections for quantity stock.'); }
        $on = bcadd($b->on_hand, $delta, 8);
        $reserved = bcsub($b->reserved, $consumeReserved, 8);
        if (bccomp($on, '0', 8) < 0 || bccomp($reserved, '0', 8) < 0 || bccomp($on, bcadd($reserved,$b->unavailable,8), 8) < 0) {
            InventoryActions::fail('الكمية المتاحة لا تكفي؛ لا يمكن الصرف من رصيد محجوز لطلب آخر.');
        }
        $movementId=DB::table('inventory_movements')->insertGetId(['reversal_id'=>$reversal,'operation' => $operation, 'source_module' => $sourceModule, 'source_id' => $sourceId, 'source_line' => $sourceLine, 'document_id' => $document, 'warehouse_id' => $warehouse, 'product_id' => $line['product_id'], 'quantity' => $delta, 'base_unit_id' => $line['base_unit_id'], 'sku' => $line['sku'], 'product_name' => $line['product_name'], 'actor_id' => $actor, 'posted_at' => now(), 'idempotency_key' => $key]);
        if ($p['tracking']==='serial') { app(InventorySerials::class)->post($actor,$line,DB::table('inventory_movements')->find($movementId)); }
        DB::table('inventory_balances')->where('id', $b->id)->update(['on_hand' => $on, 'reserved' => $reserved, 'version' => $b->version + 1, 'updated_at' => now()]);
    }
}
