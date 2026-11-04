<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;

final class InventoryLedger
{
    /** Called only inside an Inventory transaction, after source and actor authorization. */
    public function balance(int $actor, int $warehouse, int $product): object
    {
        $p = app(ProductsApi::class)->inventoryProduct($actor, $product);
        if (! $p['active'] || $p['kind'] === 'service' || ! in_array($p['tracking'], ['quantity', 'serial'], true)) {
            InventoryActions::fail('يلزم صنف نشط بتتبع كمي؛ الأصناف المسلسلة تحتاج عقد إشغال الأرقام التسلسلية.');
        }
        DB::table('inventory_balances')->insertOrIgnore(['warehouse_id' => $warehouse, 'product_id' => $product, 'tracking' => $p['tracking'], 'sku' => $p['sku'], 'product_name' => $p['name'], 'unit_name' => $p['unit_name'], 'category_id' => $p['category_id'], 'category_name' => $p['category_name'], 'on_hand' => '0', 'reserved' => '0', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

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

            $p = app(ProductsApi::class)->inventoryProduct($actor, (int) $line['product_id']);
            if ($p['tracking'] === 'serial') {
                $v = $line['serials'] ?? [];
                if (is_string($v)) {
                    $v = json_decode($v, true, 512, JSON_THROW_ON_ERROR);
                }
                $expected = array_keys(app(InventorySerials::class)->selections($v, bccomp($delta, '0', 8) < 0 ? bcsub('0', $delta, 8) : $delta));
                $actual = DB::table('inventory_serial_events as e')->join('inventory_serials as s', 's.id', '=', 'e.serial_id')->where('e.movement_id', $existing->id)->orderByRaw('s.normalized COLLATE "C"')->pluck('s.normalized')->all();
                if ($expected !== $actual) {
                    InventoryActions::fail('مفتاح الترحيل له اختيارات أرقام تسلسلية مختلفة.');
                }
            }

            return;
        }
        $costedIssue = in_array($operation, ['issue_post', 'adjustment_post', 'transfer_dispatch', 'reservation_issue', 'project_issue', 'maintenance_issue', 'workshop_issue', 'correction', 'supplier_return'], true) && bccomp($delta, '0', 8) < 0;
        $costedReceipt = in_array($operation, ['transfer_receive', 'maintenance_return', 'workshop_return', 'sales_return'], true) || (in_array($operation, ['adjustment_post', 'correction'], true) && bccomp($delta, '0', 8) > 0);
        if (! in_array($operation, ['financial_opening', 'purchase_receipt'], true) && ! $costedIssue && ! $costedReceipt) {
            app(AccountingApi::class)->requirePostingDate(now('Africa/Cairo')->toDateString());
            InventoryActions::fail('ترحيل هذا المصدر ينتظر تكامل FIFO وقيده المالي. افتتح المخزون من إعداد التفعيل المالي، واستلم الشراء من مساره الذري المعتمد.');
        }
        if ($costedIssue || $costedReceipt) {
            app(AccountingApi::class)->requirePostingDate(now('Africa/Cairo')->toDateString());
        }
        $b = $this->balance($actor, $warehouse, (int) $line['product_id']);
        $p = app(ProductsApi::class)->inventoryProduct($actor, (int) $line['product_id']);
        $values = $line['serials'] ?? [];
        if (is_string($values)) {
            $values = json_decode($values, true, 512, JSON_THROW_ON_ERROR);
        }
        $line['serials'] = $values;
        if ($p['tracking'] !== 'serial' && $values !== []) {
            InventoryActions::fail('اختيارات أرقام تسلسلية غير متوقعة لمخزون الكمية.');
        }
        $on = bcadd($b->on_hand, $delta, 8);
        $reserved = bcsub($b->reserved, $consumeReserved, 8);
        if (bccomp($on, '0', 8) < 0 || bccomp($reserved, '0', 8) < 0 || bccomp($on, bcadd($reserved, $b->unavailable, 8), 8) < 0) {
            InventoryActions::fail('الكمية المتاحة لا تكفي؛ لا يمكن الصرف من رصيد محجوز لطلب آخر.');
        }
        $movementId = DB::table('inventory_movements')->insertGetId(['reversal_id' => $reversal, 'operation' => $operation, 'source_module' => $sourceModule, 'source_id' => $sourceId, 'source_line' => $sourceLine, 'document_id' => $document, 'warehouse_id' => $warehouse, 'product_id' => $line['product_id'], 'quantity' => $delta, 'base_unit_id' => $line['base_unit_id'], 'sku' => $line['sku'], 'product_name' => $line['product_name'], 'actor_id' => $actor, 'posted_at' => now(), 'idempotency_key' => $key]);
        if ($p['tracking'] === 'serial') {
            app(InventorySerials::class)->post($actor, $line, DB::table('inventory_movements')->find($movementId));
        }
        if ($costedIssue) {
            $valuation = app(FifoValuation::class)->consume($movementId, in_array($operation, ['correction', 'supplier_return'], true) ? ($line['correction_layer_id'] ?? null) : null);
            if ($p['tracking'] === 'serial') {
                app(SerialFifoAllocations::class)->record($movementId);
            }
            if ($operation === 'transfer_dispatch') {
                app(ValuedTransfers::class)->dispatch($movementId);
            }
        }
        if ($costedReceipt) {
            if (in_array($operation, ['correction', 'maintenance_return', 'workshop_return', 'sales_return'], true)) {
                abort_unless($reversal !== null && isset($line['correction_amount'], $line['correction_date']), 422);
                $amount = $line['correction_amount'];
                $date = $line['correction_date'];
                $unitCost = $line['correction_unit_cost'];
            } elseif ($operation === 'transfer_receive') {
                abort_unless(isset($line['transfer_lot_id'], $line['transfer_amount'], $line['fifo_date']), 422);
                $amount = $line['transfer_amount'];
                $date = $line['fifo_date'];
                $unitCost = $line['transfer_unit_cost'];
            } else {
                $stored = DB::table('inventory_document_lines')->where('document_id', $document)->find((int) $sourceLine);
                if (! $stored || $stored->unit_cost === null || trim($stored->cost_reference ?? '') === '') {
                    InventoryActions::fail('أدخل تكلفة معتمدة ومرجع تقييم فرق الجرد الموجب قبل الاعتماد.');
                }
                $amount = FinancialDecimal::round(bcmul($delta, $stored->unit_cost, 32));
                $date = now('Africa/Cairo')->toDateString();
                $unitCost = $stored->unit_cost;
            }
            app(FifoValuation::class)->receive($movementId, $key, $date, $amount, $unitCost);
            if ($p['tracking'] === 'serial' && isset($line['serial_allocation_id'])) {
                app(SerialFifoAllocations::class)->receipt($movementId, (int) $line['serial_allocation_id']);
            }
            $valuation = ['amount' => $amount];
        }
        if ($costedIssue || $costedReceipt) {
            $journal = app(AccountingApi::class)->postInventoryMovement($actor, $movementId);
            DB::table('inventory_movement_journals')->insert(['movement_id' => $movementId, 'journal_id' => $journal, 'amount' => $valuation['amount'], 'created_at' => now()]);
        }
        DB::table('inventory_balances')->where('id', $b->id)->update(['on_hand' => $on, 'reserved' => $reserved, 'version' => $b->version + 1, 'updated_at' => now()]);
    }
}
