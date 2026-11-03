<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organization\Application\AssignUserRole;
use App\Modules\Organization\Application\SetUserBranchScope;
use App\Modules\Organization\Application\SetUserWarehouseScope;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || (int) DB::selectOne('select inet_server_port() as port')->port !== 55434) {
    throw new RuntimeException('ABORT: financial fixtures require the Luna-owned disposable PostgreSQL port 55434.');
}
foreach (['users', 'roles', 'user_roles', 'access_grants', 'user_branch_scopes', 'user_warehouse_scopes', 'products', 'customers', 'inventory_warehouses'] as $table) {
    if (! Schema::hasTable($table)) {
        throw new RuntimeException("Migrations are incomplete ({$table}).");
    }
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? '';
if ($mode === 'inventory-balance') {
    $warehouseId = (int) ($input['warehouse_id'] ?? 0);
    $productId = (int) ($input['product_id'] ?? 0);
    if ($warehouseId < 1 || $productId < 1) {
        throw new InvalidArgumentException('Invalid inventory balance request.');
    }
    $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->value('on_hand') ?? '0';
    fwrite(STDOUT, json_encode(['warehouse_id' => $warehouseId, 'product_id' => $productId, 'on_hand' => $balance], JSON_THROW_ON_ERROR));
    exit(0);
}
if ($mode === 'inventory-transfer-state') {
    $transferId = (int) ($input['transfer_id'] ?? 0);
    if ($transferId < 1) {
        throw new InvalidArgumentException('Invalid transfer-state request.');
    }
    $document = DB::table('inventory_documents')->where('id', $transferId)->where('kind', 'transfer')->first();
    if (! $document) {
        throw new RuntimeException('The browser transfer does not exist in the isolated PostgreSQL database.');
    }
    $receipts = DB::table('inventory_transfer_receipts')->where('document_id', $transferId)->orderBy('id')->get(['id', 'occurred_at']);
    $lines = DB::table('inventory_transfer_receipt_lines as l')->join('inventory_transfer_receipts as r', 'r.id', '=', 'l.receipt_id')
        ->where('r.document_id', $transferId)->orderBy('l.id')->get(['l.accepted', 'l.damaged', 'l.missing']);
    $dispatch = DB::table('inventory_movements')->where('document_id', $transferId)->where('operation', 'transfer_dispatch')->first(['id', 'quantity']);
    fwrite(STDOUT, json_encode(['id' => $transferId, 'status' => $document->status, 'version' => (int) $document->version,
        'dispatch' => $dispatch ? ['id' => (int) $dispatch->id, 'quantity' => $dispatch->quantity] : null,
        'receipt_count' => $receipts->count(), 'receipts' => $lines], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    exit(0);
}
if ($mode === 'verify-inventory') {
    $sumDecimal = static function (iterable $rows, string $column, int $scale): string {
        $total = '0';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->{$column}, $scale);
        }

        return $total;
    };
    $transferId = (int) ($input['transfer_id'] ?? 0);
    $countId = (int) ($input['count_id'] ?? 0);
    $adjustmentId = (int) ($input['adjustment_id'] ?? 0);
    $productId = (int) ($input['product_id'] ?? 0);
    $sourceWarehouseId = (int) ($input['source_warehouse_id'] ?? 0);
    $destinationWarehouseId = (int) ($input['destination_warehouse_id'] ?? 0);
    if (min($transferId, $countId, $adjustmentId, $productId, $sourceWarehouseId, $destinationWarehouseId) < 1) {
        throw new InvalidArgumentException('Invalid final Inventory reconciliation request.');
    }
    $transfer = DB::table('inventory_documents')->where('id', $transferId)->where('kind', 'transfer')->first();
    $count = DB::table('inventory_documents')->where('id', $countId)->where('kind', 'count')->first();
    $adjustment = DB::table('inventory_documents')->where('id', $adjustmentId)->where('kind', 'adjustment')->first();
    if (! $transfer || ! $count || ! $adjustment || $transfer->status !== 'received' || $count->status !== 'posted' || $adjustment->status !== 'posted') {
        throw new RuntimeException('Final transfer receipt, count, and adjustment statuses did not all persist.');
    }
    if ((int) $transfer->warehouse_id !== $sourceWarehouseId || (int) $transfer->destination_id !== $destinationWarehouseId
        || (int) $count->warehouse_id !== $destinationWarehouseId || (int) $adjustment->warehouse_id !== $destinationWarehouseId) {
        throw new RuntimeException('Inventory documents do not match the authorized source and destination warehouses.');
    }

    $transferLine = DB::table('inventory_document_lines')->where('document_id', $transferId)->where('product_id', $productId)->first();
    $countLine = DB::table('inventory_document_lines')->where('document_id', $countId)->where('product_id', $productId)->first();
    $adjustmentLine = DB::table('inventory_document_lines')->where('document_id', $adjustmentId)->where('product_id', $productId)->first();
    $receiptTotals = DB::table('inventory_transfer_receipt_lines as l')->join('inventory_transfer_receipts as r', 'r.id', '=', 'l.receipt_id')
        ->where('r.document_id', $transferId)->where('l.line_id', $transferLine?->id)
        ->selectRaw('count(*) as receipt_count, coalesce(sum(l.accepted),0) as accepted, coalesce(sum(l.damaged),0) as damaged, coalesce(sum(l.missing),0) as missing')->first();
    if (! $transferLine || ! $countLine || ! $adjustmentLine || (int) $receiptTotals->receipt_count !== 2
        || bccomp((string) $receiptTotals->accepted, '2', 8) !== 0
        || bccomp((string) $receiptTotals->damaged, '0', 8) !== 0
        || bccomp((string) $receiptTotals->missing, '0', 8) !== 0
        || bccomp($transferLine->quantity, '2', 8) !== 0
        || bccomp($countLine->quantity, $countLine->recorded, 8) !== 0
        || $count->linked_document_id !== null
        || bccomp($adjustmentLine->quantity, '-1', 8) !== 0) {
        throw new RuntimeException('Partial/final transfer receipts, zero-variance count, or negative adjustment lines do not reconcile.');
    }

    $dispatch = DB::table('inventory_movements')->where('document_id', $transferId)->where('operation', 'transfer_dispatch')->first();
    $receipts = DB::table('inventory_movements')->where('document_id', $transferId)->where('operation', 'transfer_receive')->orderBy('id')->get();
    $adjustmentMovements = DB::table('inventory_movements')->where('document_id', $adjustmentId)->where('operation', 'adjustment_post')->get();
    if (! $dispatch || $receipts->count() !== 2 || $adjustmentMovements->count() !== 1
        || bccomp($dispatch->quantity, '-2', 8) !== 0
        || bccomp($sumDecimal($receipts, 'quantity', 8), '2', 8) !== 0
        || bccomp($adjustmentMovements->first()->quantity, '-1', 8) !== 0) {
        throw new RuntimeException('Transfer dispatch, two receipt movements, and adjustment movement do not match the accepted quantities.');
    }
    $transit = DB::table('inventory_transfer_lots')->where('document_id', $transferId)
        ->selectRaw('coalesce(sum(original_quantity),0) as original_quantity, coalesce(sum(remaining_quantity),0) as remaining_quantity')->first();
    $transferred = DB::table('inventory_transfer_allocations')->whereIn('movement_id', $receipts->pluck('id'))
        ->selectRaw('coalesce(sum(quantity),0) as quantity')->first();
    if (bccomp((string) $transit->original_quantity, '2', 8) !== 0
        || bccomp((string) $transit->remaining_quantity, '0', 8) !== 0
        || bccomp((string) $transferred->quantity, '2', 8) !== 0) {
        throw new RuntimeException('Transit FIFO lots and received transfer allocations do not reconcile to full dispatch.');
    }

    $movements = collect([$dispatch])->concat($receipts)->concat($adjustmentMovements);
    $financialRows = [];
    foreach ($movements as $movement) {
        $fifoAllocations = DB::table('inventory_fifo_allocations')->where('movement_id', $movement->id)->get();
        $fifoLayers = DB::table('inventory_fifo_layers')->where('movement_id', $movement->id)->get();
        $fifoQuantity = bcadd($sumDecimal($fifoAllocations, 'quantity', 8), $sumDecimal($fifoLayers, 'original_quantity', 8), 8);
        $expectedFifoQuantity = bccomp($movement->quantity, '0', 8) < 0 ? bcsub('0', $movement->quantity, 8) : $movement->quantity;
        if (bccomp($fifoQuantity, $expectedFifoQuantity, 8) !== 0) {
            throw new RuntimeException("Movement {$movement->id} FIFO quantity does not match its stock movement.");
        }
        $value = bcadd($sumDecimal($fifoAllocations, 'amount', 2), $sumDecimal($fifoLayers, 'original_value', 2), 2);
        $link = DB::table('inventory_movement_journals')->where('movement_id', $movement->id)->first();
        if (! $link || bccomp((string) $link->amount, $value, 2) !== 0) {
            throw new RuntimeException("Movement {$movement->id} FIFO value does not match its GL source link.");
        }
        if (bccomp((string) $link->amount, '0', 2) > 0) {
            $journal = DB::table('accounting_journals')->where('id', $link->journal_id)->first();
            $totals = DB::table('accounting_journal_lines')->where('journal_id', $link->journal_id)
                ->selectRaw('coalesce(sum(debit),0) as debit, coalesce(sum(credit),0) as credit')->first();
            if (! $journal || $journal->status !== 'posted' || $journal->source_key !== 'inventory-movement:'.$movement->id
                || bccomp((string) $totals->debit, (string) $link->amount, 2) !== 0
                || bccomp((string) $totals->credit, (string) $link->amount, 2) !== 0) {
                throw new RuntimeException("Movement {$movement->id} does not reconcile to its posted, balanced source journal.");
            }
        } elseif ($link->journal_id !== null) {
            throw new RuntimeException("Zero-value movement {$movement->id} unexpectedly has a GL journal.");
        }
        $financialRows[] = ['movement_id' => (int) $movement->id, 'operation' => $movement->operation,
            'quantity' => $movement->quantity, 'fifo_quantity' => $fifoQuantity, 'value' => (string) $link->amount,
            'journal_id' => $link->journal_id === null ? null : (int) $link->journal_id];
    }

    $balances = [];
    foreach ([$sourceWarehouseId, $destinationWarehouseId] as $warehouseId) {
        $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->value('on_hand') ?? '0';
        $movementTotal = DB::table('inventory_movements')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->sum('quantity');
        if (bccomp((string) $balance, (string) $movementTotal, 8) !== 0) {
            throw new RuntimeException("Warehouse {$warehouseId} on-hand does not equal its complete posted movement ledger.");
        }
        $balances[] = ['warehouse_id' => $warehouseId, 'on_hand' => (string) $balance, 'movement_total' => (string) $movementTotal];
    }
    fwrite(STDOUT, json_encode(['result' => 'PASS', 'transfer' => ['id' => $transferId, 'status' => $transfer->status,
        'dispatch_movement_id' => (int) $dispatch->id, 'receipt_count' => (int) $receiptTotals->receipt_count,
        'accepted' => (string) $receiptTotals->accepted, 'transit_quantity' => (string) $transit->original_quantity,
        'transit_remaining' => (string) $transit->remaining_quantity], 'count' => ['id' => $countId, 'status' => $count->status,
            'recorded' => $countLine->recorded, 'counted' => $countLine->quantity],
        'adjustment' => ['id' => $adjustmentId, 'status' => $adjustment->status, 'quantity' => $adjustmentLine->quantity],
        'balances' => $balances, 'fifo_gl_movements' => $financialRows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    exit(0);
}
if ($mode === 'verify-quotation') {
    $quote = DB::table('sales_quotations')->where('id', (int) ($input['id'] ?? 0))->first();
    if (! $quote || $quote->project_name !== $input['project_name'] || $quote->status !== 'draft') {
        throw new RuntimeException('The browser-created quotation identity/status did not persist.');
    }
    $lines = DB::table('sales_quotation_lines')->where('quotation_id', $quote->id)->orderBy('id')->get(['product_id', 'quantity', 'requested_price']);
    if ($lines->count() !== 2 || $lines->pluck('product_id')->map(fn ($id) => (int) $id)->all() !== array_map('intval', $input['product_ids'])) {
        throw new RuntimeException('The two actual browser-created quotation lines did not persist.');
    }
    fwrite(STDOUT, json_encode(['quotation_id' => (int) $quote->id, 'status' => $quote->status, 'project_name' => $quote->project_name,
        'lines' => $lines->map(fn ($line) => ['product_id' => (int) $line->product_id, 'quantity' => $line->quantity, 'requested_price' => $line->requested_price])->all()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    exit(0);
}
if ($mode === 'order-lines') {
    $orderId = (int) ($input['order_id'] ?? 0);
    if ($orderId < 1 || ! Schema::hasTable('sales_order_lines')) {
        throw new InvalidArgumentException('Invalid order-lines request.');
    }
    $order = DB::table('sales_orders')->find($orderId);
    if (! $order) {
        throw new RuntimeException('Sales order is not present in the isolated test database.');
    }
    fwrite(STDOUT, json_encode(['order_id' => $orderId, 'status' => $order->status,
        'lines' => DB::table('sales_order_lines')->where('sales_order_id', $orderId)->orderBy('id')->get(['id', 'product_id', 'quantity'])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    exit(0);
}
if ($mode !== 'prepare') {
    throw new InvalidArgumentException('Expected prepare or verify-quotation mode.');
}
$tag = strtoupper((string) ($input['tag'] ?? ''));
$password = (string) ($input['password'] ?? '');
if (! preg_match('/^[A-F0-9]{12}$/', $tag) || strlen($password) < 32) {
    throw new InvalidArgumentException('Invalid run identity.');
}

$bootstrap = User::factory()->create([
    'email' => "phase0-bootstrap-{$tag}@example.test", 'name' => 'Phase0 isolated security bootstrap',
    'active' => true, 'email_verified_at' => now(), 'password' => Hash::make(bin2hex(random_bytes(32))),
]);
DB::table('access_grants')->insert(['user_id' => $bootstrap->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
$role = DB::table('roles')->where('code', 'Administrator')->first(['id', 'version']);
if (! $role) {
    throw new RuntimeException('Administrator role is missing.');
}
$branch = DB::table('branches')->where('active', true)->orderBy('id')->first(['id']);
$warehouses = DB::table('inventory_warehouses')->where('branch_id', $branch->id)->where('active', true)->orderBy('id')->limit(2)->get(['id', 'name']);
if ($warehouses->count() < 2) {
    throw new RuntimeException('Two active same-branch warehouses are required for Inventory transfer acceptance.');
}
$warehouse = $warehouses[0];
$actors = [];
foreach (['operator', 'reviewer'] as $index => $kind) {
    $user = User::factory()->create([
        'email' => "phase0-{$kind}-{$tag}@example.test", 'name' => "Phase0 {$kind} {$tag}",
        'active' => true, 'email_verified_at' => now(), 'password' => Hash::make($password),
    ]);
    app(AssignUserRole::class)->execute($bootstrap, (int) $role->id, (int) $user->id, true, (int) $role->version);
    $role = DB::table('roles')->where('id', $role->id)->first(['id', 'version']);
    app(SetUserBranchScope::class)->execute($bootstrap, (int) $user->id, (int) $branch->id, true);
    foreach ($warehouses as $scopedWarehouse) {
        app(SetUserWarehouseScope::class)->execute($bootstrap, (int) $user->id, (int) $scopedWarehouse->id, true);
    }
    $actors[$kind] = ['id' => (int) $user->id, 'email' => $user->email];
}
$denied = User::factory()->create([
    'email' => "phase0-denied-{$tag}@example.test", 'name' => 'Administrator',
    'active' => true, 'email_verified_at' => now(), 'password' => Hash::make($password),
]);
app(SetUserBranchScope::class)->execute($bootstrap, (int) $denied->id, (int) $branch->id, true);
$actors['denied'] = ['id' => (int) $denied->id, 'email' => $denied->email];
$products = DB::table('products as p')->join('product_sale_prices as sp', 'sp.product_id', '=', 'p.id')
    ->where('p.active', true)->where('sp.status', 'approved')->orderBy('p.id')->distinct()
    ->limit(2)->get(['p.id', 'p.sku', 'p.name', 'p.base_unit_id']);
$customer = DB::table('customers')->orderBy('id')->first(['id', 'code', 'name']);
$supplier = DB::table('suppliers')->where('status', 'active')->orderBy('id')->first(['id', 'code', 'name']);
$terms = DB::table('sales_quotation_terms')->where('active', true)->orderBy('id')->get(['id', 'kind', 'code']);
$paymentTerm = $terms->firstWhere('kind', 'payment');
$deliveryTerm = $terms->firstWhere('kind', 'delivery');
$priced = DB::table('product_sale_prices')->where('status', 'approved')->whereIn('product_id', $products->pluck('id'))->get();
if ($products->count() !== 2 || $priced->pluck('product_id')->unique()->count() !== 2) {
    throw new RuntimeException('Two active products with approved sale-price records are required for quotation browser acceptance.');
}
if (! $supplier) {
    throw new RuntimeException('An active existing supplier is required for Purchasing browser acceptance.');
}
$companyLogoPath = DB::table('companies')->where('id', 1)->value('logo_path');
if (! $companyLogoPath || ! Storage::disk('local')->exists($companyLogoPath)) {
    throw new RuntimeException('The isolated recovered company logo is missing from the configured Laravel private disk.');
}
fwrite(STDOUT, json_encode(['actors' => $actors, 'branch_id' => (int) $branch->id, 'warehouse_id' => (int) $warehouse->id,
    'destination_warehouse_id' => (int) $warehouses[1]->id,
    'products' => $products, 'customer' => $customer, 'supplier' => $supplier, 'payment_term_id' => $paymentTerm?->id,
    'delivery_term_id' => $deliveryTerm?->id, 'company_logo_path' => $companyLogoPath,
    'laravel_storage_root' => storage_path(),
    'priced_product_count' => $priced->pluck('product_id')->unique()->count()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
