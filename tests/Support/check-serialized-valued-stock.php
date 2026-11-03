<?php

declare(strict_types=1);

// Gate 5: real M05 serial receipts, FIFO-valued M06 issues/corrections/transfers,
// M11 journals, exact serial occupancy, and rollback in the owned PostgreSQL test database.
use App\Application\ActivateFinancialPosting;
use App\Application\PostPurchaseReceipt;
use App\Models\User;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Accounting\Domain\AccountingAccount;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Inventory\Application\InventoryCorrections;
use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Inventory\Application\InventoryTransfers;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 2);
require_once $root.'/vendor/autoload.php';
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$environment = [
    'APP_ENV' => 'testing',
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres'),
];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $environment);
if ($target['port'] !== 49685 || $target['database'] !== 'erp_elec_new_test' || $target['username'] !== 'postgres') {
    throw new RuntimeException('Gate 5 serial-valued check requires the exact owned PostgreSQL test target on port 49685.');
}
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
DB::purge();
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address,
    inet_server_port() as port, current_setting('data_directory') as data_directory, current_user as current_user,
    current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, $identity);
if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000
    || $identity->database_owner !== 'postgres') {
    throw new RuntimeException('Gate 5 serial-valued check requires the marker-owned PostgreSQL 17 cluster and database owner.');
}

$runId = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(5));
$evidenceDirectory = $root.'/.tmp/master-remediation-20261101/gate5-serial-valued-'.$runId;
if (! mkdir($evidenceDirectory, 0700, true)) {
    throw new RuntimeException('Cannot create private Gate 5 serial-valued evidence directory.');
}
$receiptPath = $evidenceDirectory.'/receipt.json';
$assertions = 0;
$passed = [];
$diagnostics = [];
$stage = 'synthetic company and authorization fixture';
$stageArguments = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$passed): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $passed[] = $message;
    echo "PASS {$message}\n";
};
$assertWithDetails = static function (bool $condition, string $message, array $expected, array $actual) use ($assert, &$diagnostics): void {
    $diagnostics[] = ['assertion' => $message, 'expected' => $expected, 'actual' => $actual];
    $assert($condition, $message);
};
$expect = static function (string $class, Closure $callback, string $message) use ($assert): Throwable {
    try {
        $callback();
    } catch (Throwable $error) {
        $assert($error instanceof $class, $message.' (actual '.$error::class.')');

        return $error;
    }
    throw new RuntimeException($message.' (expected exception was not thrown)');
};
$uuid = static fn (): string => (string) Str::uuid();
$today = now('Africa/Cairo')->toDateString();
$goLiveDate = now('Africa/Cairo')->subDays(7)->toDateString();
$tablesToCompare = [
    'companies', 'branches', 'users', 'access_grants', 'user_branch_scopes', 'user_warehouse_scopes',
    'inventory_warehouses', 'products', 'suppliers', 'accounting_accounts', 'accounting_periods',
    'accounting_activation', 'accounting_tax_rules', 'accounting_journals', 'accounting_journal_lines',
    'accounting_opening_batches', 'purchasing_orders', 'purchasing_order_lines', 'purchasing_order_reviews',
    'purchasing_order_issues', 'purchasing_receipts', 'purchasing_receipt_lines', 'purchasing_receipt_postings',
    'inventory_documents', 'inventory_document_lines', 'inventory_movements', 'inventory_balances', 'inventory_fifo_layers', 'inventory_fifo_allocations',
    'inventory_fifo_serial_allocations', 'inventory_serials', 'inventory_serial_events', 'inventory_movement_journals',
    'inventory_corrections', 'inventory_fifo_corrections', 'inventory_transfer_lots', 'inventory_transfer_allocations',
    'inventory_transfer_receipts', 'inventory_transfer_receipt_lines', 'inventory_isolations', 'inventory_condition_events', 'audit_entries',
];
$counts = static function () use ($tablesToCompare): array {
    $out = [];
    foreach ($tablesToCompare as $table) {
        if (Schema::hasTable($table)) {
            $out[$table] = DB::table($table)->count();
        }
    }

    return $out;
};
$beforeCounts = $counts();
$error = null;
$rollbackVerified = false;
$failureTrace = [];
DB::beginTransaction();
try {
    $stage = 'M05 serial receipt schema preflight';
    $requiredTables = ['purchasing_receipt_lines', 'inventory_fifo_serial_allocations', 'inventory_serials', 'inventory_serial_events',
        'inventory_movements', 'inventory_balances', 'inventory_documents', 'inventory_document_lines', 'inventory_fifo_layers', 'inventory_fifo_allocations',
        'inventory_corrections', 'inventory_fifo_corrections', 'inventory_transfer_lots', 'inventory_transfer_allocations',
        'inventory_transfer_receipts', 'inventory_transfer_receipt_lines', 'inventory_isolations', 'inventory_condition_events',
        'inventory_movement_journals', 'accounting_journals', 'accounting_journal_lines'];
    foreach ($requiredTables as $table) {
        $assert(Schema::hasTable($table), "Migration provides required serial-valued table {$table}");
    }
    $serialColumn = DB::selectOne("select 1 from information_schema.columns where table_schema='public' and table_name='purchasing_receipt_lines' and column_name='serials'");
    $assert($serialColumn !== null, 'M05 receipt lines persist source-owned serial identities');

    if (! DB::table('companies')->where('id', 1)->exists()) {
        DB::table('companies')->insert(['id' => 1, 'code' => 'G5-SERIAL', 'name' => 'Synthetic Gate 5 Company', 'legal_name' => 'Synthetic Gate 5 Serial Company', 'tax_registration' => 'G5-SERIAL', 'address' => 'Synthetic test address, Cairo, Egypt', 'currency' => 'EGP', 'setup_complete' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    // The M05 official-document fixture requires a legal profile. Keep even this inert baseline
    // wholly inside the enclosing rollback so no company profile edit survives the check.
    DB::table('companies')->where('id', 1)->update(['legal_name' => 'Rollback-only synthetic Gate 5 serial company',
        'tax_registration' => 'G5-SERIAL-'.strtoupper(Str::random(8)), 'address' => 'Rollback-only synthetic test address, Cairo, Egypt',
        'setup_complete' => true, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    $stage = 'synthetic branch and least-privilege actors';
    $branch = Branch::factory()->create(['code' => 'G5SER-'.strtoupper(Str::random(7))]);
    $creator = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$creator, $reviewer] as $user) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant = static function (User $user, array $permissions, ?int $branchId = null): void {
        foreach ($permissions as $permission) {
            DB::table('access_grants')->insertOrIgnore(['user_id' => $user->id, 'permission_code' => $permission, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
        }
    };
    // Assign each direct grant at its catalogued scope: company, branch, or warehouse.
    $grant($creator, ['M02.product_view', 'M03.supplier_view', 'M11.setup_manage', 'M11.tax_manage',
        'M11.tax_calculate', 'M11.opening_manage'], null);
    $grant($reviewer, ['M02.product_view', 'M03.supplier_view', 'M11.opening_approve', 'M11.financial_activate'], null);
    $grant($creator, ['M01.branch_view', 'M05.order_view', 'M05.order_create', 'M05.order_submit',
        'M05.receipt_create', 'M05.order_issue', 'M06.receipt_create', 'M06.issue_create', 'M06.issue_post',
        'M06.correction_execute', 'M06.transfer_create', 'M06.transfer_dispatch',
        'M11.journal_post'], $branch->id);
    $grant($reviewer, ['M01.branch_view', 'M05.order_view', 'M05.order_approve', 'M05.receipt_post',
        'M06.receipt_post', 'M06.issue_approve', 'M06.transfer_approve', 'M06.transfer_receive',
        'M11.receipt_post', 'M11.journal_post'], $branch->id);
    $grant($creator, ['M11.journal_post'], $branch->id);
    $grant($reviewer, ['M11.journal_post'], $branch->id);

    // Warehouse creation is company-scoped in M06; the branch-view grant above remains branch-scoped.
    $grant($creator, ['M06.warehouse_create'], null);
    $stage = 'M06 company-scoped warehouse creation';
    $stageArguments = ['actor_id' => $creator->id, 'branch_id' => $branch->id];
    $warehouse = app(InventoryActions::class)->saveWarehouse($creator->id, ['code' => 'G5SER-'.strtoupper(Str::random(7)), 'name' => 'Synthetic serial valuation warehouse', 'branch_id' => $branch->id, 'active' => true]);
    $destinationWarehouse = app(InventoryActions::class)->saveWarehouse($creator->id, ['code' => 'G5SER-DST-'.strtoupper(Str::random(6)), 'name' => 'Synthetic serial transfer destination', 'branch_id' => $branch->id, 'active' => true]);
    foreach ([$creator, $reviewer] as $user) {
        foreach ([$warehouse, $destinationWarehouse] as $scopedWarehouse) {
            DB::table('user_warehouse_scopes')->insert(['user_id' => $user->id, 'warehouse_id' => $scopedWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $product = Product::factory()->create(['kind' => 'serialized_equipment', 'tracking' => 'serial', 'active' => true]);
    $supplier = Supplier::factory()->create(['status' => 'active']);

    $stage = 'M11 company-scoped accounting setup and tax rules';
    $stageArguments = ['actor_id' => $creator->id, 'reviewer_id' => $reviewer->id, 'branch_id' => $branch->id];
    $mappings = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $mappings[$purpose] = AccountingAccount::factory()->create(['code' => 'G5SER-'.strtoupper($purpose).'-'.strtoupper(Str::random(5)), 'name' => 'Synthetic '.$label, 'kind' => $kind])->id;
    }
    DB::table('accounting_periods')->insert(['name' => 'Synthetic Gate 5 period '.Str::random(8), 'starts_on' => now('Africa/Cairo')->startOfYear()->toDateString(), 'ends_on' => now('Africa/Cairo')->endOfYear()->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    $activation = app(FinancialActivation::class);
    $financialVersion = (int) DB::table('accounting_activation')->where('id', 1)->value('version');
    $activation->save($creator->id, $financialVersion, ['go_live_date' => $goLiveDate, 'mappings' => $mappings]);
    $financialVersion++;
    foreach (['sale', 'purchase'] as $type) {
        $taxRule = ['code' => 'G5SER-'.strtoupper($type).'-'.strtoupper(Str::random(5)), 'name' => 'Synthetic '.$type.' tax', 'transaction_type' => $type, 'product_id' => null, 'rate' => '14', 'starts_on' => $goLiveDate, 'ends_on' => null, 'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Synthetic isolated Gate 5 check'];
        if ($type === 'purchase') {
            $taxRule['purchase_recoverable'] = true;
        }
        $activation->addTaxRule($creator->id, $financialVersion++, $taxRule);
    }
    $activation->validateTaxTreatment($creator->id, $financialVersion++, 'Synthetic Gate 5 receipt tax rules');
    $openings = app(OpeningBalances::class);
    $stage = 'M11 explicit no-balances opening batch with company opening_manage';
    $stageArguments = ['actor_id' => $creator->id, 'branch_id' => $branch->id];
    $batch = $openings->save($creator->id, $financialVersion, $uuid(), [], true,
        'Explicit rollback-only synthetic declaration that this isolated fixture has no opening balances.');
    $stage = 'M11 opening submission';
    $openings->advance($creator->id, $batch, 1, 'submit');
    $stage = 'M11 independent opening review and approval';
    $openings->advance($reviewer->id, $batch, 2, 'approve');
    $stage = 'M11 financial activation';
    app(ActivateFinancialPosting::class)->execute($reviewer->id, $batch, 3);
    $assert(DB::table('accounting_opening_batches')->where('id', $batch)->value('status') === 'posted', 'Financial posting is activated through independent M11 opening approval');

    $stage = 'M05 direct order, approval, and official issue';
    $orders = app(PurchaseOrders::class);
    $receiving = app(PurchaseReceiving::class);
    $receiptSources = [];
    $receiptCases = [
        ['SER-OLD-A', '10.00', now('Africa/Cairo')->subDays(3)->toDateString()],
        ['SER-NEW-B', '20.00', now('Africa/Cairo')->subDays(2)->toDateString()],
        ['SER-ONE-C', '0.01', now('Africa/Cairo')->subDay()->toDateString()],
    ];
    foreach ($receiptCases as [$serial, $price, $receiptDate]) {
        $stage = 'M05 actual serial-valued receipt and M11 source posting';
        $stageArguments = ['actor_id' => $creator->id, 'reviewer_id' => $reviewer->id, 'branch_id' => $branch->id,
            'serial' => $serial, 'unit_price' => $price];
        $order = $orders->save($creator->id, ['creation_key' => $uuid(), 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'award_id' => null,
            'order_date' => $receiptDate, 'expected_date' => null, 'currency' => 'EGP', 'payment_terms' => null, 'notes' => 'Synthetic Gate 5 serial receipt', 'reported_tax' => null,
            'lines' => [['product_id' => $product->id, 'quantity' => '1.00000000', 'unit_price' => $price, 'discount_amount' => '0.00']]], null, 0);
        $orders->transition($creator->id, $order, 1, 'submit');
        $orders->transition($reviewer->id, $order, 2, 'approve');
        $orders->issue($creator->id, $order);
        $orderLine = DB::table('purchasing_order_lines')->where('order_id', $order)->firstOrFail();
        $receipt = $receiving->save($creator->id, ['creation_key' => $uuid(), 'order_id' => $order, 'warehouse_id' => $warehouse['id'], 'receipt_date' => $receiptDate,
            'delivery_reference' => 'G5SER-'.strtoupper(Str::random(8)), 'notes' => 'Synthetic serialized receipt',
            'lines' => [['order_line_id' => $orderLine->id, 'quantity' => '1.00000000', 'serials' => [$serial]]]], null, 0);
        $receiving->submit($creator->id, $receipt, 1);
        $receiptRow = DB::table('purchasing_receipts')->find($receipt);
        app(PostPurchaseReceipt::class)->execute($reviewer->id, $receipt, $receiptRow->version);
        $receiptLine = DB::table('purchasing_receipt_lines')->where('receipt_id', $receipt)->firstOrFail();
        $posting = DB::table('purchasing_receipt_postings')->where('receipt_id', $receipt)->where('line_id', $receiptLine->id)->firstOrFail();
        $receiptSources[] = ['receipt_id' => $receipt, 'order_id' => $order, 'order_line_id' => $orderLine->id,
            'line_id' => $receiptLine->id, 'movement_id' => $posting->movement_id, 'layer_id' => $posting->layer_id,
            'serial' => $serial, 'amount' => $price];
    }
    $assert(DB::table('purchasing_receipts')->whereIn('id', array_column($receiptSources, 'receipt_id'))->where('status', 'posted')->count() === 3, 'Three real M05 serial receipt sources post through the M05/M06/M11 coordinator');
    foreach ($receiptSources as $source) {
        $receiptLine = DB::table('purchasing_receipt_lines')->where('receipt_id', $source['receipt_id'])->firstOrFail();
        $assert(json_decode($receiptLine->serials, true) === [$source['serial']], 'M05 immutable receipt line retains its exact serial source');
        $layer = DB::table('inventory_fifo_layers')->where('id', $source['layer_id'])->where('movement_id', $source['movement_id'])->firstOrFail();
        $expectedUnitCost = bcadd($source['amount'], '0', 16);
        $expectedLayer = ['original_quantity' => '1.00000000', 'original_value' => $source['amount'], 'remaining_value' => $source['amount'], 'unit_cost' => $expectedUnitCost];
        $actualLayer = ['original_quantity' => (string) $layer->original_quantity, 'original_value' => (string) $layer->original_value,
            'remaining_value' => (string) $layer->remaining_value, 'unit_cost' => (string) $layer->unit_cost];
        $assertWithDetails($actualLayer['original_quantity'] === $expectedLayer['original_quantity']
            && $actualLayer['original_value'] === $expectedLayer['original_value']
            && bccomp($actualLayer['remaining_value'], $expectedLayer['remaining_value'], 16) === 0
            && $actualLayer['unit_cost'] === $expectedLayer['unit_cost'],
            'M05 receipt creates exact source-cost FIFO layer for '.$source['serial'], $expectedLayer, $actualLayer);
        $serialRow = DB::table('inventory_serials')->where('product_id', $product->id)->where('normalized', $source['serial'])->firstOrFail();
        $assert($serialRow->source_movement_id === $layer->movement_id && $serialRow->warehouse_id === $warehouse['id'] && $serialRow->state === 'available', 'Received serial occupancy points to its actual M05 source movement');
        $assert(DB::table('inventory_serial_events')->where('serial_id', $serialRow->id)->where('movement_id', $layer->movement_id)->where('action', 'received')->count() === 1, 'M05 serial receipt has one immutable received event');
        $receiptPosting = DB::table('purchasing_receipt_postings')->where('receipt_id', $source['receipt_id'])->where('line_id', $receiptLine->id)->first();
        $expectedReceiptPosting = ['count' => 1, 'movement_id' => $layer->movement_id, 'layer_id' => $layer->id, 'amount' => $source['amount']];
        $actualReceiptPosting = $receiptPosting ? ['count' => DB::table('purchasing_receipt_postings')->where('receipt_id', $source['receipt_id'])->where('line_id', $receiptLine->id)->count(),
            'movement_id' => $receiptPosting->movement_id, 'layer_id' => $receiptPosting->layer_id, 'amount' => (string) $receiptPosting->amount] : ['count' => 0];
        $assertWithDetails($actualReceiptPosting === $expectedReceiptPosting, 'Receipt posting links exact M05 source line, M06 movement and FIFO layer', $expectedReceiptPosting, $actualReceiptPosting);
        $receipt = DB::table('purchasing_receipts')->find($source['receipt_id']);
        $assertWithDetails($receipt->capitalized_amount === $source['amount'], 'Recoverable tax treatment capitalizes the exact source net cost',
            ['capitalized_amount' => $source['amount']], ['capitalized_amount' => (string) $receipt->capitalized_amount]);
        $journal = DB::table('accounting_journals')->find($receipt->journal_id);
        $assert($journal && $journal->status === 'posted' && $journal->source_key === 'purchase-receipt:'.$source['receipt_id'], 'M11 receipt journal is posted exactly once against its M05 source');
        $receiptJournalLines = DB::table('accounting_journal_lines')->where('journal_id', $receipt->journal_id)->get();
        $inventoryDebit = (string) DB::table('accounting_journal_lines')->where('journal_id', $receipt->journal_id)->where('account_id', $mappings['inventory'])->sum('debit');
        $grniCredit = (string) DB::table('accounting_journal_lines')->where('journal_id', $receipt->journal_id)->where('account_id', $mappings['grni'])->sum('credit');
        $assertWithDetails($receiptJournalLines->count() === 2 && bccomp($inventoryDebit, $source['amount'], 2) === 0 && bccomp($grniCredit, $source['amount'], 2) === 0,
            'M11 receipt journal contains only the exact Inventory debit and GRNI credit',
            ['line_count' => 2, 'inventory_debit' => $source['amount'], 'grni_credit' => $source['amount']],
            ['line_count' => $receiptJournalLines->count(), 'inventory_debit' => $inventoryDebit, 'grni_credit' => $grniCredit]);
    }
    $assert(DB::table('accounting_journals')->whereIn('source_key', array_map(fn ($s) => 'purchase-receipt:'.$s['receipt_id'], $receiptSources))->count() === 3, 'Three M05 receipts produce exactly three source-keyed M11 journals without duplicates');
    $assert(DB::table('inventory_serials')->where('product_id', $product->id)->count() === 3
        && DB::table('inventory_serials')->where('product_id', $product->id)->whereIn('normalized', ['SER-OLD-A', 'SER-NEW-B', 'SER-ONE-C'])->count() === 3,
        'M05 receipt serial occupancy contains exactly one row per distinct source serial');

    $stage = 'M06 approved serialized issue and FIFO allocation';
    $stageArguments = ['actor_id' => $creator->id, 'reviewer_id' => $reviewer->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse['id'], 'serial' => 'SER-NEW-B'];
    $issue = app(InventoryDocuments::class)->save($creator->id, 'issue', ['creation_key' => $uuid(), 'warehouse_id' => $warehouse['id'], 'reason' => 'Issue the newer received serial to verify FIFO cost mapping',
        'lines' => [['product_id' => $product->id, 'quantity' => '1.00000000', 'serials' => ['SER-NEW-B']]]]);
    app(InventoryDocuments::class)->transition($creator->id, $issue['id'], 'submit', $issue['version']);
    $submitted = DB::table('inventory_documents')->find($issue['id']);
    app(InventoryDocuments::class)->transition($reviewer->id, $issue['id'], 'approve', $submitted->version);
    $approved = DB::table('inventory_documents')->find($issue['id']);
    app(InventoryDocuments::class)->transition($creator->id, $issue['id'], 'post', $approved->version);
    $movement = DB::table('inventory_movements')->where('document_id', $issue['id'])->where('operation', 'issue_post')->firstOrFail();
    $assertWithDetails($movement->quantity === '-1.00000000' && $movement->product_id === $product->id && $movement->warehouse_id === $warehouse['id'],
        'Approved M06 issue posts the selected serialized base quantity',
        ['quantity' => '-1.00000000', 'product_id' => $product->id, 'warehouse_id' => $warehouse['id']],
        ['quantity' => (string) $movement->quantity, 'product_id' => $movement->product_id, 'warehouse_id' => $movement->warehouse_id]);
    $chosenSerial = DB::table('inventory_serials')->where('product_id', $product->id)->where('normalized', 'SER-NEW-B')->firstOrFail();
    $assert($chosenSerial->state === 'issued' && $chosenSerial->warehouse_id === $warehouse['id'] && $chosenSerial->last_movement_id === $movement->id, 'Selected newer serial becomes issued at the source warehouse');
    $assert(DB::table('inventory_serial_events')->where('serial_id', $chosenSerial->id)->where('movement_id', $movement->id)->where('action', 'issue_post')->count() === 1, 'Selected serial has one issue event linked to the actual M06 movement');
    $allocation = DB::table('inventory_fifo_allocations as a')->join('inventory_fifo_layers as l', 'l.id', '=', 'a.layer_id')->where('a.movement_id', $movement->id)->select('a.*', 'l.source_key', 'l.movement_id as source_movement_id')->firstOrFail();
    $oldLayer = DB::table('inventory_fifo_layers')->where('id', $receiptSources[0]['layer_id'])->where('movement_id', $receiptSources[0]['movement_id'])->firstOrFail();
    $newLayer = DB::table('inventory_fifo_layers')->where('id', $receiptSources[1]['layer_id'])->where('movement_id', $receiptSources[1]['movement_id'])->firstOrFail();
    $assertWithDetails($allocation->layer_id === $oldLayer->id && $allocation->quantity === '1.00000000' && $allocation->amount === '10.00',
        'FIFO issue consumes the oldest M05 lot at its exact $10.00 source cost',
        ['layer_id' => $oldLayer->id, 'quantity' => '1.00000000', 'amount' => '10.00'],
        ['layer_id' => $allocation->layer_id, 'quantity' => (string) $allocation->quantity, 'amount' => (string) $allocation->amount]);
    $expectedLayerBalances = ['old_remaining_quantity' => '0.00000000', 'old_remaining_value' => '0.0000000000000000',
        'new_remaining_quantity' => '1.00000000', 'new_remaining_value' => '20.0000000000000000'];
    $actualLayerBalances = ['old_remaining_quantity' => (string) $oldLayer->remaining_quantity, 'old_remaining_value' => (string) $oldLayer->remaining_value,
        'new_remaining_quantity' => (string) $newLayer->remaining_quantity, 'new_remaining_value' => (string) $newLayer->remaining_value];
    $assertWithDetails($actualLayerBalances === $expectedLayerBalances, 'FIFO layer balances retain exact residual quantity and value after issue', $expectedLayerBalances, $actualLayerBalances);
    $serialAllocation = DB::table('inventory_fifo_serial_allocations')->where('serial_id', $chosenSerial->id)->where('movement_id', $movement->id)->firstOrFail();
    $serialMappingCount = DB::table('inventory_fifo_serial_allocations')->where('movement_id', $movement->id)->count();
    $assertWithDetails($serialMappingCount === 1 && $serialAllocation->allocation_id === $allocation->id && $serialAllocation->amount === '10.00',
        'Selected newer physical serial maps exactly once to the actual older FIFO allocation and cents',
        ['mapping_count' => 1, 'allocation_id' => $allocation->id, 'amount' => '10.00'],
        ['mapping_count' => $serialMappingCount, 'allocation_id' => $serialAllocation->allocation_id, 'amount' => (string) $serialAllocation->amount]);
    $expect(QueryException::class, fn () => DB::transaction(fn () => DB::table('inventory_fifo_serial_allocations')->where('id', $serialAllocation->id)->update(['amount' => '0.01'])), 'Database guard makes the serial-to-FIFO value mapping immutable');
    $retainedMappingAmount = (string) DB::table('inventory_fifo_serial_allocations')->where('id', $serialAllocation->id)->value('amount');
    $assertWithDetails($retainedMappingAmount === '10.00', 'Rejected mapping mutation leaves the original FIFO amount intact',
        ['amount' => '10.00'], ['amount' => $retainedMappingAmount]);
    $journalLink = DB::table('inventory_movement_journals')->where('movement_id', $movement->id)->firstOrFail();
    $issueJournal = DB::table('accounting_journals')->find($journalLink->journal_id);
    $issueLines = DB::table('accounting_journal_lines')->where('journal_id', $journalLink->journal_id)->get();
    $assertWithDetails($journalLink->amount === '10.00' && $issueJournal->status === 'posted' && $issueJournal->source_key === 'inventory-movement:'.$movement->id,
        'M11 issue valuation journals exact FIFO cost once against the M06 movement',
        ['amount' => '10.00', 'status' => 'posted', 'source_key' => 'inventory-movement:'.$movement->id],
        ['amount' => (string) $journalLink->amount, 'status' => $issueJournal->status, 'source_key' => $issueJournal->source_key]);
    $issueDebit = (string) DB::table('accounting_journal_lines')->where('journal_id', $journalLink->journal_id)->where('account_id', $mappings['inventory_issue'])->sum('debit');
    $inventoryCredit = (string) DB::table('accounting_journal_lines')->where('journal_id', $journalLink->journal_id)->where('account_id', $mappings['inventory'])->sum('credit');
    $assertWithDetails($issueLines->count() === 2 && bccomp($issueDebit, '10.00', 2) === 0 && bccomp($inventoryCredit, '10.00', 2) === 0,
        'M11 issue journal contains only the exact $10.00 expense debit and Inventory credit',
        ['line_count' => 2, 'issue_debit' => '10.00', 'inventory_credit' => '10.00'],
        ['line_count' => $issueLines->count(), 'issue_debit' => $issueDebit, 'inventory_credit' => $inventoryCredit]);
    $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->firstOrFail();
    $assertWithDetails($balance->on_hand === '2.00000000' && $balance->reserved === '0.00000000', 'M06 physical balance equals two remaining serialized units',
        ['on_hand' => '2.00000000', 'reserved' => '0.00000000'], ['on_hand' => (string) $balance->on_hand, 'reserved' => (string) $balance->reserved]);
    $assert(DB::table('inventory_serials')->where('product_id', $product->id)->where('state', 'available')->count() === 2
        && DB::table('inventory_serials')->where('product_id', $product->id)->where('state', 'issued')->count() === 1
        && DB::table('inventory_serials')->where('product_id', $product->id)->count() === 3,
        'M06 serial occupancy reconciles two available, one issued, and three lifetime identities');

    $beforeReplay = [DB::table('inventory_movements')->count(), DB::table('inventory_fifo_allocations')->count(), DB::table('inventory_fifo_serial_allocations')->count(), DB::table('accounting_journals')->count(), DB::table('inventory_serial_events')->count()];
    $duplicateIssue = app(InventoryDocuments::class)->save($creator->id, 'issue', ['creation_key' => $uuid(), 'warehouse_id' => $warehouse['id'], 'reason' => 'Duplicate issuance must reject an already-issued serial',
        'lines' => [['product_id' => $product->id, 'quantity' => '1.00000000', 'serials' => ['SER-NEW-B']]]]);
    app(InventoryDocuments::class)->transition($creator->id, $duplicateIssue['id'], 'submit', $duplicateIssue['version']);
    $duplicateSubmitted = DB::table('inventory_documents')->find($duplicateIssue['id']);
    app(InventoryDocuments::class)->transition($reviewer->id, $duplicateIssue['id'], 'approve', $duplicateSubmitted->version);
    $duplicateApproved = DB::table('inventory_documents')->find($duplicateIssue['id']);
    $expect(ValidationException::class, fn () => app(InventoryDocuments::class)->transition($creator->id, $duplicateIssue['id'], 'post', $duplicateApproved->version), 'M06 rejects a second source document for the already-issued serial identity');
    $assert($beforeReplay === [DB::table('inventory_movements')->count(), DB::table('inventory_fifo_allocations')->count(), DB::table('inventory_fifo_serial_allocations')->count(), DB::table('accounting_journals')->count(), DB::table('inventory_serial_events')->count()], 'Duplicate selected serial attempt creates no movement, FIFO, journal, mapping, or event rows');

    $stage = 'M06 selected-serial correction restores its exact original FIFO lot';
    $stageArguments = ['actor_id' => $creator->id, 'original_movement_id' => $movement->id, 'serial' => 'SER-NEW-B'];
    $correctionInput = ['movement_id' => $movement->id, 'quantity' => '1.00000000', 'reason' => 'Return the exact selected serial through its source FIFO allocation',
        'idempotency_key' => $uuid(), 'serials' => ['SER-NEW-B']];
    $correction = app(InventoryCorrections::class)->reverse($creator->id, $correctionInput);
    $correctionRow = DB::table('inventory_corrections')->find($correction['id']);
    $correctionMovement = DB::table('inventory_movements')->find($correctionRow->correcting_movement_id);
    $sourceCorrection = DB::table('inventory_fifo_corrections')->where('movement_id', $correctionMovement->id)->firstOrFail();
    $assertWithDetails($correctionRow->status === 'posted' && $correctionRow->movement_id === $movement->id
        && $correctionMovement->operation === 'correction' && $correctionMovement->reversal_id === $movement->id
        && $sourceCorrection->allocation_id === $allocation->id && $sourceCorrection->source_layer_id === $oldLayer->id
        && $sourceCorrection->quantity === '1.00000000' && $sourceCorrection->amount === '10.00',
        'Correction links the selected serial to its exact original $10.00 allocation and layer',
        ['status' => 'posted', 'original_movement_id' => $movement->id, 'operation' => 'correction', 'reversal_id' => $movement->id,
            'allocation_id' => $allocation->id, 'source_layer_id' => $oldLayer->id, 'quantity' => '1.00000000', 'amount' => '10.00'],
        ['status' => $correctionRow->status, 'original_movement_id' => $correctionRow->movement_id, 'operation' => $correctionMovement->operation,
            'reversal_id' => $correctionMovement->reversal_id, 'allocation_id' => $sourceCorrection->allocation_id,
            'source_layer_id' => $sourceCorrection->source_layer_id, 'quantity' => (string) $sourceCorrection->quantity, 'amount' => (string) $sourceCorrection->amount]);
    $restoredLayer = DB::table('inventory_fifo_layers')->where('movement_id', $correctionMovement->id)->firstOrFail();
    $assertWithDetails($restoredLayer->received_on === $oldLayer->received_on && $restoredLayer->original_quantity === '1.00000000'
        && $restoredLayer->original_value === '10.00' && $restoredLayer->unit_cost === $oldLayer->unit_cost,
        'Correction restores exact source date, quantity, value, and unit cost',
        ['received_on' => $oldLayer->received_on, 'original_quantity' => '1.00000000', 'original_value' => '10.00', 'unit_cost' => (string) $oldLayer->unit_cost],
        ['received_on' => $restoredLayer->received_on, 'original_quantity' => (string) $restoredLayer->original_quantity,
            'original_value' => (string) $restoredLayer->original_value, 'unit_cost' => (string) $restoredLayer->unit_cost]);
    $returnedSerial = DB::table('inventory_serials')->where('id', $chosenSerial->id)->firstOrFail();
    $assert($returnedSerial->state === 'available' && $returnedSerial->warehouse_id === $warehouse['id']
        && $returnedSerial->last_movement_id === $correctionMovement->id
        && DB::table('inventory_serial_events')->where('serial_id', $chosenSerial->id)->where('movement_id', $correctionMovement->id)->count() === 1,
        'Corrected physical serial becomes available at source with one linked correction event');
    $correctionJournalLink = DB::table('inventory_movement_journals')->where('movement_id', $correctionMovement->id)->firstOrFail();
    $correctionJournal = DB::table('accounting_journals')->find($correctionJournalLink->journal_id);
    $correctionInventoryDebit = (string) DB::table('accounting_journal_lines')->where('journal_id', $correctionJournal->id)->where('account_id', $mappings['inventory'])->sum('debit');
    $correctionIssueCredit = (string) DB::table('accounting_journal_lines')->where('journal_id', $correctionJournal->id)->where('account_id', $mappings['inventory_issue'])->sum('credit');
    $assertWithDetails($correctionJournal->status === 'posted' && $correctionJournal->source_key === 'inventory-movement:'.$correctionMovement->id
        && bccomp($correctionInventoryDebit, '10.00', 2) === 0 && bccomp($correctionIssueCredit, '10.00', 2) === 0,
        'M11 correction journal restores the exact $10.00 Inventory debit and expense credit',
        ['status' => 'posted', 'source_key' => 'inventory-movement:'.$correctionMovement->id, 'inventory_debit' => '10.00', 'issue_credit' => '10.00'],
        ['status' => $correctionJournal->status, 'source_key' => $correctionJournal->source_key, 'inventory_debit' => $correctionInventoryDebit, 'issue_credit' => $correctionIssueCredit]);
    $correctionCounts = [DB::table('inventory_movements')->count(), DB::table('inventory_fifo_corrections')->count(), DB::table('inventory_serial_events')->count(), DB::table('accounting_journals')->count()];
    $correctionReplay = app(InventoryCorrections::class)->reverse($creator->id, $correctionInput);
    $assert($correctionReplay['id'] === $correction['id'] && $correctionCounts === [DB::table('inventory_movements')->count(), DB::table('inventory_fifo_corrections')->count(), DB::table('inventory_serial_events')->count(), DB::table('accounting_journals')->count()],
        'Same correction idempotency key replays without duplicate source, FIFO, serial, or GL rows');
    $mismatch = $correctionInput;
    $mismatch['serials'] = ['SER-OLD-A'];
    $expect(ValidationException::class, fn () => app(InventoryCorrections::class)->reverse($creator->id, $mismatch), 'Correction idempotency key rejects a different selected serial');
    $assert($correctionCounts === [DB::table('inventory_movements')->count(), DB::table('inventory_fifo_corrections')->count(), DB::table('inventory_serial_events')->count(), DB::table('accounting_journals')->count()],
        'Rejected correction-key collision leaves all correction, FIFO, serial, and GL rows unchanged');

    $stage = 'M06 multi-serial issue maps physical identities to exact FIFO cents';
    $stageArguments = ['actor_id' => $creator->id, 'reviewer_id' => $reviewer->id, 'warehouse_id' => $warehouse['id'], 'serials' => ['SER-NEW-B', 'SER-OLD-A', 'SER-ONE-C']];
    $bulkIssue = app(InventoryDocuments::class)->save($creator->id, 'issue', ['creation_key' => $uuid(), 'warehouse_id' => $warehouse['id'],
        'reason' => 'Issue all rollback-only serials to verify per-allocation and one-cent FIFO identity mapping',
        'lines' => [['product_id' => $product->id, 'quantity' => '3.00000000', 'serials' => $stageArguments['serials']]]]);
    app(InventoryDocuments::class)->transition($creator->id, $bulkIssue['id'], 'submit', $bulkIssue['version']);
    $bulkSubmitted = DB::table('inventory_documents')->find($bulkIssue['id']);
    app(InventoryDocuments::class)->transition($reviewer->id, $bulkIssue['id'], 'approve', $bulkSubmitted->version);
    $bulkApproved = DB::table('inventory_documents')->find($bulkIssue['id']);
    app(InventoryDocuments::class)->transition($creator->id, $bulkIssue['id'], 'post', $bulkApproved->version);
    $bulkMovement = DB::table('inventory_movements')->where('document_id', $bulkIssue['id'])->where('operation', 'issue_post')->firstOrFail();
    $bulkAllocations = DB::table('inventory_fifo_allocations')->where('movement_id', $bulkMovement->id)->orderBy('id')->get();
    $bulkSerialMappings = DB::table('inventory_fifo_serial_allocations as s')->join('inventory_serials as i', 'i.id', '=', 's.serial_id')
        ->join('inventory_fifo_allocations as a', 'a.id', '=', 's.allocation_id')->where('s.movement_id', $bulkMovement->id)
        ->orderBy('i.normalized')->get(['i.normalized', 's.allocation_id', 's.amount', 'a.amount as allocation_amount', 'a.layer_id']);
    $expectedBulkMappings = [
        ['serial' => 'SER-NEW-B', 'amount' => '10.00', 'layer_id' => $restoredLayer->id],
        ['serial' => 'SER-OLD-A', 'amount' => '20.00', 'layer_id' => $newLayer->id],
        ['serial' => 'SER-ONE-C', 'amount' => '0.01', 'layer_id' => $receiptSources[2]['layer_id']],
    ];
    $actualBulkMappings = $bulkSerialMappings->map(fn ($row) => ['serial' => $row->normalized, 'amount' => (string) $row->amount, 'layer_id' => $row->layer_id])->values()->all();
    $assertWithDetails($bulkMovement->quantity === '-3.00000000' && $bulkAllocations->count() === 3 && $actualBulkMappings === $expectedBulkMappings,
        'Three selected physical serials map one-to-one to $10.00, $20.00, and $0.01 FIFO allocations',
        ['quantity' => '-3.00000000', 'allocation_count' => 3, 'serial_mappings' => $expectedBulkMappings],
        ['quantity' => (string) $bulkMovement->quantity, 'allocation_count' => $bulkAllocations->count(), 'serial_mappings' => $actualBulkMappings]);
    $assert(DB::table('inventory_fifo_serial_allocations')->where('movement_id', $bulkMovement->id)->count() === 3
        && DB::table('inventory_fifo_serial_allocations')->where('movement_id', $bulkMovement->id)->where('amount', '0.01')->count() === 1,
        'Database records exactly three immutable serial allocation links including the one-cent unit');

    $stage = 'M06 serial-specific correction follows each saved FIFO allocation';
    foreach ($expectedBulkMappings as $mapped) {
        $stageArguments = ['actor_id' => $creator->id, 'original_movement_id' => $bulkMovement->id, 'serial' => $mapped['serial'], 'expected_amount' => $mapped['amount']];
        $savedMapping = DB::table('inventory_fifo_serial_allocations as s')->join('inventory_serials as i', 'i.id', '=', 's.serial_id')
            ->join('inventory_fifo_allocations as a', 'a.id', '=', 's.allocation_id')
            ->where('s.movement_id', $bulkMovement->id)->where('i.normalized', $mapped['serial'])
            ->first(['s.allocation_id', 's.amount', 'a.layer_id']);
        if (! $savedMapping) {
            throw new RuntimeException('Missing saved serial-to-FIFO allocation for '.$mapped['serial'].'.');
        }
        $correctionInput = ['movement_id' => $bulkMovement->id, 'quantity' => '1.00000000', 'reason' => 'Restore selected unit against its saved actual FIFO allocation',
            'idempotency_key' => $uuid(), 'serials' => [$mapped['serial']]];
        $selectedCorrection = app(InventoryCorrections::class)->reverse($creator->id, $correctionInput);
        $selectedCorrectionMovement = DB::table('inventory_movements')->find($selectedCorrection['correcting_movement_id']);
        $selectedCorrectionSource = DB::table('inventory_fifo_corrections')->where('movement_id', $selectedCorrectionMovement->id)->firstOrFail();
        $selectedJournalLink = DB::table('inventory_movement_journals')->where('movement_id', $selectedCorrectionMovement->id)->firstOrFail();
        $selectedJournal = DB::table('accounting_journals')->find($selectedJournalLink->journal_id);
        $selectedInventoryDebit = (string) DB::table('accounting_journal_lines')->where('journal_id', $selectedJournal->id)->where('account_id', $mappings['inventory'])->sum('debit');
        $selectedIssueCredit = (string) DB::table('accounting_journal_lines')->where('journal_id', $selectedJournal->id)->where('account_id', $mappings['inventory_issue'])->sum('credit');
        $assertWithDetails($selectedCorrectionSource->allocation_id === $savedMapping->allocation_id
            && $selectedCorrectionSource->source_layer_id === $savedMapping->layer_id && $selectedCorrectionSource->amount === $mapped['amount']
            && $selectedJournalLink->amount === $mapped['amount'] && $selectedJournal->status === 'posted'
            && $selectedJournal->source_key === 'inventory-movement:'.$selectedCorrectionMovement->id
            && bccomp($selectedInventoryDebit, $mapped['amount'], 2) === 0 && bccomp($selectedIssueCredit, $mapped['amount'], 2) === 0,
            'Serial '.$mapped['serial'].' correction restores its saved FIFO allocation, exact cents, and M11 journal',
            ['allocation_id' => $savedMapping->allocation_id, 'source_layer_id' => $savedMapping->layer_id, 'amount' => $mapped['amount'],
                'journal_amount' => $mapped['amount'], 'journal_status' => 'posted', 'inventory_debit' => $mapped['amount'], 'issue_credit' => $mapped['amount']],
            ['allocation_id' => $selectedCorrectionSource->allocation_id, 'source_layer_id' => $selectedCorrectionSource->source_layer_id,
                'amount' => (string) $selectedCorrectionSource->amount, 'journal_amount' => (string) $selectedJournalLink->amount, 'journal_status' => $selectedJournal->status,
                'inventory_debit' => $selectedInventoryDebit, 'issue_credit' => $selectedIssueCredit]);
    }
    $assert(DB::table('inventory_serials')->where('product_id', $product->id)->where('warehouse_id', $warehouse['id'])->where('state', 'available')->count() === 3,
        'All three corrected physical serials are available again at their original warehouse');

    $stage = 'M06 transfer dispatch retains each original FIFO lot and serial cents';
    $transferSerials = ['SER-NEW-B', 'SER-OLD-A', 'SER-ONE-C'];
    $transfer = app(InventoryDocuments::class)->save($creator->id, 'transfer', ['creation_key' => $uuid(), 'warehouse_id' => $warehouse['id'], 'destination_id' => $destinationWarehouse['id'],
        'reason' => 'Transfer synthetic serials across warehouses with source FIFO values retained',
        'lines' => [['product_id' => $product->id, 'quantity' => '3.00000000', 'serials' => $transferSerials]]]);
    app(InventoryDocuments::class)->transition($creator->id, $transfer['id'], 'submit', $transfer['version']);
    $transferSubmitted = DB::table('inventory_documents')->find($transfer['id']);
    app(InventoryDocuments::class)->transition($reviewer->id, $transfer['id'], 'approve', $transferSubmitted->version);
    $transferApproved = DB::table('inventory_documents')->find($transfer['id']);
    app(InventoryDocuments::class)->transition($creator->id, $transfer['id'], 'dispatch', $transferApproved->version);
    $transferLine = DB::table('inventory_document_lines')->where('document_id', $transfer['id'])->firstOrFail();
    $dispatchMovement = DB::table('inventory_movements')->where('document_id', $transfer['id'])->where('operation', 'transfer_dispatch')->firstOrFail();
    $transferLots = DB::table('inventory_transfer_lots as t')->join('inventory_fifo_allocations as a', 'a.id', '=', 't.allocation_id')
        ->where('t.document_id', $transfer['id'])->orderBy('a.id')->get(['t.id', 't.allocation_id', 't.original_quantity', 't.original_value', 'a.amount']);
    $assertWithDetails($dispatchMovement->quantity === '-3.00000000' && $transferLots->count() === 3
        && $transferLots->map(fn ($lot) => (string) $lot->original_value)->all() === ['10.00', '20.00', '0.01'],
        'Dispatch creates three transit lots with original FIFO values preserved exactly',
        ['quantity' => '-3.00000000', 'lot_count' => 3, 'original_values' => ['10.00', '20.00', '0.01']],
        ['quantity' => (string) $dispatchMovement->quantity, 'lot_count' => $transferLots->count(), 'original_values' => $transferLots->pluck('original_value')->map(fn ($value) => (string) $value)->all()]);
    $dispatchMappings = DB::table('inventory_fifo_serial_allocations as s')->join('inventory_serials as i', 'i.id', '=', 's.serial_id')
        ->where('s.movement_id', $dispatchMovement->id)->orderBy('i.normalized')->get(['i.normalized', 's.amount', 's.allocation_id']);
    $expectedDispatchMappings = [['normalized' => 'SER-NEW-B', 'amount' => '10.00'], ['normalized' => 'SER-OLD-A', 'amount' => '20.00'], ['normalized' => 'SER-ONE-C', 'amount' => '0.01']];
    $actualDispatchMappings = $dispatchMappings->map(fn ($row) => ['normalized' => $row->normalized, 'amount' => (string) $row->amount])->values()->all();
    $assertWithDetails($actualDispatchMappings === $expectedDispatchMappings, 'Dispatch serial-to-FIFO cents retain all three source mappings', $expectedDispatchMappings, $actualDispatchMappings);

    $stage = 'M06 partial transfer receive quarantines the actual damaged serial';
    $transferReceipt1 = app(InventoryTransfers::class)->receive($reviewer->id, $transfer['id'], ['idempotency_key' => $uuid(), 'reason' => 'Physical count: one accepted, one damaged, one still missing',
        'lines' => [['line_id' => $transferLine->id, 'accepted' => '1.00000000', 'damaged' => '1.00000000', 'missing' => '1.00000000', 'recovered_missing' => '0.00000000',
            'accepted_serials' => ['SER-NEW-B'], 'damaged_serials' => ['SER-OLD-A']]]]);
    $firstTransferReceipt = DB::table('inventory_transfer_receipts')->where('document_id', $transfer['id'])->orderBy('id')->firstOrFail();
    $firstReceiptLine = DB::table('inventory_transfer_receipt_lines')->where('receipt_id', $firstTransferReceipt->id)->where('line_id', $transferLine->id)->firstOrFail();
    $assert($transferReceipt1['status'] === 'dispatched' && $firstReceiptLine->accepted === '1.00000000' && $firstReceiptLine->damaged === '1.00000000'
        && json_decode($firstReceiptLine->accepted_serials, true) === ['SER-NEW-B'] && json_decode($firstReceiptLine->damaged_serials, true) === ['SER-OLD-A'],
        'First partial receive records accepted and damaged physical identities while one dispatch unit remains unresolved');
    $acceptedSerial = DB::table('inventory_serials')->where('product_id', $product->id)->where('normalized', 'SER-NEW-B')->firstOrFail();
    $damagedSerial = DB::table('inventory_serials')->where('product_id', $product->id)->where('normalized', 'SER-OLD-A')->firstOrFail();
    $transitSerial = DB::table('inventory_serials')->where('product_id', $product->id)->where('normalized', 'SER-ONE-C')->firstOrFail();
    $damageIsolation = $damagedSerial->isolation_id ? DB::table('inventory_isolations')->where('id', $damagedSerial->isolation_id)
        ->where('warehouse_id', $destinationWarehouse['id'])->where('product_id', $product->id)->first() : null;
    $damageMovement = $damageIsolation ? DB::table('inventory_movements')->where('id', $damageIsolation->source_movement_id)->first() : null;
    $assert($acceptedSerial->state === 'available' && $acceptedSerial->warehouse_id === $destinationWarehouse['id']
        && $damagedSerial->state === 'unavailable' && $damagedSerial->warehouse_id === $destinationWarehouse['id']
        && $damagedSerial->isolation_id === $damageIsolation?->id && $damageMovement?->operation === 'transfer_receive'
        && $damageMovement?->document_id === $transfer['id'] && $transitSerial->state === 'transit'
        && $transitSerial->transfer_id === $transfer['id'] && $damageIsolation !== null,
        'Accepted serial reaches destination, damaged serial is isolated there, and unresolved serial stays in transit');
    $assert(DB::table('inventory_serials')->where('product_id', $product->id)->whereIn('normalized', ['SER-NEW-B', 'SER-OLD-A', 'SER-ONE-C'])->count() === 3,
        'Partial transfer preserves one unique physical identity row per selected serial');

    $stage = 'M06 recovered-missing transfer receipt reconciles final serial and FIFO cents';
    $transferReceipt2 = app(InventoryTransfers::class)->receive($reviewer->id, $transfer['id'], ['idempotency_key' => $uuid(), 'reason' => 'Recover the previously missing serial after physical follow-up',
        'lines' => [['line_id' => $transferLine->id, 'accepted' => '1.00000000', 'damaged' => '0.00000000', 'missing' => '0.00000000', 'recovered_missing' => '1.00000000',
            'accepted_serials' => ['SER-ONE-C'], 'damaged_serials' => []]]]);
    $secondTransferReceipt = DB::table('inventory_transfer_receipts')->where('document_id', $transfer['id'])->orderByDesc('id')->firstOrFail();
    $receivedSerial = DB::table('inventory_serials')->where('product_id', $product->id)->where('normalized', 'SER-ONE-C')->firstOrFail();
    $assert($transferReceipt2['status'] === 'received' && $receivedSerial->state === 'available' && $receivedSerial->warehouse_id === $destinationWarehouse['id']
        && $receivedSerial->transfer_id === null, 'Recovered missing serial moves from transit to available at the destination');
    $destinationBalance = DB::table('inventory_balances')->where('warehouse_id', $destinationWarehouse['id'])->where('product_id', $product->id)->firstOrFail();
    $assertWithDetails($destinationBalance->on_hand === '3.00000000' && $destinationBalance->unavailable === '1.00000000'
        && $destinationBalance->available === '2.00000000', 'Destination balance reconciles accepted and quarantined serial quantities',
        ['on_hand' => '3.00000000', 'unavailable' => '1.00000000', 'available' => '2.00000000'],
        ['on_hand' => (string) $destinationBalance->on_hand, 'unavailable' => (string) $destinationBalance->unavailable, 'available' => (string) $destinationBalance->available]);
    $receivedTransferAllocations = DB::table('inventory_transfer_allocations as a')->join('inventory_transfer_lots as t', 't.id', '=', 'a.lot_id')
        ->where('t.document_id', $transfer['id'])->orderBy('t.id')->get(['t.original_value', 'a.amount', 'a.quantity']);
    $assertWithDetails($receivedTransferAllocations->count() === 3
        && $receivedTransferAllocations->map(fn ($row) => (string) $row->amount)->all() === ['10.00', '20.00', '0.01'],
        'Transfer receipt allocations preserve exact original FIFO cents including one-cent lot',
        ['count' => 3, 'amounts' => ['10.00', '20.00', '0.01']],
        ['count' => $receivedTransferAllocations->count(), 'amounts' => $receivedTransferAllocations->pluck('amount')->map(fn ($value) => (string) $value)->all()]);
    $destinationSerialMappings = DB::table('inventory_fifo_serial_allocations as s')->join('inventory_serials as i', 'i.id', '=', 's.serial_id')
        ->join('inventory_movements as m', 'm.id', '=', 's.movement_id')->where('m.document_id', $transfer['id'])
        ->where('m.operation', 'transfer_receive')->orderBy('i.normalized')->get(['i.normalized', 's.amount', 'm.operation']);
    $expectedDestinationMappings = [['normalized' => 'SER-NEW-B', 'amount' => '10.00', 'operation' => 'transfer_receive'],
        ['normalized' => 'SER-OLD-A', 'amount' => '20.00', 'operation' => 'transfer_receive'],
        ['normalized' => 'SER-ONE-C', 'amount' => '0.01', 'operation' => 'transfer_receive']];
    $actualDestinationMappings = $destinationSerialMappings->map(fn ($row) => ['normalized' => $row->normalized, 'amount' => (string) $row->amount, 'operation' => $row->operation])->values()->all();
    $assertWithDetails($actualDestinationMappings === $expectedDestinationMappings, 'Accepted serials inherit their actual per-lot FIFO cents; damaged serial remains in destination quarantine',
        $expectedDestinationMappings, $actualDestinationMappings);

    $stage = 'deferred serialized FIFO, correction, transit, and GL constraints';
    $requiredDeferredConstraints = [
        'inventory_serial_fifo_movement', 'inventory_serial_fifo_mapping', 'inventory_correction_commit',
        'inventory_fifo_correction_reconcile', 'inventory_transit_lot_reconcile', 'inventory_transit_allocation_reconcile',
        'inventory_valued_extension_commit', 'inventory_financial_commit', 'inventory_fifo_cost_lineage', 'inventory_fifo_layer_reconcile',
        'inventory_fifo_allocation_reconcile', 'inventory_serial_movement_quantity', 'inventory_serial_balance_check',
        'inventory_serial_location_check', 'inventory_transfer_receipt_quantity', 'purchasing_receipt_finance_reconcile',
        'purchasing_receipt_evidence_reconcile',
    ];
    $foundDeferredConstraints = DB::table('pg_constraint')->whereIn('conname', $requiredDeferredConstraints)->where('condeferrable', true)
        ->orderBy('conname')->pluck('conname')->all();
    sort($requiredDeferredConstraints);
    $assertWithDetails($foundDeferredConstraints === $requiredDeferredConstraints, 'All named serial/FIFO/correction/transit/source-GL constraints exist and are deferred',
        ['constraints' => $requiredDeferredConstraints], ['constraints' => $foundDeferredConstraints]);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $assert(true, 'SET CONSTRAINTS ALL IMMEDIATE completed: deferred serial/FIFO identity, correction source, transit allocation, and M11 GL guards validated before PASS');
} catch (Throwable $caught) {
    $error = $caught;
    $failureTrace = array_map(static fn (array $frame): array => array_filter([
        'file' => isset($frame['file']) ? basename($frame['file']) : null,
        'line' => $frame['line'] ?? null,
        'class' => $frame['class'] ?? null,
        'function' => $frame['function'] ?? null,
    ], static fn (mixed $value): bool => $value !== null), $caught->getTrace());
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $rollbackVerified = $counts() === $beforeCounts;
}

$result = [
    'all_pass' => $error === null && $rollbackVerified,
    'mode' => 'gate5-serialized-valued-stock',
    'phase' => $stage,
    'phase_arguments' => $stageArguments,
    'database' => $target['database'],
    'port' => $target['port'],
    'server_version_num' => (int) $identity->server_version_num,
    'assertions' => $assertions,
    'rollback_verified' => $rollbackVerified,
    'before_counts' => $beforeCounts,
    'after_counts' => $counts(),
    'passed' => $passed,
    'private_diagnostics' => $diagnostics,
    'failure' => $error ? ['class' => $error::class, 'message' => mb_substr($error->getMessage(), 0, 600), 'trace_frames' => $failureTrace] : null,
    'recorded_at_utc' => gmdate(DATE_ATOM),
];
file_put_contents($receiptPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
@chmod($receiptPath, 0600);
echo 'RESULT all_pass='.(int) $result['all_pass'].' assertions='.$assertions.' rollback_verified='.(int) $rollbackVerified.' phase='.$stage.' receipt='.$receiptPath.PHP_EOL;
if (! $result['all_pass']) {
    exit(1);
}
