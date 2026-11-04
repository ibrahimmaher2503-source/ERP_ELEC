<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use App\Modules\Sales\Application\Contracts\SalesDeliverySources;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FinancialStock
{
    public function costedMovement(int $actor, int $movement): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $m = DB::table('inventory_movements')->find($movement);
        if ($m && in_array($m->operation, ['sales_return', 'supplier_return'], true)) {
            return app(TradeReturnStock::class)->financialSource($actor, $m);
        }
        if ($m && in_array($m->operation, ['workshop_issue', 'workshop_return'], true)) {
            return app(WorkshopStock::class)->financialSource($actor, $m);
        }
        if ($m && in_array($m->operation, ['maintenance_issue', 'maintenance_return'], true)) {
            return app(MaintenanceStock::class)->financialSource($actor, $m);
        }
        if ($m && $m->operation === 'project_issue' && $m->source_module === 'Projects' && bccomp($m->quantity, '0', 8) < 0) {
            app(InventoryAccess::class)->authorize($actor, 'issue_post', $m->warehouse_id);
            $source = app(ProjectsApi::class)->authorizeMaterialIssue($actor, $m->source_id, $m->source_line, substr($m->idempotency_key, strlen('reservation:')), $m->warehouse_id, bcsub('0', $m->quantity, 8));
            $w = DB::table('inventory_warehouses')->find($m->warehouse_id);
            abort_unless($w && (int) $w->branch_id === $source['branch_id'], 422);
            $allocations = DB::table('inventory_fifo_allocations')->where('movement_id', $movement)->selectRaw('coalesce(sum(quantity),0) quantity,coalesce(sum(amount),0) amount')->first();
            abort_unless(bccomp($allocations->quantity, bcsub('0', $m->quantity, 8), 8) === 0, 422);

            return $source + ['project_material' => true, 'movement_id' => $movement, 'date' => CarbonImmutable::parse($m->posted_at)->setTimezone('Africa/Cairo')->toDateString(), 'amount' => $allocations->amount, 'debit_purpose' => 'inventory_issue', 'credit_purpose' => 'inventory'];
        }
        if ($m && $m->operation === 'reservation_issue' && $m->source_module === 'Sales' && bccomp($m->quantity, '0', 8) < 0) {
            app(InventoryAccess::class)->authorize($actor, 'issue_post', $m->warehouse_id);
            $source = app(SalesDeliverySources::class)->authorizeIssue($actor,
                $m->source_id, $m->source_line, substr($m->idempotency_key, strlen('reservation:')), $m->warehouse_id, bcsub('0', $m->quantity, 8));
            $quantity = '0.00000000';
            $amount = '0.00';
            foreach (DB::table('inventory_fifo_allocations')->where('movement_id', $movement)->get() as $allocation) {
                $quantity = bcadd($quantity, $allocation->quantity, 8);
                $amount = bcadd($amount, $allocation->amount, 2);
            }
            abort_unless(bccomp($quantity, bcsub('0', $m->quantity, 8), 8) === 0, 422);

            $source['project_id'] = app(ProjectsApi::class)->sourceProject($actor, 'sales_order', (int) $m->source_id, (int) $source['branch_id'], (int) $source['customer_id']);

            return $source + ['movement_id' => $movement, 'date' => CarbonImmutable::parse($m->posted_at)->setTimezone('Africa/Cairo')->toDateString(),
                'amount' => $amount, 'debit_purpose' => 'cogs', 'credit_purpose' => 'inventory'];
        }
        $doc = $m?->document_id ? DB::table('inventory_documents')->find($m->document_id) : null;
        if ($m && $m->operation === 'correction' && $m->source_module === 'Inventory' && $doc && $doc->status === 'posted') {
            app(InventoryAccess::class)->authorize($actor, 'correction_execute', $m->warehouse_id);
            $original = DB::table('inventory_movements')->find($m->reversal_id);
            abort_unless($original && $original->document_id === $doc->id && $original->source_module === 'Inventory'
                && in_array($original->operation, ['issue_post', 'adjustment_post'], true) && $original->reversal_id === null
                && in_array($doc->kind, ['issue', 'adjustment'], true) && $doc->approved_by, 422);
            $positive = bccomp($m->quantity, '0', 8) > 0;
            if ($positive) {
                $layer = DB::table('inventory_fifo_layers')->where('movement_id', $movement)->first();
                abort_unless($layer && bccomp($layer->original_quantity, $m->quantity, 8) === 0, 422);
                $amount = $layer->original_value;
            } else {
                $amount = '0.00';
                $quantity = '0.00000000';
                foreach (DB::table('inventory_fifo_allocations')->where('movement_id', $movement)->get() as $allocation) {
                    $amount = bcadd($amount, $allocation->amount, 2);
                    $quantity = bcadd($quantity, $allocation->quantity, 8);
                }
                abort_unless(bccomp($quantity, bcsub('0', $m->quantity, 8), 8) === 0, 422);
            }

            return ['movement_id' => $movement, 'branch_id' => DB::table('inventory_warehouses')->where('id', $m->warehouse_id)->value('branch_id'),
                'date' => CarbonImmutable::parse($m->posted_at)->setTimezone('Africa/Cairo')->toDateString(),
                'created_by' => $doc->created_by, 'approved_by' => $doc->approved_by, 'amount' => $amount,
                'debit_purpose' => $positive ? 'inventory' : 'stock_adjustment',
                'credit_purpose' => $positive ? ($doc->kind === 'issue' ? 'inventory_issue' : 'stock_adjustment') : 'inventory'];
        }
        $transfer = $m && in_array($m->operation, ['transfer_dispatch', 'transfer_receive'], true);
        if (! $m || ! $doc || $m->source_module !== 'Inventory' || ! in_array($m->operation, ['issue_post', 'adjustment_post', 'transfer_dispatch', 'transfer_receive'], true)
            || ! in_array($doc->kind, ['issue', 'adjustment', 'transfer'], true) || ! in_array($doc->status, ['approved', 'dispatched'], true) || ! $doc->approved_by) {
            InventoryActions::fail('مصدر صرف المخزون غير مؤهل لقيده المالي.');
        }
        app(InventoryAccess::class)->authorize($actor, $transfer ? (bccomp($m->quantity, '0', 8) < 0 ? 'transfer_dispatch' : 'transfer_receive') : $doc->kind.'_post', $m->warehouse_id);
        $allocations = DB::table('inventory_fifo_allocations')->where('movement_id', $movement)->get();
        $quantity = '0.00000000';
        $amount = '0.00';
        foreach ($allocations as $allocation) {
            $quantity = bcadd($quantity, $allocation->quantity, 8);
            $amount = bcadd($amount, $allocation->amount, 2);
        }
        $positive = bccomp($m->quantity, '0', 8) > 0;
        if ($positive) {
            $layer = DB::table('inventory_fifo_layers')->where('movement_id', $movement)->first();
            abort_unless($layer, 422);
            $quantity = $layer->original_quantity;
            $amount = $layer->original_value;
        }
        if (bccomp($quantity, $positive ? $m->quantity : bcsub('0', $m->quantity, 8), 8) !== 0) {
            InventoryActions::fail('تكلفة FIFO لا تطابق الكمية المصروفة.');
        }
        $warehouse = DB::table('inventory_warehouses')->find($m->warehouse_id);

        return ['movement_id' => $movement, 'branch_id' => $warehouse->branch_id, 'date' => CarbonImmutable::parse($m->posted_at)->setTimezone('Africa/Cairo')->toDateString(),
            'created_by' => $doc->created_by, 'approved_by' => $doc->approved_by, 'amount' => $amount,
            'debit_purpose' => $positive ? 'inventory' : ($transfer ? 'inventory_transit' : ($doc->kind === 'issue' ? 'inventory_issue' : 'stock_adjustment')),
            'credit_purpose' => $positive ? ($transfer ? 'inventory_transit' : 'stock_adjustment') : 'inventory'];
    }

    public function postOpening(int $actor, int $batch, string $date, array $lines): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        InventoryActions::lock();
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.financial_activate'));
        if (DB::table('inventory_movements')->exists() || DB::table('inventory_balances')->where(fn ($q) => $q->where('on_hand', '<>', '0')->orWhere('reserved', '<>', '0')->orWhere('unavailable', '<>', '0'))->exists()) {
            InventoryActions::fail('توجد حركة أو أرصدة مخزون سابقة. يلزم ترحيل بيانات مصالَح، ولا يستبدل الافتتاح تاريخ المخزون.');
        }
        foreach ($lines as $line) {
            app(InventoryAccess::class)->authorize($actor, 'receipt_post', $line['warehouse_id']);
            $this->receive($actor, $line['warehouse_id'], $line['product_id'], $line['quantity'], $line['amount'], $date,
                'financial_opening', 'Accounting', (string) $batch, $line['reference'], 'opening:'.$batch.':'.$line['reference'], $line['unit_cost'], is_string($line['serials'] ?? null) ? json_decode($line['serials'], true, 512, JSON_THROW_ON_ERROR) : ($line['serials'] ?? []));
        }
    }

    public function receivePurchase(int $actor, int $receipt): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        InventoryActions::lock();
        $source = app(PurchasingApi::class)->prepareReceiptPosting($actor, $receipt);
        abort_if($source['already_posted'] ?? false, 409, 'Receipt already posted.');
        app(AccountingApi::class)->requirePostingDate($source['receipt']['receipt_date']);
        $warehouse = $source['receipt']['warehouse_id'];
        app(InventoryAccess::class)->authorize($actor, 'receipt_post', $warehouse);
        $posted = [];
        foreach ($source['lines'] as $line) {
            $posted[] = $this->receive($actor, $warehouse, $line['product_id'], $line['quantity'], $line['amount'], $source['receipt']['receipt_date'],
                'purchase_receipt', 'Purchasing', (string) $receipt, (string) $line['id'], 'purchase-receipt:'.$receipt.':'.$line['id'], $line['unit_cost'], $line['serials'] ?? []) + ['line_id' => $line['id'], 'amount' => $line['amount']];
        }

        return ['lines' => $posted, 'amount' => $source['total']];
    }

    private function receive(int $actor, int $warehouse, int $product, string $quantity, string $amount, string $date, string $operation, string $owner, string $source, string $sourceLine, string $key, ?string $unitCost = null, array $serials = []): array
    {
        $p = app(ProductsApi::class)->inventoryProduct($actor, $product);
        if (! $p['active'] || $p['kind'] === 'service' || ! in_array($p['tracking'], ['quantity', 'serial'], true)) {
            InventoryActions::fail('يلزم صنف مخزني نشط بتتبع كمي أو مسلسل.');
        }
        $w = DB::table('inventory_warehouses')->where('active', true)->find($warehouse);
        abort_unless($w, 422, 'المستودع غير نشط.');
        app(InventoryLedger::class)->post($actor, $warehouse, ['product_id' => $product, 'base_unit_id' => $p['base_unit_id'], 'sku' => $p['sku'], 'product_name' => $p['name'], 'serials' => $serials],
            $quantity, $operation, $owner, $source, $sourceLine, $key);
        $movement = DB::table('inventory_movements')->where('idempotency_key', $key)->value('id');
        $layer = app(FifoValuation::class)->receive($movement, $key, $date, $amount, $unitCost);

        return ['movement_id' => $movement, 'layer_id' => $layer];
    }
}
