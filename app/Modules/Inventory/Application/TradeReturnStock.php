<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\InventoryChanged;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use App\Modules\Sales\Application\Contracts\SalesDeliverySources;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Physical effect of owner-approved customer and supplier returns, at their source FIFO cost. */
final class TradeReturnStock
{
    public function salesIssueEvidence(int $actor, string $issueKey): array
    {
        $m = DB::table('inventory_movements')->where('idempotency_key', 'reservation:'.$issueKey)->where('operation', 'reservation_issue')->first();
        abort_unless($m, 404);
        $serials = DB::table('inventory_serial_events as e')->join('inventory_serials as s', 's.id', '=', 'e.serial_id')
            ->where('e.movement_id', $m->id)->where('s.state', 'issued')->where('s.last_movement_id', $m->id)
            ->orderByRaw('s.normalized COLLATE "C"')->pluck('s.normalized')->all();

        return ['movement_id' => $m->id, 'warehouse_id' => $m->warehouse_id, 'product_id' => $m->product_id, 'serials' => $serials];
    }

    /** @return list<array{movement_id:int,quantity:string,amount:string,serials:list<string>}> */
    public function restoreSalesIssue(int $actor, string $issueKey, string $quantity, string $key, string $reason, array $serials): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        InventoryActions::lock();
        $m = DB::table('inventory_movements')->where('idempotency_key', 'reservation:'.$issueKey)->lockForUpdate()->first();
        abort_unless($m && $m->operation === 'reservation_issue' && $m->source_module === 'Sales', 422);
        app(InventoryAccess::class)->authorize($actor, 'correction_execute', $m->warehouse_id);
        $this->requireActiveWarehouse($m->warehouse_id);
        $quantity = app(InventoryLedger::class)->quantity($quantity);
        $posted = [];
        foreach ($this->chunks($m, $quantity, $serials) as $index => [$part, $names]) {
            $chunk = Uuid::uuid5(Uuid::NAMESPACE_URL, 'sales-return:'.$key.':'.$index)->toString();
            $id = app(ValuedCorrections::class)->post($actor, $m, $part, 'sales-return:'.$chunk, $names, 'sales_return');
            $posted[] = $this->record($actor, $m->id, $id, $part, $chunk, $reason, $names);
        }

        return $posted;
    }

    /** Consumes the receipt's own FIFO layer, so the returned goods leave at their received cost. */
    public function returnToSupplier(int $actor, int $movement, string $quantity, string $key, string $reason, array $serials): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        InventoryActions::lock();
        $m = DB::table('inventory_movements')->lockForUpdate()->find($movement);
        abort_unless($m && $m->operation === 'purchase_receipt' && $m->source_module === 'Purchasing', 422);
        app(InventoryAccess::class)->authorize($actor, 'correction_execute', $m->warehouse_id);
        $this->requireActiveWarehouse($m->warehouse_id);
        $quantity = app(InventoryLedger::class)->quantity($quantity);
        $names = $serials === [] ? [] : array_keys(app(InventorySerials::class)->selections($serials, $quantity));
        $layer = DB::table('inventory_fifo_layers')->where('movement_id', $m->id)->lockForUpdate()->first();
        if (! $layer || bccomp($quantity, $layer->remaining_quantity, 8) > 0) {
            InventoryActions::fail('الكمية المتبقية من هذا الاستلام في المخزن أقل من المرتجع. المتاح: '.($layer->remaining_quantity ?? '0'));
        }
        $id = app(ValuedCorrections::class)->post($actor, $m, $quantity, 'supplier-return:'.$key, $names, 'supplier_return');

        return $this->record($actor, $m->id, $id, $quantity, $key, $reason, $names);
    }

    public function financialSource(int $actor, object $m): array
    {
        app(InventoryAccess::class)->authorize($actor, 'correction_execute', $m->warehouse_id);
        $original = DB::table('inventory_movements')->find($m->reversal_id);
        abort_unless($original, 422);
        $date = now('Africa/Cairo')->toDateString();
        if ($m->operation === 'sales_return') {
            abort_unless($original->operation === 'reservation_issue' && $original->source_module === 'Sales', 422);
            $source = app(SalesDeliverySources::class)->authorizeReturn($actor, (int) $original->source_id, (int) $original->source_line);
            $source['project_id'] = app(ProjectsApi::class)->sourceProject($actor, 'sales_order', (int) $original->source_id, (int) $source['branch_id'], (int) $source['customer_id']);
            $amount = DB::table('inventory_fifo_layers')->where('movement_id', $m->id)->value('original_value');
            abort_unless($amount !== null, 422);

            return $source + ['movement_id' => $m->id, 'date' => $date, 'amount' => $amount, 'debit_purpose' => 'inventory', 'credit_purpose' => 'cogs'];
        }
        abort_unless($original->operation === 'purchase_receipt' && $original->source_module === 'Purchasing', 422);
        $source = app(PurchasingApi::class)->authorizeReturn($actor, (int) $original->source_id, (int) $original->source_line);
        $source['project_id'] = app(ProjectsApi::class)->sourceProject($actor, 'purchase_order', (int) $source['order_id'], (int) $source['branch_id']);
        $amount = '0.00';
        foreach (DB::table('inventory_fifo_allocations')->where('movement_id', $m->id)->pluck('amount') as $part) {
            $amount = bcadd($amount, $part, 2);
        }

        return $source + ['movement_id' => $m->id, 'date' => $date, 'amount' => $amount, 'debit_purpose' => 'ppv', 'credit_purpose' => 'inventory'];
    }

    /** A customer issue can span FIFO layers; each return movement restores exactly one of them. */
    private function chunks(object $m, string $quantity, array $serials): array
    {
        if ($serials !== []) {
            $names = array_keys(app(InventorySerials::class)->selections($serials, $quantity));
            $rows = DB::table('inventory_fifo_serial_allocations as a')->join('inventory_serials as s', 's.id', '=', 'a.serial_id')
                ->where('a.movement_id', $m->id)->whereIn('s.normalized', $names)->orderBy('a.allocation_id')->get(['a.allocation_id', 's.normalized']);
            if ($rows->count() !== count($names)) {
                InventoryActions::fail('الرقم المسلسل لا يخص إذن التسليم الأصلي.');
            }

            return $rows->groupBy('allocation_id')->map(fn ($group) => [bcadd((string) $group->count(), '0', 8), $group->pluck('normalized')->all()])->values()->all();
        }
        $parts = [];
        $left = $quantity;
        foreach (DB::table('inventory_fifo_allocations')->where('movement_id', $m->id)->orderBy('id')->get() as $allocation) {
            if (bccomp($left, '0', 8) === 0) {
                break;
            }
            $used = (string) DB::table('inventory_fifo_corrections')->where('allocation_id', $allocation->id)->sum('quantity');
            $remaining = bcsub($allocation->quantity, $used, 8);
            if (bccomp($remaining, '0', 8) <= 0) {
                continue;
            }
            $take = bccomp($left, $remaining, 8) < 0 ? $left : $remaining;
            $parts[] = [$take, []];
            $left = bcsub($left, $take, 8);
        }
        if (bccomp($left, '0', 8) !== 0) {
            InventoryActions::fail('الكمية تتجاوز المتبقي القابل للإرجاع من إذن التسليم.');
        }

        return $parts;
    }

    private function record(int $actor, int $original, int $movement, string $quantity, string $key, string $reason, array $serials): array
    {
        $hash = hash('sha256', json_encode([$original, $quantity, $reason, $serials], JSON_THROW_ON_ERROR));
        DB::table('inventory_corrections')->insert(['movement_id' => $original, 'correcting_movement_id' => $movement, 'quantity' => $quantity, 'reason' => $reason,
            'idempotency_key' => $key, 'serials' => json_encode($serials, JSON_THROW_ON_ERROR), 'payload_hash' => $hash, 'status' => 'posted', 'actor_id' => $actor, 'occurred_at' => now()]);
        event(new InventoryChanged($actor, 'inventory_movement', $movement, 'trade_return.posted', null, ['original' => $original, 'quantity' => $quantity, 'reason' => $reason]));

        return ['movement_id' => $movement, 'quantity' => $quantity, 'serials' => $serials,
            'amount' => (string) DB::table('inventory_movement_journals')->where('movement_id', $movement)->value('amount')];
    }

    private function requireActiveWarehouse(int $warehouse): void
    {
        if (! DB::table('inventory_warehouses')->where('id', $warehouse)->value('active')) {
            InventoryActions::fail('المخزن الأصلي غير نشط.');
        }
    }
}
