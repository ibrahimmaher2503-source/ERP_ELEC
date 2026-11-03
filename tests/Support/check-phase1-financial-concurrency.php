<?php

use App\Application\PostPurchaseReceipt;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Application\ApproveSalesOrder;
use App\Modules\Sales\Application\SalesDeliveries;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = $root.'/.tmp/phase1-supplier-invoice-storage';
if (! is_dir($storage) && ! mkdir($storage, 0700, true) && ! is_dir($storage)) {
    throw new RuntimeException('Cannot create the private Phase 1 test storage directory.');
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$actual = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if ($connection->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql'
    || $connection->getDatabaseName() !== 'erp_elec_new_test' || config('database.connections.pgsql.database') !== 'erp_elec_new_test'
    || config('database.connections.pgsql.host') !== '127.0.0.1' || ! in_array((string) config('database.connections.pgsql.port'), ['5443', '55442'], true)
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true) || $actual->db !== 'erp_elec_new_test'
    || ! in_array($actual->address, ['127.0.0.1', '127.0.0.1/32'], true) || ! in_array((string) $actual->port, ['5443', '55442'], true)) {
    throw new RuntimeException('ABORT before writes: Phase 1 concurrency requires isolated erp_elec_new_test PostgreSQL on loopback port 5443 or disposable 55442.');
}

$reuseCommitted = getenv('PHASE1_REUSE_COMMITTED_FIXTURES') === '1';
if ($reuseCommitted) {
    $evidenceFile = $root.'/docs/qa/evidence/phase1-financial-journeys.json';
    if (! is_file($evidenceFile)) {
        throw new RuntimeException('Cannot reuse missing committed financial journey evidence.');
    }
    $fixtures = json_decode(file_get_contents($evidenceFile), true, 512, JSON_THROW_ON_ERROR);
} else {
    $prepare = new Process([PHP_BINARY, $root.'/tests/Support/check-phase1-financial-journeys.php'], $root, ['PHASE1_COMMIT_RACE_FIXTURES' => '1'], null, 180);
    $prepare->run();
    if (! $prepare->isSuccessful()) {
        throw new RuntimeException('Committed Phase 1 worker fixture preparation failed: '.mb_substr(trim($prepare->getErrorOutput().' '.$prepare->getOutput()), 0, 1800));
    }
    $fixtures = json_decode($prepare->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}
$workers = $fixtures['committed_worker_fixtures'] ?? [];
if (($fixtures['all_pass'] ?? false) !== true || count($workers) !== 3) {
    throw new RuntimeException('Committed Phase 1 worker fixture evidence is incomplete.');
}

$race = static function (array $tasks) use ($root): array {
    $tmp = sys_get_temp_dir().'/phase1-financial-race-'.getmypid().'-'.bin2hex(random_bytes(5));
    if (! mkdir($tmp, 0700, true)) {
        throw new RuntimeException('Cannot create isolated local worker barrier.');
    }
    $gate = $tmp.'/go';
    $running = [];
    foreach ($tasks as $i => $task) {
        $ready = $tmp.'/ready-'.$i;
        $process = new Process([PHP_BINARY, $root.'/tests/Support/phase1-financial-worker.php', (string) $task['operation'],
            json_encode($task['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $gate, $ready], $root, null, null, 60);
        $process->start();
        $running[] = [$process, $ready];
    }
    $deadline = microtime(true) + 25;
    foreach ($running as [, $ready]) {
        while (! is_file($ready) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($ready)) {
            throw new RuntimeException('Two independent PostgreSQL workers did not reach the race barrier.');
        }
    }
    file_put_contents($gate, 'go', LOCK_EX);
    $results = [];
    foreach ($running as [$process]) {
        $process->wait();
        $stdout = trim($process->getOutput());
        $results[] = ['exit_code' => $process->getExitCode(), 'result' => $stdout === '' ? null : json_decode($stdout, true)];
    }
    foreach (glob($tmp.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);

    return $results;
};

$sameTask = ['operation' => 'post_supplier_invoice', 'payload' => ['actor_id' => $workers[0]['actor_id'],
    'invoice_id' => $workers[0]['invoice_id'], 'version' => $workers[0]['version']]];
$sameInvoiceRace = $race([$sameTask, $sameTask]);
$sameInvoice = (int) $workers[0]['invoice_id'];
$sameRow = DB::table('rp_supplier_invoices')->find($sameInvoice);
$sameJournalCount = DB::table('accounting_journals')->where('source_key', 'supplier-invoice:'.$sameInvoice)->count();
$sameOpenCount = DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $sameInvoice)->count();
$sameDocCount = DB::table('system_official_documents')->where('document_type', 'supplier_invoice')->where('source_id', (string) $sameInvoice)->count();
if (! $sameRow || $sameRow->status !== 'posted' || ! $sameRow->official_number || $sameJournalCount !== 1 || $sameOpenCount !== 1 || $sameDocCount !== 1) {
    throw new RuntimeException('Concurrent duplicate posting produced missing or duplicate invoice, journal, AP, or official evidence.');
}
$sameOutcomes = array_column(array_column($sameInvoiceRace, 'result'), 'outcome');
if (count($sameOutcomes) !== 2 || count(array_filter($sameOutcomes, fn ($outcome) => in_array($outcome, ['passed', 'rejected'], true))) !== 2) {
    throw new RuntimeException('Concurrent duplicate posting did not produce two observed worker outcomes.');
}

$numberRace = $race(array_map(fn ($fixture) => ['operation' => 'post_supplier_invoice', 'payload' => ['actor_id' => $fixture['actor_id'],
    'invoice_id' => $fixture['invoice_id'], 'version' => $fixture['version']]], [$workers[1], $workers[2]]));
$numberIds = array_map(fn ($fixture) => (int) $fixture['invoice_id'], [$workers[1], $workers[2]]);
$numberRows = DB::table('rp_supplier_invoices')->whereIn('id', $numberIds)->orderBy('id')->get();
$numbers = $numberRows->pluck('official_number')->all();
if ($numberRows->count() !== 2 || $numberRows->contains(fn ($row) => $row->status !== 'posted' || ! $row->official_number)
    || count(array_unique($numbers)) !== 2 || count(array_filter($numberRace, fn ($row) => $row['exit_code'] === 0)) !== 2) {
    throw new RuntimeException('Concurrent distinct invoice issuance failed or reused an official yearly number.');
}

$makerId = (int) $workers[0]['actor_id'];
$branchId = (int) $workers[0]['branch_id'];
$maker = User::query()->findOrFail($makerId);
$advanceMapping = DB::table('accounting_account_mappings')->where('purpose', 'customer_advance')->first();
if (! $advanceMapping) {
    $advanceAccountId = app(AccountingActions::class)->account($makerId, null, 0, [
        'code' => 'PH1-ADV-'.strtoupper(Str::random(10)), 'name' => 'Synthetic customer advance liability',
        'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true,
    ]);
    app(FinancialActivation::class)->saveOptionalMapping($makerId, 'customer_advance', $advanceAccountId);
    $advanceMapping = DB::table('accounting_account_mappings')->where('purpose', 'customer_advance')->first();
}
$advanceAccountRow = $advanceMapping ? DB::table('accounting_accounts')->find($advanceMapping->account_id) : null;
if (! $advanceAccountRow || $advanceAccountRow->kind !== 'liability' || ! $advanceAccountRow->active || ! $advanceAccountRow->postable) {
    throw new RuntimeException('Synthetic optional customer advance mapping is missing or not a usable liability account.');
}
$advanceAccount = (int) $advanceAccountRow->id;
$reviewerId = (int) DB::table('rp_credit_notes')->where('branch_id', $branchId)->whereNotNull('approved_by')->orderByDesc('id')->value('approved_by');
$reviewer = User::query()->findOrFail($reviewerId);
$sourceInvoice = DB::table('rp_supplier_invoices')->where('id', $sameInvoice)->first();
$sourceLine = DB::table('rp_supplier_invoice_lines')->where('invoice_id', $sameInvoice)->first();
$supplierId = (int) $sourceInvoice->supplier_id;
$productId = (int) $sourceLine->product_id;
$product = DB::table('products')->find($productId);
$warehouseId = (int) DB::table('user_warehouse_scopes')->where('user_id', $makerId)->where('branch_id', $branchId)->value('warehouse_id');
$customerInvoiceSource = DB::table('rp_customer_invoices')->where('branch_id', $branchId)->orderByDesc('id')->first();
$customerId = (int) $customerInvoiceSource->customer_id;
$date = now('Africa/Cairo')->toDateString();
if (! $warehouseId || ! $product || ! $customerInvoiceSource) {
    throw new RuntimeException('Committed source actor/product/customer scopes are incomplete.');
}

$makePostedReceipt = static function (string $label, string $quantity) use ($makerId, $reviewerId, $branchId, $supplierId, $productId, $warehouseId, $date): array {
    $orders = app(PurchaseOrders::class);
    $receiving = app(PurchaseReceiving::class);
    $order = $orders->save($makerId, ['creation_key' => (string) Str::uuid(), 'branch_id' => $branchId, 'supplier_id' => $supplierId,
        'order_date' => $date, 'expected_date' => null, 'currency' => 'EGP', 'payment_terms' => 'PHASE1 committed race fixture', 'notes' => $label,
        'tax_mode' => 'exclusive', 'reported_tax' => null, 'lines' => [['product_id' => $productId, 'quantity' => $quantity,
            'unit_price' => '10.00000000', 'discount_amount' => '0.00']]], null, 0);
    $orders->transition($makerId, $order, 1, 'submit');
    $orders->transition($reviewerId, $order, 2, 'approve');
    $orders->issue($reviewerId, $order);
    $orderLineId = (int) DB::table('purchasing_order_lines')->where('order_id', $order)->value('id');
    $receipt = $receiving->save($makerId, ['creation_key' => (string) Str::uuid(), 'order_id' => $order, 'warehouse_id' => $warehouseId,
        'receipt_date' => $date, 'delivery_reference' => $label, 'notes' => 'Committed synthetic Phase 1 worker fixture',
        'lines' => [['order_line_id' => $orderLineId, 'quantity' => $quantity]]], null, 0);
    $receiving->submit($makerId, $receipt, 1);
    app(PostPurchaseReceipt::class)->execute($reviewerId, $receipt, 2);

    return ['order_id' => $order, 'order_line_id' => $orderLineId, 'receipt_id' => $receipt,
        'receipt_line_id' => (int) DB::table('purchasing_receipt_lines')->where('receipt_id', $receipt)->value('id')];
};

// Competing supplier invoice matches share one real posted receipt line. Exactly one may consume it.
$matchReceipt = $makePostedReceipt('PHASE1-MATCH-RACE', '1.00000000');
$m12 = app(ReceivablesPayablesApi::class);
$matchRace = $race(array_map(fn ($label) => ['operation' => 'create_supplier_invoice', 'payload' => ['actor_id' => $makerId, 'data' => [
    'creation_key' => (string) Str::uuid(), 'supplier_id' => $supplierId,
    'supplier_invoice_number' => 'PH1-'.$label.'-'.strtoupper(Str::random(5)), 'invoice_date' => $date,
    'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive', 'lines' => [[
        'order_line_id' => $matchReceipt['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
        'matches' => [['receipt_line_id' => $matchReceipt['receipt_line_id'], 'quantity' => '1.00000000']]]]]]], ['MATCH-A', 'MATCH-B']));
$matchInvoiceIds = array_values(array_filter(array_map(fn ($row) => $row['result']['result']['invoice_id'] ?? null, $matchRace)));
$matchedQty = (string) DB::table('rp_supplier_invoice_matches')->where('receipt_line_id', $matchReceipt['receipt_line_id'])->sum('quantity');
$matchedStatuses = DB::table('rp_supplier_invoices')->whereIn('id', $matchInvoiceIds)->orderBy('id')->pluck('status')->all();
if (bccomp($matchedQty, '1.00000000', 8) !== 0 || count($matchInvoiceIds) !== 1
    || count(array_filter($matchRace, fn ($r) => $r['exit_code'] === 0)) !== 1) {
    throw new RuntimeException('Concurrent supplier invoice matches double-allocated one posted receipt line.');
}
$m12->validateSupplierInvoice($makerId, (int) $matchInvoiceIds[0], 1);
$m12->matchSupplierInvoice($makerId, (int) $matchInvoiceIds[0], 2);
$matchedStatus = DB::table('rp_supplier_invoices')->where('id', $matchInvoiceIds[0])->value('status');
if ($matchedStatus !== 'matched') {
    throw new RuntimeException('Winning concurrent supplier invoice did not complete validation and matching.');
}

// Competing payment allocations target one nearly exhausted AP item; one worker must lose without overpay.
$raceAp = DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $sameInvoice)->first();
$raceCredit = $m12->createCreditNote($makerId, ['creation_key' => (string) Str::uuid(), 'kind' => 'supplier', 'invoice_id' => $sameInvoice,
    'note_date' => $date, 'reason' => 'Synthetic AP balance cap before competing allocations', 'amount' => '9.55']);
$m12->approveCreditNote($reviewerId, $raceCredit);
$m12->postCreditNote($reviewerId, $raceCredit);
$raceAp = DB::table('rp_open_items')->find($raceAp->id);
if ($raceAp->remaining_amount !== '2.99') {
    throw new RuntimeException('Synthetic AP balance did not reach the exact competing allocation boundary.');
}
$cashAccountId = (int) DB::table('treasury_accounts')->where('kind', 'cash')->where('active', true)->value('id');
$treasury = app(TreasuryApi::class);
$paymentTasks = [];
foreach (['ALLOC-A', 'ALLOC-B'] as $reference) {
    $paymentId = $treasury->prepareSupplierPayment($makerId, ['creation_key' => (string) Str::uuid(), 'branch_id' => $branchId,
        'supplier_id' => $supplierId, 'account_id' => $cashAccountId, 'request_date' => $date, 'amount' => '1.50',
        'reference' => 'PHASE1 '.$reference]);
    $treasury->approveSupplierPayment($reviewerId, $paymentId, 1);
    $paymentTasks[] = ['operation' => 'execute_supplier_payment', 'payload' => ['actor_id' => $makerId, 'payment_id' => $paymentId,
        'version' => 2, 'idempotency_key' => (string) Str::uuid(), 'allocations' => [['open_item_id' => $raceAp->id, 'amount' => '1.50']]]];
}
$allocationRace = $race($paymentTasks);
$allocationSuccess = count(array_filter($allocationRace, fn ($row) => $row['exit_code'] === 0));
$allocationFailure = count(array_filter($allocationRace, fn ($row) => $row['exit_code'] !== 0));
$raceApAfter = DB::table('rp_open_items')->find($raceAp->id);
$raceAllocations = DB::table('rp_settlement_allocations')->where('open_item_id', $raceAp->id)->sum('amount');
if ($allocationSuccess !== 1 || $allocationFailure !== 1 || $raceApAfter->remaining_amount !== '1.49' || bccomp((string) $raceAllocations, '1.50', 2) !== 0) {
    throw new RuntimeException('Competing payment allocations over-allocated the remaining AP balance.');
}
$retryPayment = $treasury->prepareSupplierPayment($makerId, ['creation_key' => (string) Str::uuid(), 'branch_id' => $branchId,
    'supplier_id' => $supplierId, 'account_id' => $cashAccountId, 'request_date' => $date, 'amount' => '0.50', 'reference' => 'PHASE1 SAME-KEY RETRY']);
$treasury->approveSupplierPayment($reviewerId, $retryPayment, 1);
$retryTask = ['operation' => 'execute_supplier_payment', 'payload' => ['actor_id' => $makerId, 'payment_id' => $retryPayment,
    'version' => 2, 'idempotency_key' => (string) Str::uuid(), 'allocations' => [['open_item_id' => $raceAp->id, 'amount' => '0.50']]]];
$retryRace = $race([$retryTask, $retryTask]);
$retryMovementIds = DB::table('treasury_movements')->where('payment_request_id', $retryPayment)->pluck('id')->all();
$retryAllocationCount = DB::table('rp_settlement_allocations')->where('movement_id', $retryMovementIds[0] ?? 0)->where('open_item_id', $raceAp->id)->count();
if (count($retryMovementIds) !== 1 || $retryAllocationCount !== 1 || count(array_filter($retryRace, fn ($row) => $row['exit_code'] === 0)) !== 2) {
    throw new RuntimeException('Concurrent same-key supplier payment retry created duplicate movement or allocation.');
}

// Actual approved Sales delivery, raced invoice creation, then one source-linked invoice post.
$unitId = (int) $product->base_unit_id;
$salePrice = DB::table('product_sale_prices')->where('product_id', $productId)->where('unit_id', $unitId)->where('status', 'approved')
    ->where('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))->orderByDesc('starts_on')->first();
$paymentTerms = (string) DB::table('sales_quotation_terms')->where('kind', 'payment')->where('active', true)->orderByDesc('id')->value('body');
$deliveryTerms = (string) DB::table('sales_quotation_terms')->where('kind', 'delivery')->where('active', true)->orderByDesc('id')->value('body');
if (! $salePrice || $paymentTerms === '' || $deliveryTerms === '') {
    throw new RuntimeException('Approved Sales price/terms are missing for concurrent customer billing.');
}
$sales = app(SalesOrderActions::class);
$sale = $sales->save($makerId, ['creation_key' => (string) Str::uuid(), 'branch_id' => $branchId, 'customer_id' => $customerId,
    'order_date' => $date, 'requested_delivery_date' => null, 'discount_mode' => 'percent', 'discount_value' => '0',
    'payment_terms' => $paymentTerms, 'delivery_terms' => $deliveryTerms, 'notes' => 'PHASE1 concurrent customer billing source',
    'lines' => [['product_id' => $productId, 'unit_id' => $unitId, 'quantity' => '1.00000000', 'requested_price' => null,
        'description' => 'Synthetic concurrent invoice quantity', 'discount_mode' => 'percent', 'discount_value' => '0', 'override_reason' => null]]]);
$sales->submit($makerId, (int) $sale->id, 1);
$saleLineId = (int) $sale->lines->first()->id;
app(ApproveSalesOrder::class)->execute($reviewerId, (int) $sale->id, 2, [$saleLineId => $warehouseId]);
$deliveryId = app(SalesDeliveries::class)->create($makerId, (int) $sale->id, ['creation_key' => (string) Str::uuid(),
    'reference' => 'PHASE1-CONCURRENT-BILLING', 'lines' => [['line_id' => $saleLineId, 'quantity' => '1.00000000']]]);
app(SalesDeliveries::class)->confirm($reviewerId, $deliveryId);
$deliveryLineId = (int) DB::table('sales_delivery_instruction_lines')->where('instruction_id', $deliveryId)->value('id');
$customerCreateTasks = [];
foreach (['BILL-A', 'BILL-B'] as $label) {
    $customerCreateTasks[] = ['operation' => 'create_customer_invoice', 'payload' => ['actor_id' => $makerId, 'data' => [
        'creation_key' => (string) Str::uuid(), 'customer_id' => $customerId, 'invoice_date' => $date,
        'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '1.00000000']]]]];
}
$customerCreateRace = $race($customerCreateTasks);
$createdCustomerInvoices = DB::table('rp_customer_invoice_lines')->where('delivery_line_id', $deliveryLineId)->pluck('invoice_id')->unique()->values()->all();
if (count($createdCustomerInvoices) !== 1 || count(array_filter($customerCreateRace, fn ($row) => $row['exit_code'] === 0)) !== 1) {
    throw new RuntimeException('Concurrent customer invoice creation billed more than the eligible delivered quantity.');
}
$concurrentCustomerInvoice = (int) $createdCustomerInvoices[0];
$m12->validateCustomerInvoice($makerId, $concurrentCustomerInvoice, 1);
$customerInvoicePost = $m12->postCustomerInvoice($makerId, $concurrentCustomerInvoice, 2);
$customerInvoiceRow = DB::table('rp_customer_invoices')->find($concurrentCustomerInvoice);
if ($customerInvoiceRow->status !== 'posted' || ! DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $concurrentCustomerInvoice)->exists()) {
    throw new RuntimeException('The winning customer invoice did not post its exact AR item.');
}

// Credit-note creation is serialized against the immutable invoiced amount.
$creditAmount = bcadd(bcdiv($customerInvoiceRow->total_amount, '2', 2), '0.01', 2);
$creditCreateTasks = [];
foreach (['CREDIT-A', 'CREDIT-B'] as $label) {
    $creditCreateTasks[] = ['operation' => 'create_credit_note', 'payload' => ['actor_id' => $makerId, 'data' => [
        'creation_key' => (string) Str::uuid(), 'kind' => 'customer', 'invoice_id' => $concurrentCustomerInvoice,
        'note_date' => $date, 'reason' => 'Concurrent credit cap '.$label, 'amount' => $creditAmount]]];
}
$creditRace = $race($creditCreateTasks);
$creditIds = DB::table('rp_credit_notes')->where('kind', 'customer')->where('original_invoice_id', $concurrentCustomerInvoice)->pluck('id')->all();
if (count($creditIds) !== 1 || count(array_filter($creditRace, fn ($row) => $row['exit_code'] === 0)) !== 1) {
    throw new RuntimeException('Concurrent customer credit-note creation exceeded original invoice value.');
}
$m12->approveCreditNote($reviewerId, (int) $creditIds[0]);
$customerCreditPosted = $m12->postCreditNote($reviewerId, (int) $creditIds[0]);

// Explicitly authorized customer advance collection is mapped to the liability account, not revenue.
$advanceKey = (string) Str::uuid();
$advance = $treasury->executeCustomerCollection($makerId, ['idempotency_key' => $advanceKey, 'branch_id' => $branchId,
    'customer_id' => $customerId, 'account_id' => $cashAccountId, 'date' => $date, 'amount' => '1.00',
    'reference' => 'PHASE1 customer advance', 'allow_advance' => true], []);
$advanceRow = DB::table('rp_customer_advances')->where('movement_id', $advance['movement_id'])->first();
$advanceGl = DB::table('accounting_journal_lines')->where('journal_id', $advance['journal_id'])->where('account_id', $advanceAccount)->first();
if (! $advanceRow || $advanceRow->original_amount !== '1.00' || ! $advanceGl || $advanceGl->credit !== '1.00') {
    throw new RuntimeException('Customer advance was not recorded as a mapped liability with exact source evidence.');
}

$makeApprovedInvoice = static function (string $label) use ($makePostedReceipt, $m12, $makerId, $supplierId, $date): int {
    $receipt = $makePostedReceipt($label, '1.00000000');
    $invoice = $m12->createSupplierInvoice($makerId, ['creation_key' => (string) Str::uuid(), 'supplier_id' => $supplierId,
        'supplier_invoice_number' => 'PH1-'.$label.'-'.strtoupper(Str::random(5)), 'invoice_date' => $date,
        'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive', 'lines' => [[
            'order_line_id' => $receipt['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $receipt['receipt_line_id'], 'quantity' => '1.00000000']]]]]);
    $m12->validateSupplierInvoice($makerId, $invoice, 1);
    $m12->matchSupplierInvoice($makerId, $invoice, 2);
    $m12->approveSupplierInvoice($makerId, $invoice, 3);

    return $invoice;
};

// Failure injection at the AP-open-item insert proves the full journal/document post rolls back.
$failureInvoice = $makeApprovedInvoice('FAILURE-ROLLBACK');
$failureJournalKey = 'supplier-invoice:'.$failureInvoice;
$suffix = strtoupper(Str::random(8));
$functionName = 'phase1_fail_open_item_'.$suffix;
$triggerName = 'phase1_fail_open_item_'.$suffix;
DB::beginTransaction();
$injected = false;
$needFailure = false;
try {
    DB::unprepared("CREATE FUNCTION {$functionName}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'phase1 injected failure'; END; \$\$");
    DB::unprepared("CREATE TRIGGER {$triggerName} BEFORE INSERT ON rp_open_items FOR EACH ROW WHEN (NEW.source_type = 'supplier_invoice') EXECUTE FUNCTION {$functionName}()");
    try {
        $m12->postSupplierInvoice($makerId, $failureInvoice, 4);
    } catch (QueryException $error) {
        $injected = ($error->errorInfo[0] ?? null) === 'P0001' && str_contains($error->getMessage(), 'phase1 injected failure');
    }
    DB::unprepared("DROP TRIGGER {$triggerName} ON rp_open_items");
    DB::unprepared("DROP FUNCTION {$functionName}()");
    $needFailure = DB::table('rp_supplier_invoices')->where('id', $failureInvoice)->where('status', 'approved')->exists()
        && ! DB::table('accounting_journals')->where('source_key', $failureJournalKey)->exists()
        && ! DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $failureInvoice)->exists()
        && ! DB::table('system_official_documents')->where('document_type', 'supplier_invoice')->where('source_id', (string) $failureInvoice)->exists();
} finally {
    if ($connection->transactionLevel() > 0) {
        DB::rollBack();
    }
}
if (! $injected || ! $needFailure) {
    throw new RuntimeException('Injected supplier invoice failure left partial journal, official document, AP item, or status changes.');
}

// Close-period and invoice-post race on the same eligible date. Either posting wins before a valid close,
// or close wins and the invoice remains approved without partial financial evidence.
$closeRaceInvoice = $makeApprovedInvoice('PERIOD-CLOSE-RACE');
$openPeriod = DB::table('accounting_periods')->where('status', 'open')->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
if (! $openPeriod) {
    throw new RuntimeException('Open period missing before close-vs-post race.');
}
$closeRace = $race([
    ['operation' => 'post_supplier_invoice', 'payload' => ['actor_id' => $makerId, 'invoice_id' => $closeRaceInvoice, 'version' => 4]],
    ['operation' => 'close_period', 'payload' => ['actor_id' => $makerId, 'period_id' => (int) $openPeriod->id]],
]);
$closedStatus = DB::table('accounting_periods')->where('id', $openPeriod->id)->value('status');
$closeInvoiceRow = DB::table('rp_supplier_invoices')->find($closeRaceInvoice);
$closeJournal = DB::table('accounting_journals')->where('source_key', 'supplier-invoice:'.$closeRaceInvoice)->first();
$closeOpenItem = DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $closeRaceInvoice)->first();
if ($closedStatus !== 'closed' || ! in_array($closeInvoiceRow->status, ['approved', 'posted'], true)
    || ($closeInvoiceRow->status === 'posted' && (! $closeJournal || ! $closeOpenItem))
    || ($closeInvoiceRow->status === 'approved' && ($closeJournal || $closeOpenItem))) {
    throw new RuntimeException('Period close/post race left a partial supplier financial posting.');
}

$evidence = ['database' => $actual->db, 'host' => '127.0.0.1', 'port' => (int) $actual->port,
    'committed_synthetic_fixture_ids' => ['actors' => array_values(array_unique(array_column($workers, 'actor_id'))),
        'supplier_invoice_ids' => array_column($workers, 'invoice_id'), 'receipt_ids' => array_column($workers, 'receipt_id'),
        'branch_ids' => array_values(array_unique(array_column($workers, 'branch_id')))],
    'same_invoice_two_worker_race' => ['worker_results' => $sameInvoiceRace, 'posted_status' => $sameRow->status,
        'journal_count' => $sameJournalCount, 'ap_open_item_count' => $sameOpenCount, 'official_pdf_count' => $sameDocCount],
    'concurrent_official_number_contention' => ['worker_results' => $numberRace, 'invoice_ids' => $numberIds, 'official_numbers' => $numbers],
    'concurrent_supplier_matching' => ['worker_results' => $matchRace, 'invoice_ids' => $matchInvoiceIds,
        'posted_receipt_id' => $matchReceipt['receipt_id'], 'allocated_quantity' => $matchedQty, 'statuses' => $matchedStatuses],
    'competing_settlement_allocations' => ['worker_results' => $allocationRace, 'open_item_id' => (int) $raceAp->id,
        'successes' => $allocationSuccess, 'rejections' => $allocationFailure, 'remaining_after_race' => $raceApAfter->remaining_amount],
    'concurrent_same_payment_retry' => ['worker_results' => $retryRace, 'payment_request_id' => $retryPayment,
        'movement_ids' => $retryMovementIds, 'allocation_count' => $retryAllocationCount],
    'concurrent_customer_invoice_creation' => ['worker_results' => $customerCreateRace, 'sales_order_id' => (int) $sale->id,
        'delivery_id' => $deliveryId, 'invoice_id' => $concurrentCustomerInvoice, 'posted_invoice' => $customerInvoicePost],
    'concurrent_credit_overallocation' => ['worker_results' => $creditRace, 'credit_note_ids' => $creditIds,
        'credit_amount_each' => $creditAmount, 'posted_winner' => $customerCreditPosted],
    'customer_advance' => ['movement_id' => (int) $advance['movement_id'], 'amount' => $advanceRow->original_amount,
        'liability_account_id' => $advanceAccount, 'journal_credit' => $advanceGl->credit],
    'injected_financial_rollback' => ['invoice_id' => $failureInvoice, 'raised_p0001' => $injected, 'no_partial_posting' => $needFailure],
    'period_close_vs_invoice_post' => ['worker_results' => $closeRace, 'period_id' => (int) $openPeriod->id,
        'invoice_id' => $closeRaceInvoice, 'period_status' => $closedStatus, 'invoice_status' => $closeInvoiceRow->status,
        'journal_id' => $closeJournal?->id, 'open_item_id' => $closeOpenItem?->id],
    'all_pass' => true];
$path = $root.'/docs/qa/evidence/phase1-financial-concurrency.json';
if (! is_dir(dirname($path))) {
    mkdir(dirname($path), 0770, true);
}
file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
