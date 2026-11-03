<?php

// Two-process races against the isolated post-restore cluster. Creates all rows through owner actions.
use App\Application\PostPurchaseReceipt;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_DATABASE=erp_elec_new_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
if (getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '55433') {
    throw new RuntimeException('ABORT: concurrency harness requires isolated restore port 55433.');
}
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$storageRoot = getenv('PHASE0_STORAGE_ROOT');
if (! is_string($storageRoot) || $storageRoot === '') {
    throw new RuntimeException('PHASE0_STORAGE_ROOT is required.');
}
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storageRoot);
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$actualConnection = DB::selectOne('select current_database() as db, host(inet_server_addr()) as address, inet_server_port() as port');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'erp_elec_new_test'
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.host') !== '127.0.0.1'
    || (string) config('database.connections.pgsql.port') !== '55433'
    || config('database.connections.pgsql.database') !== 'erp_elec_new_test'
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)
    || $actualConnection->db !== 'erp_elec_new_test'
    || $actualConnection->address !== '127.0.0.1'
    || (string) $actualConnection->port !== '55433') {
    throw new RuntimeException('ABORT: wrong database.');
}
$need = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
$maker = 1;
$reviewer = 2;
$branch = 1;
$warehouse = 1;
$supplier = 1;
$product = DB::table('products')->where('active', true)->orderBy('id')->first();
$need($product !== null && DB::table('suppliers')->where('id', $supplier)->where('status', 'active')->exists(), 'Synthetic active supplier/product missing.');
$need(DB::table('user_branch_scopes')->where('user_id', $maker)->where('branch_id', $branch)->exists()
    && DB::table('user_warehouse_scopes')->where('user_id', $reviewer)->where('warehouse_id', $warehouse)->exists(), 'Synthetic actor scope missing.');

$makeOrder = static function (string $label, string $quantity) use ($maker, $reviewer, $branch, $supplier, $product): array {
    $orders = app(PurchaseOrders::class);
    $id = $orders->save($maker, ['creation_key' => (string) Str::uuid(), 'branch_id' => $branch, 'supplier_id' => $supplier,
        'order_date' => '2026-10-27', 'expected_date' => null, 'currency' => 'EGP', 'payment_terms' => 'synthetic concurrency fixture',
        'notes' => $label, 'tax_mode' => 'exclusive', 'reported_tax' => null,
        'lines' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => '1.00000000', 'discount_amount' => '0.00000000']]], null, 0);
    $orders->transition($maker, $id, 1, 'submit');
    $orders->transition($reviewer, $id, 2, 'approve');

    return ['id' => $id, 'line_id' => (int) DB::table('purchasing_order_lines')->where('order_id', $id)->value('id')];
};
$po = $makeOrder('Phase0 same-receipt and cap race fixture', '3.00000000');
$orders = app(PurchaseOrders::class);
$orders->issue($reviewer, $po['id']);
$numberingA = $makeOrder('Phase0 concurrent official number A', '1.00000000');
$numberingB = $makeOrder('Phase0 concurrent official number B', '1.00000000');

$race = static function (string $name, array $work) use ($root): array {
    $tmp = sys_get_temp_dir().'/phase0-concurrency-'.getmypid().'-'.bin2hex(random_bytes(5));
    if (! mkdir($tmp, 0700, true)) {
        throw new RuntimeException('Cannot create local race barrier.');
    }
    $gate = $tmp.'/go';
    $workers = [];
    foreach ($work as $index => $task) {
        $ready = $tmp.'/ready-'.$index;
        $args = array_map('strval', $task);
        $process = new Process([PHP_BINARY, $root.'/tests/Support/phase0-concurrency-worker.php', ...$args, $gate, $ready], $root, null, null, 45);
        $process->start();
        $workers[] = [$process, $ready];
    }
    $deadline = microtime(true) + 20;
    foreach ($workers as [, $ready]) {
        while (! is_file($ready) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($ready)) {
            throw new RuntimeException($name.' workers did not reach barrier.');
        }
    }
    file_put_contents($gate, 'go');
    $results = [];
    foreach ($workers as [$process]) {
        $process->wait();
        $results[] = ['exit_code' => $process->getExitCode(), 'stdout' => trim($process->getOutput()), 'stderr' => trim($process->getErrorOutput())];
    }
    foreach (glob($tmp.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);

    return $results;
};

$receiving = app(PurchaseReceiving::class);
$sameReceipt = $receiving->save($maker, ['creation_key' => (string) Str::uuid(), 'order_id' => $po['id'], 'warehouse_id' => $warehouse,
    'receipt_date' => '2026-10-27', 'delivery_reference' => 'SAME-RECEIPT-RACE', 'notes' => 'synthetic two-process race',
    'lines' => [['order_line_id' => $po['line_id'], 'quantity' => '1.00000000']]], null, 0);
$receiving->submit($maker, $sameReceipt, 1);
$sameRace = $race('same receipt twice', [['receipt', $sameReceipt, $reviewer], ['receipt', $sameReceipt, $reviewer]]);
$sameRows = DB::table('inventory_movements')->where('source_module', 'Purchasing')->where('operation', 'purchase_receipt')->where('source_id', (string) $sameReceipt)->count();
$sameStatus = DB::table('purchasing_receipts')->where('id', $sameReceipt)->value('status');
$sameLine = (int) DB::table('purchasing_receipt_lines')->where('receipt_id', $sameReceipt)->value('id');
$sameLayer = DB::table('inventory_fifo_layers')->where('source_key', 'purchase-receipt:'.$sameReceipt.':'.$sameLine)->count();
$samePosting = DB::table('purchasing_receipt_postings')->where('receipt_id', $sameReceipt)->where('line_id', $sameLine)->count();
$sameReceiptRow = DB::table('purchasing_receipts')->find($sameReceipt);
$sameJournal = DB::table('accounting_journals')->where('id', $sameReceiptRow->journal_id)->count();
$need(count(array_filter($sameRace, fn ($r) => $r['exit_code'] === 0)) === 2 && $sameRows === 1 && $sameLayer === 1 && $samePosting === 1 && $sameJournal === 1 && $sameStatus === 'posted', 'Same-receipt race did not keep stock, FIFO, posting and GRNI journal unique.');

$capRace = $race('two receipts quantity cap', [
    ['create_receipt', $po['id'], $maker, $po['line_id'], $warehouse, '2.00000000', (string) Str::uuid(), 'CAP-RACE-A'],
    ['create_receipt', $po['id'], $maker, $po['line_id'], $warehouse, '2.00000000', (string) Str::uuid(), 'CAP-RACE-B'],
]);
$capReceipts = DB::table('purchasing_receipts')->where('order_id', $po['id'])->where('id', '<>', $sameReceipt)->orderBy('id')->get(['id', 'status']);
$capRows = $capReceipts->pluck('status')->all();
$capReceiptId = (int) ($capReceipts->first()->id ?? 0);
$need(count(array_filter($capRace, fn ($r) => $r['exit_code'] === 0)) === 1 && count(array_filter($capRace, fn ($r) => $r['exit_code'] !== 0)) === 1
    && $capReceipts->count() === 1 && $capRows === ['integration_pending'],
    'Concurrent real receipt saves exceeded the remaining order quantity or accepted an invalid retry.');
app(PostPurchaseReceipt::class)->execute($reviewer, $capReceiptId, 2);
$capMovements = DB::table('inventory_movements')->where('source_module', 'Purchasing')->where('operation', 'purchase_receipt')
    ->where('source_id', (string) $capReceiptId)->count();
$need($capMovements === 1 && DB::table('purchasing_receipts')->where('id', $capReceiptId)->value('status') === 'posted', 'Accepted cap-race receipt did not post once through the real action.');

$postPo = $makeOrder('Phase0 concurrent valid half-quantity receipts', '2.00000000');
$orders->issue($reviewer, $postPo['id']);
$halfReceipts = [];
foreach (['POST-HALF-A', 'POST-HALF-B'] as $label) {
    $rid = $receiving->save($maker, ['creation_key' => (string) Str::uuid(), 'order_id' => $postPo['id'], 'warehouse_id' => $warehouse,
        'receipt_date' => '2026-10-27', 'delivery_reference' => $label, 'notes' => 'synthetic concurrently posted valid split receipt',
        'lines' => [['order_line_id' => $postPo['line_id'], 'quantity' => '1.00000000']]], null, 0);
    $receiving->submit($maker, $rid, 1);
    $halfReceipts[] = $rid;
}
$onHandBefore = (string) (DB::table('inventory_balances')->where('warehouse_id', $warehouse)->where('product_id', $product->id)->value('on_hand') ?? '0.00000000');
$postingRace = $race('two valid submitted receipts posting concurrently', [
    ['receipt', $halfReceipts[0], $reviewer], ['receipt', $halfReceipts[1], $reviewer],
]);
$postEvidence = [];
foreach ($halfReceipts as $rid) {
    $receipt = DB::table('purchasing_receipts')->find($rid);
    $receiptLine = (int) DB::table('purchasing_receipt_lines')->where('receipt_id', $rid)->value('id');
    $movement = DB::table('inventory_movements')->where('source_module', 'Purchasing')->where('operation', 'purchase_receipt')->where('source_id', (string) $rid)->first();
    $layer = DB::table('inventory_fifo_layers')->where('source_key', 'purchase-receipt:'.$rid.':'.$receiptLine)->first();
    $posting = DB::table('purchasing_receipt_postings')->where('receipt_id', $rid)->where('line_id', $receiptLine)->first();
    $journal = $receipt ? DB::table('accounting_journals')->find($receipt->journal_id) : null;
    $lines = $journal ? DB::table('accounting_journal_lines')->where('journal_id', $journal->id)->get() : collect();
    $debit = '0.00';
    $credit = '0.00';
    foreach ($lines as $line) {
        $debit = bcadd($debit, $line->debit, 2);
        $credit = bcadd($credit, $line->credit, 2);
    }
    $need($receipt && $receipt->status === 'posted' && $movement && $layer && $posting && $journal && $journal->status === 'posted'
        && $layer->movement_id === $movement->id && $posting->layer_id === $layer->id && $movement->source_line === (string) $receiptLine
        && $movement->quantity === '1.00000000' && $layer->original_quantity === '1.00000000'
        && $layer->original_value === $receipt->capitalized_amount && $debit === $credit && $debit === $receipt->capitalized_amount,
        'Concurrent valid receipt did not reconcile movement, FIFO layer, GRNI journal and source value.');
    $need(DB::table('inventory_movements')->where('source_module', 'Purchasing')->where('operation', 'purchase_receipt')->where('source_id', (string) $rid)->count() === 1
        && DB::table('inventory_fifo_layers')->where('source_key', 'purchase-receipt:'.$rid.':'.$receiptLine)->count() === 1
        && DB::table('purchasing_receipt_postings')->where('receipt_id', $rid)->where('line_id', $receiptLine)->count() === 1,
        'Concurrent valid receipt created duplicate stock/FIFO posting rows.');
    $postEvidence[] = ['receipt_id' => $rid, 'capitalized_amount' => $receipt->capitalized_amount, 'movement_id' => $movement->id, 'fifo_layer_id' => $layer->id, 'grni_journal_id' => $journal->id, 'debit' => $debit, 'credit' => $credit];
}
$postedQuantity = (string) DB::table('purchasing_receipt_lines')->join('purchasing_receipts', 'purchasing_receipts.id', '=', 'purchasing_receipt_lines.receipt_id')
    ->where('purchasing_receipts.order_id', $postPo['id'])->where('purchasing_receipts.status', 'posted')->sum('purchasing_receipt_lines.quantity');
$onHandAfter = (string) (DB::table('inventory_balances')->where('warehouse_id', $warehouse)->where('product_id', $product->id)->value('on_hand') ?? '0.00000000');
$need(count(array_filter($postingRace, fn ($r) => $r['exit_code'] === 0)) === 2 && bccomp($postedQuantity, '2.00000000', 8) === 0,
    'Concurrent valid half receipts did not complete with full valid receipt quantity.');
$need(bccomp(bcsub($onHandAfter, $onHandBefore, 8), '2.00000000', 8) === 0, 'Concurrent receipt stock delta was not exactly 2.');
$numberRace = $race('official number contention', [['issue', $numberingA['id'], $reviewer], ['issue', $numberingB['id'], $reviewer]]);
$numberRows = DB::table('purchasing_order_issues')->whereIn('order_id', [$numberingA['id'], $numberingB['id']])->orderBy('order_id')->get(['official_number', 'document_id']);
$numbers = $numberRows->pluck('official_number')->all();
$docs = $numberRows->pluck('document_id')->all();
$need(count(array_filter($numberRace, fn ($r) => $r['exit_code'] === 0)) === 2 && count($numbers) === 2 && count(array_unique($numbers)) === 2
    && count(array_unique($docs)) === 2, 'Concurrent official-document number issuance did not produce two distinct persisted documents.');

$result = ['database' => 'erp_elec_new_test', 'postgres_port' => 55433,
    'same_receipt_twice' => ['workers' => $sameRace, 'receipt_status' => $sameStatus, 'source_movements' => $sameRows, 'fifo_layers' => $sameLayer, 'receipt_postings' => $samePosting, 'grni_journals' => $sameJournal, 'passed' => true],
    'concurrent_valid_receipt_posting' => ['workers' => $postingRace, 'receipt_ids' => $halfReceipts, 'receipts' => $postEvidence, 'posted_quantity' => $postedQuantity, 'stock_delta' => bcsub($onHandAfter, $onHandBefore, 8), 'passed' => true],
    'remaining_quantity_cap' => ['workers' => $capRace, 'receipt_statuses' => $capRows, 'accepted_receipt_id' => $capReceiptId, 'accepted_receipt_posted_movement_count' => $capMovements, 'passed' => true],
    'official_number_contention' => ['workers' => $numberRace, 'numbers' => $numbers, 'distinct_documents' => count(array_unique($docs)), 'passed' => true],
    'all_pass' => true];
$path = $root.'/docs/qa/evidence/phase0-concurrency.json';
file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
