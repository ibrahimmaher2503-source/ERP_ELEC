<?php

declare(strict_types=1);

// Real owner-action financial graph for the isolated page-speed audit database.
// Invoke only after the performance agent has finished its other fixture writes.
use App\Application\ActivateFinancialPosting;
use App\Application\PostPurchaseReceipt;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

$root = dirname(__DIR__, 3);
$cluster = $root.'/.tmp/page-speed-audit/postgres-55461';
$markerPath = $cluster.'/codex-owned.json';
$storage = getenv('E2E_APP_STORAGE') ?: '';
$expectedMarker = getenv('E2E_CLUSTER_MARKER') ?: '';
$expectedToken = getenv('E2E_CLUSTER_TOKEN') ?: '';
if ((int) (getenv('E2E_DB_PORT') ?: 0) !== 55461 || getenv('E2E_DB_DATABASE') !== 'erp_elec_new_test'
    || ! str_contains(str_replace('\\', '/', $storage), '/.tmp/page-speed-audit/')) {
    throw new RuntimeException('ABORT: only the owned page-speed PostgreSQL database and private storage are accepted.');
}
if (! is_file($markerPath)) throw new RuntimeException('ABORT: isolated PostgreSQL ownership marker is missing.');
$marker = json_decode((string) file_get_contents($markerPath), true, 512, JSON_THROW_ON_ERROR);
if (($marker['kind'] ?? null) !== 'codex-e2e-postgres' || ($marker['repo'] ?? null) !== realpath($root)
    || (int) ($marker['port'] ?? 0) !== 55461 || (int) ($marker['version'] ?? 0) !== 1
    || $expectedMarker === '' || realpath($expectedMarker) !== realpath($markerPath)
    || strlen($expectedToken) < 32 || ! hash_equals((string) ($marker['token'] ?? ''), $expectedToken)) {
    throw new RuntimeException('ABORT: isolated PostgreSQL ownership marker does not match this workspace.');
}
putenv('APP_ENV=testing'); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql'); $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL='); $_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
putenv('DB_HOST=127.0.0.1'); $_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
putenv('DB_PORT=55461'); $_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = '55461';
putenv('DB_DATABASE=erp_elec_new_test'); $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();

$stage = 'database identity';
set_exception_handler(static function (Throwable $error) use (&$stage): never {
    fwrite(STDERR, json_encode(['fixture_error' => $error::class, 'stage' => $stage,
        'message' => mb_substr($error->getMessage(), 0, 500)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
});
$identity = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || $identity->db !== 'erp_elec_new_test' || ! in_array($identity->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || (int) $identity->port !== 55461 || realpath((string) DB::scalar('show data_directory')) !== realpath($cluster)
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)) {
    throw new RuntimeException('ABORT before writes: connected database is not the owned E2E PostgreSQL cluster.');
}
$makerId = (int) (getenv('AUDIT_ACTOR_ID') ?: 0);
$reviewerId = (int) (getenv('PERF_FINANCIAL_APPROVER_ID') ?: 0);
if ($makerId !== 122 || $reviewerId !== 123 || $makerId === $reviewerId) {
    throw new RuntimeException('ABORT: expected existing isolated performance actors 122/123.');
}
$maker = User::query()->findOrFail($makerId);
$reviewer = User::query()->findOrFail($reviewerId);
if (! $maker->active || ! $maker->hasVerifiedEmail() || ! $reviewer->active || ! $reviewer->hasVerifiedEmail()) {
    throw new RuntimeException('ABORT: both existing audit actors must be active and verified.');
}
$branchId = (int) DB::table('user_branch_scopes as a')->join('user_branch_scopes as b', 'b.branch_id', '=', 'a.branch_id')
    ->join('branches as br', 'br.id', '=', 'a.branch_id')->where('a.user_id', $makerId)->where('b.user_id', $reviewerId)
    ->where('br.active', true)->orderBy('br.id')->value('br.id');
$scope = DB::table('user_warehouse_scopes as a')->join('user_warehouse_scopes as b', function ($join) {
    $join->on('b.warehouse_id', '=', 'a.warehouse_id')->on('b.branch_id', '=', 'a.branch_id');
})->join('inventory_warehouses as w', 'w.id', '=', 'a.warehouse_id')->where('a.user_id', $makerId)->where('b.user_id', $reviewerId)
    ->where('a.branch_id', $branchId)->where('w.active', true)->orderBy('w.id')->first(['w.id as warehouse_id', 'a.branch_id']);
if ($branchId < 1 || ! $scope) throw new RuntimeException('ABORT: actors lack a shared active branch and warehouse scope.');
$branch = (int) $scope->branch_id;
$warehouseId = (int) $scope->warehouse_id;
$tag = 'PERF-FIN-20261031';
$today = now('Africa/Cairo')->toDateString();
$dateKey = static fn (string $name): string => Uuid::uuid5(Uuid::NAMESPACE_URL, 'codex-performance-financial:'.$name)->toString();

// The exact source graph is committed atomically. A failed attempt leaves no partial
// postings; successful reruns detect the durable tag and only report its identities.
$existing = DB::table('rp_supplier_invoices')->where('supplier_invoice_number', $tag.'-SUP')->first();
if ($existing) {
    $receipt = DB::table('purchasing_receipts')->where('delivery_reference', $tag.'-RECEIPT')->first();
    $movement = DB::table('treasury_movements')->where('reference', $tag.' customer advance')->first();
    if ($existing->status !== 'posted' || ! $receipt
        || ! DB::table('purchasing_receipt_postings')->where('receipt_id', $receipt->id)->exists()
        || ! $movement || $movement->kind !== 'customer_collection') {
        throw new RuntimeException('Tagged fixture is incomplete; refusing to replay irreversible owner actions.');
    }
    echo json_encode(['database' => $identity->db, 'port' => 55461, 'already_present' => true,
        'supplier_invoice_id' => (int) $existing->id, 'receipt_id' => (int) $receipt->id,
        'collection_movement_id' => (int) $movement->id,
        'treasury_movement_count' => DB::table('treasury_movements')->where('reference', 'like', $tag.'%')->count()], JSON_THROW_ON_ERROR);
    exit(0);
}

$created = DB::transaction(function () use ($makerId, $reviewerId, $branch, $warehouseId, $today, $tag, $dateKey, &$stage): array {
    $maker = User::query()->findOrFail($makerId);
    $reviewer = User::query()->findOrFail($reviewerId);
    $supplier = Supplier::query()->where('code', $tag.'-SUP')->first() ?: Supplier::factory()->create(['code' => $tag.'-SUP', 'status' => 'active']);
    $customer = Customer::query()->where('code', $tag.'-CUS')->first() ?: Customer::factory()->create(['code' => $tag.'-CUS', 'status' => 'active']);
    $product = Product::query()->where('sku', $tag.'-SKU')->first() ?: Product::factory()->create([
        'sku' => $tag.'-SKU', 'kind' => 'bulk', 'tracking' => 'quantity', 'active' => true,
    ]);

    $stage = 'M11 owner accounting activation';
    $activation = DB::table('accounting_activation')->where('id', 1)->lockForUpdate()->first();
    if (! $activation) throw new RuntimeException('M11 activation row missing.');
    $mappings = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $configured = DB::table('accounting_account_mappings')->where('purpose', $purpose)->value('account_id');
        if ($configured) { $mappings[$purpose] = (int) $configured; continue; }
        if ($activation->activated_at !== null) throw new RuntimeException('Activated M11 has no required '.$purpose.' mapping; refusing mutation.');
        $usedAccountIds = array_values(array_map('intval', array_values($mappings)));
        $accountQuery = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->where('kind', $kind);
        if ($usedAccountIds !== []) $accountQuery->whereNotIn('id', $usedAccountIds);
        $account = $accountQuery->orderBy('id')->value('id');
        if (! $account) $account = app(AccountingActions::class)->account($makerId, null, 0, [
            'code' => $tag.'-'.strtoupper($purpose), 'name' => 'اختبار أداء '.$label, 'kind' => $kind,
            'parent_id' => null, 'postable' => true, 'active' => true,
        ]);
        $mappings[$purpose] = (int) $account;
    }
    $version = (int) $activation->version;
    if ($activation->activated_at === null) {
        $period = DB::table('accounting_periods')->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->first();
        if (! $period) {
            $start = now('Africa/Cairo')->startOfYear()->toDateString();
            $end = now('Africa/Cairo')->endOfYear()->toDateString();
            app(AccountingActions::class)->period($makerId, ['name' => 'فترة أداء محلية '.now('Africa/Cairo')->year, 'starts_on' => $start, 'ends_on' => $end]);
        }
        $setup = app(FinancialActivation::class);
        $setup->save($makerId, $version++, ['go_live_date' => $today, 'mappings' => $mappings]);
        foreach ([['sale', null], ['purchase', (int) $product->id]] as [$type, $productId]) {
            $hasRule = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
                ->where('r.transaction_type', $type)->where(function ($q) use ($productId) { $q->whereNull('r.product_id')->orWhere('r.product_id', $productId); })
                ->where('r.starts_on', '<=', $today)->whereRaw('(coalesce(e.ends_on,r.ends_on) is null or coalesce(e.ends_on,r.ends_on)>=?::date)', [$today])
                ->whereRaw('coalesce(e.withdrawn,false)=false')->exists();
            if (! $hasRule) {
                $tax = ['code' => $tag.'-'.strtoupper($type), 'name' => 'Synthetic performance '.$type.' tax (not statutory)',
                    'transaction_type' => $type, 'product_id' => $productId, 'rate' => '14', 'starts_on' => $today,
                    'ends_on' => null, 'treatment' => 'percentage', 'tax_scale' => 2,
                    'legal_reference' => 'Synthetic isolated performance fixture only; not a statutory treatment.'];
                if ($type === 'purchase') $tax['purchase_recoverable'] = true;
                $setup->addTaxRule($makerId, $version++, $tax);
            }
        }
        $setup->validateTaxTreatment($makerId, $version++, 'Synthetic isolated performance fixture tax validation.');
        $batch = app(OpeningBalances::class)->save($makerId, $version, $dateKey('opening'), [], true,
            'Synthetic test declaration: no prior opening balances assumed by this isolated fixture.');
        app(OpeningBalances::class)->advance($makerId, $batch, 1, 'submit');
        app(OpeningBalances::class)->advance($reviewerId, $batch, 2, 'approve');
        app(ActivateFinancialPosting::class)->execute($reviewerId, $batch, 3);
    }
    $cashAccount = (int) (DB::table('treasury_accounts')->where('active', true)->where('kind', 'cash')->value('id') ?: 0);
    if ($cashAccount < 1) {
        $cashAccount = app(TreasuryApi::class)->saveOperationalAccount($makerId, [
            'code' => $tag.'-CASH', 'name' => 'نقدية اختبار الأداء', 'kind' => 'cash', 'gl_account_id' => $mappings['cash'],
        ]);
    }

    $stage = 'M05/M06 posted purchase receipt owner actions';
    $orders = app(PurchaseOrders::class);
    $orderId = $orders->save($makerId, ['creation_key' => $dateKey('purchase-order'), 'branch_id' => $branch,
        'supplier_id' => (int) $supplier->id, 'order_date' => $today, 'expected_date' => null, 'currency' => 'EGP',
        'payment_terms' => 'Synthetic isolated performance terms', 'notes' => 'Actual source actions for local page performance fixtures.',
        'tax_mode' => 'exclusive', 'reported_tax' => null,
        'lines' => [['product_id' => (int) $product->id, 'quantity' => '3.00000000', 'unit_price' => '10.00000000', 'discount_amount' => '0.00']]], null, 0);
    $orders->transition($makerId, $orderId, 1, 'submit');
    $orders->transition($reviewerId, $orderId, 2, 'approve');
    $orders->issue($reviewerId, $orderId);
    $orderLineId = (int) DB::table('purchasing_order_lines')->where('order_id', $orderId)->value('id');
    $receiptId = app(PurchaseReceiving::class)->save($makerId, ['creation_key' => $dateKey('receipt'), 'order_id' => $orderId,
        'warehouse_id' => $warehouseId, 'receipt_date' => $today, 'delivery_reference' => $tag.'-RECEIPT',
        'notes' => 'Synthetic isolated real owner-action receipt.',
        'lines' => [['order_line_id' => $orderLineId, 'quantity' => '3.00000000']]], null, 0);
    app(PurchaseReceiving::class)->submit($makerId, $receiptId, 1);
    app(PostPurchaseReceipt::class)->execute($reviewerId, $receiptId, 2);
    $receiptLineId = (int) DB::table('purchasing_receipt_lines')->where('receipt_id', $receiptId)->value('id');

    $stage = 'M12 posted supplier invoice owner actions';
    $rp = app(ReceivablesPayablesApi::class);
    $supplierInvoiceId = $rp->createSupplierInvoice($makerId, ['creation_key' => $dateKey('supplier-invoice'),
        'supplier_id' => (int) $supplier->id, 'supplier_invoice_number' => $tag.'-SUP', 'invoice_date' => $today,
        'due_date' => now('Africa/Cairo')->addDays(30)->toDateString(), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $orderLineId, 'quantity' => '3.00000000', 'unit_price' => '10.00000000',
            'discount_amount' => '0.00', 'matches' => [['receipt_line_id' => $receiptLineId, 'quantity' => '3.00000000']]]]]);
    $rp->validateSupplierInvoice($makerId, $supplierInvoiceId, 1);
    $rp->matchSupplierInvoice($makerId, $supplierInvoiceId, 2);
    $rp->approveSupplierInvoice($makerId, $supplierInvoiceId, 3);
    $rp->postSupplierInvoice($makerId, $supplierInvoiceId, 4);
    $ap = DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $supplierInvoiceId)->first();
    if (! $ap) throw new RuntimeException('Posted supplier invoice did not create its AP open item.');

    $stage = 'M13 customer advance owner action';
    $advanceAccount = (int) DB::table('accounting_account_mappings')->where('purpose', 'customer_advance')->value('account_id');
    if ($advanceAccount < 1) {
        $advanceAccount = app(AccountingActions::class)->account($makerId, null, 0, [
            'code' => $tag.'-ADVANCE', 'name' => 'دفعات عملاء مقدمة لاختبار الأداء', 'kind' => 'liability',
            'parent_id' => null, 'postable' => true, 'active' => true,
        ]);
        app(FinancialActivation::class)->saveOptionalMapping($makerId, 'customer_advance', (int) $advanceAccount);
    }
    $treasury = app(TreasuryApi::class);
    $collection = $treasury->executeCustomerCollection($makerId, ['idempotency_key' => $dateKey('collection-advance'),
        'branch_id' => $branch, 'customer_id' => (int) $customer->id, 'account_id' => $cashAccount, 'date' => $today,
        'amount' => '10.00', 'allow_advance' => true, 'reference' => $tag.' customer advance'], []);
    if (! DB::table('purchasing_receipt_postings')->where('receipt_id', $receiptId)->exists()
        || DB::table('rp_supplier_invoices')->where('id', $supplierInvoiceId)->value('status') !== 'posted'
        || ! DB::table('treasury_movements')->where('id', $collection['movement_id'])->where('kind', 'customer_collection')->exists()
        || DB::table('treasury_movements')->where('id', $collection['movement_id'])->where('reference', $tag.' customer advance')->value('amount') !== '10.00') {
        throw new RuntimeException('Readback did not find the actual posted receipt, AP invoice, and Treasury collection movement.');
    }
    return ['supplier_id' => (int) $supplier->id, 'customer_id' => (int) $customer->id,
        'product_id' => (int) $product->id, 'branch_id' => $branch, 'warehouse_id' => $warehouseId,
        'order_id' => $orderId, 'receipt_id' => $receiptId, 'supplier_invoice_id' => $supplierInvoiceId,
        'collection_movement_id' => (int) $collection['movement_id'], 'customer_advance_amount' => '10.00'];
});
echo json_encode(['database' => $identity->db, 'port' => 55461, 'tag' => $tag,
    'source_actions_succeeded' => true, 'financial_fixture' => $created,
    'counts' => ['posted_receipts' => DB::table('purchasing_receipt_postings')->count(),
        'posted_supplier_invoices' => DB::table('rp_supplier_invoices')->where('status', 'posted')->count(),
        'treasury_movements' => DB::table('treasury_movements')->count()]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
