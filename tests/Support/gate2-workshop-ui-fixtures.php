<?php

declare(strict_types=1);

// Isolated M09 browser acceptance. Reuses only the exact private finance UI PG17 target and nonce branch.
use App\Models\User;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = realpath(dirname(__DIR__, 2));
$privateRoot = $root.'/.tmp/master-remediation-20261101/ui-private';
$financeRoot = $privateRoot.'/finance';
$bootstrapPath = $financeRoot.'/gate2-finance-ui-bootstrap.json';
$basePath = $financeRoot.'/gate2-finance-ui-fixture.json';
$markerPath = $privateRoot.'/.codex-ui-owned.txt';
$marker = 'master-remediation-20261101 Gate2 UI private PostgreSQL marker';
$mode = (string) ($argv[1] ?? '');
if (! preg_match('/^--(prepare|verify)=([a-f0-9]{32})(?:-([1-9][0-9]*))?$/D', $mode, $matches)) {
    throw new RuntimeException('Usage: --prepare=<32 lowercase hex> or --verify=<32 lowercase hex>-<job id>.');
}
$operation = $matches[1]; $runNonce = $matches[2]; $jobId = isset($matches[3]) ? (int) $matches[3] : null;
if ($operation === 'verify' && ! $jobId) throw new RuntimeException('Verification requires the browser-created job id.');
$nonce = (string) (getenv('E2E_FINANCE_DB_NONCE') ?: '');
$database = (string) (getenv('DB_DATABASE') ?: '');
$receipt = is_file($bootstrapPath) ? json_decode((string) file_get_contents($bootstrapPath), true, 512, JSON_THROW_ON_ERROR) : null;
$base = is_file($basePath) ? json_decode((string) file_get_contents($basePath), true, 512, JSON_THROW_ON_ERROR) : null;
$resolvedRoot = realpath($root); $resolvedPrivate = realpath($privateRoot); $cluster = realpath($root.'/.tmp/master-remediation-20261101/ui-pgdata');
$pidFile = $cluster ? $cluster.'/postmaster.pid' : '';
if (! is_array($receipt) || ! is_array($base) || $resolvedPrivate !== $resolvedRoot.DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.'master-remediation-20261101'.DIRECTORY_SEPARATOR.'ui-private'
    || ! is_file($markerPath) || trim((string) file_get_contents($markerPath)) !== $marker
    || ! preg_match('/^[a-f0-9]{64}$/D', $nonce) || ! hash_equals((string) $receipt['ownership_nonce'], $nonce)
    || $database !== 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32) || $database !== ($receipt['database'] ?? null)
    || (int) ($receipt['port'] ?? 0) !== 49686 || realpath((string) ($receipt['data_directory'] ?? '')) !== $cluster
    || ! is_file($pidFile) || trim((string) file($pidFile, FILE_IGNORE_NEW_LINES)[3] ?? '') !== '49686'
    || ($base['ownership_nonce'] ?? null) !== $nonce || ($base['database'] ?? null) !== $database) {
    throw new RuntimeException('M09 acceptance refuses any target except the existing nonce-owned private PG17 cluster on port 49686.');
}
$runPath = $financeRoot.'/gate2-finance-ui-run-'.$runNonce.'.json';
$workshopPath = $financeRoot.'/gate2-workshop-ui-run-'.$runNonce.'.json';
$run = is_file($runPath) ? json_decode((string) file_get_contents($runPath), true, 512, JSON_THROW_ON_ERROR) : null;
if (! is_array($run) || ($run['state'] ?? null) !== 'READY' || ($run['database'] ?? null) !== $database
    || ($run['ownership_nonce'] ?? null) !== $nonce || (int) ($run['port'] ?? 0) !== 49686) {
    throw new RuntimeException('Run the existing guarded finance fixture first; its READY receipt must bind this nonce branch.');
}
$storage = $financeRoot.'/storage';
foreach ([$financeRoot.'/cache', $financeRoot.'/views', $storage.'/framework/sessions', $storage.'/framework/cache', $storage.'/framework/views', $storage.'/logs', $storage.'/app/private'] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) throw new RuntimeException('Cannot create private M09 runtime directory.');
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => $receipt['app_key'], 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '49686', 'DB_DATABASE' => $database, 'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '', 'E2E_DB_PORT' => '49686',
    'E2E_FINANCE_DB_NONCE' => $nonce, 'APP_CONFIG_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/config.php',
    'APP_ROUTES_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/routes.php', 'APP_EVENTS_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/events.php',
    'APP_SERVICES_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/services.php', 'APP_PACKAGES_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/packages.php',
    'APP_VIEW_COMPILED_PATH' => $financeRoot.'/views', 'VIEW_COMPILED_PATH' => $financeRoot.'/views', 'LARAVEL_STORAGE_PATH' => $storage,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php'; $app->loadEnvironmentFrom('.env.testing'); $app->useStoragePath($storage); $app->make(Kernel::class)->bootstrap();
config(['app.key' => $receipt['app_key'], 'database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 49686,
    'database.connections.pgsql.database' => $database, 'database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => '',
    'database.connections.pgsql.url' => null, 'filesystems.disks.local.root' => $storage.'/app/private', 'filesystems.disks.local.visibility' => 'private',
    'filesystems.disks.local.throw' => true, 'cache.default' => 'array', 'session.driver' => 'file']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
 current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
 (select r.rolname from pg_database d join pg_roles r on r.oid=d.datdba where d.datname=current_database()) as database_owner");
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, ['APP_ENV'=>'testing','DB_HOST'=>'127.0.0.1','DB_PORT'=>'49686','E2E_DB_PORT'=>'49686',
    'DB_DATABASE'=>$database,'DB_USERNAME'=>'postgres','E2E_FINANCE_DB_NONCE'=>$nonce]);
$nonceRow = DB::selectOne('select nonce from codex_test_guard.ownership_receipts where database = ?', [$database]);
Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, $identity, $nonceRow);
if (($identity->database_owner ?? null) !== 'postgres' || (int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000
    || realpath((string) $identity->data_directory) !== $cluster) throw new RuntimeException('Live M09 connection differs from the guarded PG17 target.');

$tableSnapshot = static function (int $branchId): array {
    $tables = ['companies', 'branches', 'workshops', 'workshop_intakes', 'workshop_jobs', 'workshop_estimates', 'workshop_quotes', 'workshop_quote_approvals',
        'workshop_estimate_approvals', 'workshop_work_records', 'workshop_labor_entries', 'workshop_tests', 'workshop_verifications', 'workshop_deliveries',
        'workshop_part_requirements', 'workshop_part_reservations', 'workshop_part_issues', 'workshop_part_returns', 'workshop_part_usage',
        'service_contracts', 'service_contract_billing_sources', 'service_contract_billing_allocations', 'service_contract_recognitions',
        'rp_customer_invoices', 'rp_open_items', 'rp_settlement_allocations', 'treasury_movements', 'accounting_journals', 'accounting_journal_lines',
        'inventory_warehouses', 'inventory_balances', 'inventory_documents', 'inventory_movements', 'inventory_reservations', 'inventory_reservation_events',
        'inventory_fifo_layers', 'inventory_fifo_allocations', 'inventory_fifo_corrections', 'inventory_movement_journals', 'inventory_corrections',
        'purchasing_orders', 'purchasing_order_lines', 'purchasing_order_reviews', 'purchasing_receipts', 'purchasing_receipt_lines'];
    $snapshot = [];
    foreach ($tables as $table) {
        if (! DB::getSchemaBuilder()->hasTable($table)) continue;
        $hasBranch = DB::getSchemaBuilder()->hasColumn($table, 'branch_id');
        $orderColumn = ['workshop_quote_approvals' => 'estimate_id', 'workshop_verifications' => 'job_id', 'service_contract_billing_allocations' => 'source_id',
            'workshop_part_reservations' => 'requirement_id', 'inventory_fifo_corrections' => 'movement_id', 'inventory_movement_journals' => 'movement_id'][$table] ?? 'id';
        if (! DB::getSchemaBuilder()->hasColumn($table, $orderColumn)) throw new RuntimeException('Snapshot ordering key is missing for '.$table.'.');
        $query = DB::table($table);
        if ($table === 'companies') $query->where('id', 1);
        elseif ($table === 'branches') $query->where('id', '<>', $branchId);
        elseif ($hasBranch) $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', '<>', $branchId));
        elseif (in_array($table, ['workshop_estimates','workshop_quotes','workshop_work_records','workshop_labor_entries','workshop_tests','workshop_verifications','workshop_deliveries','workshop_part_requirements'], true)) {
            $query->whereIn('job_id', DB::table('workshop_jobs')->where('branch_id', '<>', $branchId)->select('id'));
        } elseif (in_array($table, ['workshop_quote_approvals','workshop_estimate_approvals'], true)) {
            $query->whereIn('estimate_id', DB::table('workshop_estimates')->whereIn('job_id', DB::table('workshop_jobs')->where('branch_id', '<>', $branchId)->select('id'))->select('id'));
        } elseif ($table === 'service_contract_billing_sources') {
            $query->whereIn('contract_id', DB::table('service_contracts')->where('branch_id', '<>', $branchId)->select('id'));
        } elseif ($table === 'service_contract_billing_allocations') {
            $query->whereIn('source_id', DB::table('service_contract_billing_sources')->whereIn('contract_id', DB::table('service_contracts')->where('branch_id', '<>', $branchId)->select('id'))->select('id'));
        } elseif ($table === 'service_contract_recognitions') {
            $query->whereIn('contract_id', DB::table('service_contracts')->where('branch_id', '<>', $branchId)->select('id'));
        } elseif ($table === 'rp_settlement_allocations') {
            $query->whereIn('movement_id', DB::table('treasury_movements')->where('branch_id', '<>', $branchId)->select('id'));
        } elseif (in_array($table, ['workshop_part_reservations','workshop_part_issues'], true)) {
            $query->whereIn('requirement_id', DB::table('workshop_part_requirements')->whereIn('job_id', DB::table('workshop_jobs')->where('branch_id', '<>', $branchId)->select('id'))->select('id'));
        } elseif ($table === 'workshop_part_returns') {
            $query->whereIn('issue_id', DB::table('workshop_part_issues')->whereIn('requirement_id', DB::table('workshop_part_requirements')->whereIn('job_id', DB::table('workshop_jobs')->where('branch_id', '<>', $branchId)->select('id'))->select('id'))->select('id'));
        } elseif ($table === 'workshop_part_usage') {
            $query->whereIn('requirement_id', DB::table('workshop_part_requirements')->whereIn('job_id', DB::table('workshop_jobs')->where('branch_id', '<>', $branchId)->select('id'))->select('id'));
        } elseif (in_array($table, ['inventory_balances','inventory_documents','inventory_movements','inventory_reservations','inventory_fifo_layers'], true)) {
            $query->whereIn('warehouse_id', DB::table('inventory_warehouses')->where('branch_id', '<>', $branchId)->select('id'));
        } elseif ($table === 'inventory_reservation_events') {
            $query->whereIn('reservation_id', DB::table('inventory_reservations')->whereIn('warehouse_id', DB::table('inventory_warehouses')->where('branch_id', '<>', $branchId)->select('id'))->select('id'));
        } elseif ($table === 'inventory_fifo_allocations') {
            $query->whereIn('movement_id', DB::table('inventory_movements')->whereIn('warehouse_id', DB::table('inventory_warehouses')->where('branch_id', '<>', $branchId)->select('id'))->select('id'));
        } elseif (in_array($table, ['inventory_fifo_corrections','inventory_movement_journals'], true)) {
            $query->whereIn('movement_id', DB::table('inventory_movements')->whereIn('warehouse_id', DB::table('inventory_warehouses')->where('branch_id', '<>', $branchId)->select('id'))->select('id'));
        } elseif ($table === 'inventory_corrections') {
            $query->whereIn('movement_id', DB::table('inventory_movements')->whereIn('warehouse_id', DB::table('inventory_warehouses')->where('branch_id', '<>', $branchId)->select('id'))->select('id'));
        } elseif ($table === 'accounting_journal_lines') {
            $query->whereIn('journal_id', DB::table('accounting_journals')->where('branch_id', '<>', $branchId)->select('id'));
        } elseif (in_array($table, ['purchasing_order_lines','purchasing_order_reviews'], true)) {
            $query->whereIn('order_id', DB::table('purchasing_orders')->where('branch_id', '<>', $branchId)->select('id'));
        } elseif ($table === 'purchasing_receipts') {
            $query->where('branch_id', '<>', $branchId);
        } elseif ($table === 'purchasing_receipt_lines') {
            $query->whereIn('receipt_id', DB::table('purchasing_receipts')->where('branch_id', '<>', $branchId)->select('id'));
        } elseif (! $hasBranch) continue;
        $rows = $query->orderBy($orderColumn)->get()->map(fn ($row) => (array) $row)->all();
        $snapshot[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }
    return $snapshot;
};
$writePrivate = static function (string $path, array $data) use ($financeRoot): void {
    if (realpath(dirname($path)) !== realpath($financeRoot)) throw new RuntimeException('M09 fixture receipt must remain beneath private finance runtime.');
    $handle = fopen($path, 'x'); if (! $handle) throw new RuntimeException('M09 private run receipt exists; preserve it and use another nonce.');
    try { fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL); fflush($handle); }
    finally { fclose($handle); }
};

if ($operation === 'verify') {
    $fixture = json_decode((string) file_get_contents($workshopPath), true, 512, JSON_THROW_ON_ERROR);
    if (($fixture['state'] ?? null) !== 'PREPARED' || ($fixture['database'] ?? null) !== $database
        || ($fixture['ownership_nonce'] ?? null) !== $nonce || (int) ($fixture['branch_id'] ?? 0) !== (int) $run['branch_id']) throw new RuntimeException('M09 result does not bind this fixture receipt.');
    $job = DB::table('workshop_jobs')->where('id', $jobId)->where('branch_id', $run['branch_id'])->first();
    $intake = $job ? DB::table('workshop_intakes')->where('id', $job->intake_id)->first() : null;
    $invoice = DB::table('rp_customer_invoices')->where('workshop_job_id', $jobId)->first();
    $estimate = DB::table('workshop_estimates')->where('job_id', $jobId)->orderByDesc('revision')->first();
    $quote = $estimate ? DB::table('workshop_quotes')->where('estimate_id', $estimate->id)->first() : null;
    $priceApproval = $estimate ? DB::table('workshop_quote_approvals')->where('estimate_id', $estimate->id)->first() : null;
    $customerDecision = $estimate ? DB::table('workshop_estimate_approvals')->where('estimate_id', $estimate->id)->first() : null;
    $invoiceLine = $invoice ? DB::table('rp_customer_invoice_lines')->where('invoice_id', $invoice->id)->first() : null;
    $open = $invoice ? DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoice->id)->first() : null;
    $movement = DB::table('treasury_movements')->where('branch_id', $run['branch_id'])->where('kind', 'customer_collection')
        ->where('reference', $fixture['collection_reference'])->first();
    $alloc = $movement && $open ? DB::table('rp_settlement_allocations')->where('movement_id', $movement->id)->where('open_item_id', $open->id)->first() : null;
    $parts = $fixture['parts'] ?? [];
    $requirement = DB::table('workshop_part_requirements')->where('job_id', $jobId)->where('product_id', $parts['product_id'] ?? 0)->where('warehouse_id', $parts['warehouse_id'] ?? 0)->first();
    $partIssue = $requirement ? DB::table('workshop_part_issues')->where('requirement_id', $requirement->id)->first() : null;
    $partUsage = $requirement ? DB::table('workshop_part_usage')->where('requirement_id', $requirement->id)->first() : null;
    $partReturn = $partIssue ? DB::table('workshop_part_returns')->where('issue_id', $partIssue->id)->first() : null;
    $receiptLayer = isset($parts['receipt_movement_id']) ? DB::table('inventory_fifo_layers')->where('movement_id', $parts['receipt_movement_id'])->first() : null;
    $issueMovement = $partIssue ? DB::table('inventory_movements')->where('id', $partIssue->movement_id)->first() : null;
    $issueAllocation = $partIssue ? DB::table('inventory_fifo_allocations')->where('movement_id', $partIssue->movement_id)->first() : null;
    $returnMovement = $partReturn ? DB::table('inventory_movements')->where('id', $partReturn->movement_id)->first() : null;
    $returnFifoCorrection = $returnMovement ? DB::table('inventory_fifo_corrections')->where('movement_id', $returnMovement->id)->first() : null;
    $partBalance = $requirement ? DB::table('inventory_balances')->where('warehouse_id', $requirement->warehouse_id)->where('product_id', $requirement->product_id)->first() : null;
    $remainingFifoValue = $requirement ? DB::table('inventory_fifo_layers')->where('warehouse_id', $requirement->warehouse_id)->where('product_id', $requirement->product_id)->sum('remaining_value') : null;
    $afterSnapshot = $tableSnapshot((int) $run['branch_id']);
    if (! $job || $job->status !== 'delivered' || ! $intake || $intake->status !== 'delivered' || ! $estimate || bccomp((string) $estimate->billable_amount, '100.00', 2) !== 0
        || ! $quote || ! $priceApproval || ! $customerDecision || ! $customerDecision->customer_accepted
        || ! $invoice || $invoice->status !== 'partially_collected' || $invoice->journal_id === null || ! $invoiceLine
        || (int) $invoiceLine->workshop_approval_id !== (int) $customerDecision->id || bccomp((string) $invoice->net_amount, '100.00', 2) !== 0
        || ! $open || bccomp((string) $open->original_amount, (string) $invoice->total_amount, 2) !== 0
        || bccomp((string) $open->remaining_amount, bcsub((string) $invoice->total_amount, (string) $fixture['collection_amount'], 2), 2) !== 0
        || ! $movement || (int) $movement->account_id !== (int) $fixture['cash_account_id'] || $movement->journal_id === null
        || bccomp((string) $movement->amount, (string) $fixture['collection_amount'], 2) !== 0
        || ! $alloc || bccomp((string) $alloc->amount, (string) $fixture['collection_amount'], 2) !== 0
        || DB::table('workshop_work_records')->where('job_id', $jobId)->count() !== 1 || DB::table('workshop_tests')->where('job_id', $jobId)->where('outcome', 'passed')->count() < 1
        || ! DB::table('workshop_verifications')->where('job_id', $jobId)->exists() || ! DB::table('workshop_deliveries')->where('job_id', $jobId)->exists()
        || ! $requirement || bccomp((string) $requirement->quantity, '2.00000000', 8) !== 0
        || ! $partIssue || bccomp((string) $partIssue->quantity, '2.00000000', 8) !== 0 || $partIssue->journal_id === null || $partIssue->movement_id === null
        || ! $partUsage || bccomp((string) $partUsage->quantity, '1.00000000', 8) !== 0
        || ! DB::table('workshop_work_records')->where('id', $partUsage->work_record_id ?? 0)->where('job_id', $jobId)->where('technician_id', $fixture['actors']['technician']['id'])->exists()
        || ! $partReturn || bccomp((string) $partReturn->quantity, '1.00000000', 8) !== 0 || bccomp((string) $partReturn->amount, '20.00', 2) !== 0
        || ! $receiptLayer || bccomp((string) $receiptLayer->original_value, '40.00', 2) !== 0 || bccomp((string) $receiptLayer->allocated_amount, '40.00', 2) !== 0
        || ! $issueAllocation || (int) $issueAllocation->layer_id !== (int) ($receiptLayer->id ?? 0) || bccomp((string) $issueAllocation->quantity, '2.00000000', 8) !== 0 || bccomp((string) $issueAllocation->amount, '40.00', 2) !== 0
        || ! $issueMovement || $issueMovement->operation !== 'workshop_issue' || bccomp((string) $issueMovement->quantity, '-2.00000000', 8) !== 0
        || ! $returnMovement || $returnMovement->operation !== 'workshop_return' || bccomp((string) $returnMovement->quantity, '1.00000000', 8) !== 0
        || ! $returnFifoCorrection || (int) $returnFifoCorrection->original_movement_id !== (int) ($issueMovement->id ?? 0)
        || (int) $returnFifoCorrection->source_layer_id !== (int) ($receiptLayer->id ?? 0) || bccomp((string) $returnFifoCorrection->quantity, '1.00000000', 8) !== 0 || bccomp((string) $returnFifoCorrection->amount, '20.00', 2) !== 0
        || ! $partBalance || bccomp((string) $partBalance->on_hand, '1.00000000', 8) !== 0 || bccomp((string) $remainingFifoValue, '20.00', 2) !== 0
        || ! DB::table('accounting_journals')->where('id', $partIssue->journal_id ?? 0)->where('status', 'posted')->exists()
        || ! DB::table('inventory_movement_journals as imj')->join('accounting_journals as j', 'j.id', '=', 'imj.journal_id')->where('imj.movement_id', $partReturn->movement_id ?? 0)->where('imj.amount', '20.00')->where('j.status', 'posted')->exists()
        || $afterSnapshot !== $fixture['unaffected_snapshot']) throw new RuntimeException('M09 final source chain, partial balance, or unaffected company/other-branch snapshot did not match.');
    echo 'PASS M09 browser source chain; invoice, partial collection, real FIFO part issue/use/return, and unaffected company/other-branch snapshots verified.'.PHP_EOL;
    exit(0);
}
if (is_file($workshopPath)) throw new RuntimeException('M09 fixture receipt already exists; do not reuse consumed synthetic actors or workflow.');
$branchId = (int) $run['branch_id']; $today = now('Africa/Cairo')->toDateString(); $tag = strtoupper(substr($runNonce, 0, 12));
$permissionSets = [
    'intake' => ['M09.view','M09.intake_manage','M09.custody','M09.job_manage','M09.assign','M09.manage','M03.customer_view','M02.product_view','M01.branch_view'],
    'technician' => ['M09.view','M09.execute','M09.labor_record','M01.branch_view'],
    'estimator' => ['M09.view','M09.estimate_manage','M09.cost_view','M09.quote_issue','M09.document_view','M03.customer_view','M02.product_view','M11.tax_calculate','M01.branch_view'],
    'price_approver' => ['M09.view','M09.estimate_approve','M01.branch_view'],
    'customer_acceptor' => ['M09.view','M09.customer_acceptance','M01.branch_view'],
    'verifier' => ['M09.view','M09.verify','M09.deliver','M12.ar_view','M01.branch_view'],
    'billing' => ['M09.view','M09.billing_request','M09.job_manage','M12.customer_invoice_manage','M12.ar_view','M03.customer_view','M02.product_view','M11.tax_calculate','M01.branch_view'],
    'poster' => ['M09.view','M12.customer_invoice_post','M12.ar_view','M03.customer_view','M02.product_view','M11.source_post','M11.journal_post','M01.branch_view'],
    'cashier' => ['M09.view','M12.ar_view','M13.collection_execute','M13.account_view','M11.source_post','M11.journal_post','M03.customer_view','M01.branch_view'],
];
$actors = []; $branchScope = static function (int $user, int $branch): void { DB::table('user_branch_scopes')->insertOrIgnore(['user_id'=>$user,'branch_id'=>$branch,'created_at'=>now(),'updated_at'=>now()]); };
foreach ($permissionSets as $role => $permissions) {
    $password = Str::random(48); $name = 'DEMO-EG M09 '.$role.' '.$tag; $email = 'g2ui-m09-'.$role.'-'.strtolower($tag).'@example.test';
    $actor = User::factory()->create(['name'=>$name,'email'=>$email,'password'=>$password,'active'=>true,'email_verified_at'=>now()]); $branchScope((int) $actor->id, $branchId);
    foreach ($permissions as $permission) {
        $scope = PermissionCatalog::DEFINITIONS[$permission][1] ?? null;
        if ($scope === null) throw new RuntimeException('Unknown explicit M09 acceptance permission '.$permission);
        DB::table('access_grants')->insert(['user_id'=>$actor->id,'permission_code'=>$permission,'branch_id'=>$scope === 'branch' ? $branchId : null,'created_at'=>now(),'updated_at'=>now()]);
    }
    $actors[$role] = ['id'=>(int)$actor->id,'name'=>$name,'email'=>$email,'password'=>$password];
}
$managerId = (int) $actors['intake']['id'];
$workshop = app(WorkshopActions::class)->saveWorkshop($managerId, null, 0, ['code'=>'M09-'.$tag,'name'=>'ورشة قبول اصطناعية '.$tag,
    'specialty'=>'اختبار قبول محلي لسير إصلاح مدفوع','branch_id'=>$branchId,'supervisor_id'=>$managerId,'warehouse_id'=>null,'capacity'=>4,
    'schedule'=>[['day'=>0,'start'=>'08:00','end'=>'17:00'],['day'=>1,'start'=>'08:00','end'=>'17:00']], 'active'=>true]);
app(WorkshopActions::class)->member($managerId,$workshop,1,(int)$actors['technician']['id'],true);
require_once $root.'/tests/Support/gate2-workshop-parts-fixture.php';
$partsFixture = gate2WorkshopPartsFixture((int) $run['actors']['maker']['id'], (int) $run['actors']['approver']['id'], $branchId, $runNonce);
$fixture = ['state'=>'PREPARED','database'=>$database,'port'=>49686,'ownership_nonce'=>$nonce,'run_nonce'=>$runNonce,'branch_id'=>$branchId,'workshop_id'=>$workshop,
    'customer_id'=>(int)$run['customer_id'],'customer_name'=>$run['customer_name'],'service_product_id'=>(int)$run['service_product_id'],'cash_account_id'=>(int)$run['cash_account_id'],
    'actors'=>$actors,'parts'=>$partsFixture,'today'=>$today,'run_tag'=>$tag,'collection_amount'=>'50.00','collection_reference'=>'M09 UI partial collection '.$tag,
    'unaffected_snapshot'=>$tableSnapshot($branchId),'created_at'=>gmdate(DATE_ATOM)];
$writePrivate($workshopPath,$fixture);
echo 'READY nonce-scoped M09 actors and workshop; no workshop work or financial posting was pre-created.'.PHP_EOL;
