<?php

declare(strict_types=1);

// Two-process Gate 1 close/post race on the dedicated, cloned Gate 1 test database.
use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\FinancialReports;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\ErrorCode;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Domain\Branch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 2);
$task = $root.'/.tmp/master-remediation-20261101';
$raceOrder = getenv('GATE1_RACE_ORDER') ?: 'close-first';
if (! in_array($raceOrder, ['close-first', 'collect-first'], true)) {
    throw new RuntimeException('GATE1_RACE_ORDER must be close-first or collect-first.');
}
$environment = ['APP_ENV' => (string) (getenv('APP_ENV') ?: 'testing'), 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: ''), 'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: ''), 'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: '')];
$ciTarget = (int) $environment['DB_PORT'] === 5432;
$ciRunId = (string) (getenv('GATE1_CI_RUN_ID') ?: '');
if ($ciTarget && ! preg_match('/^[a-f0-9]{32}$/', $ciRunId)) {
    throw new RuntimeException('CI Gate1 race requires the current 32-hex GATE1_CI_RUN_ID.');
}
if ($ciTarget) {
    require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
    $target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $environment);
    $database = $target['database'];
    $dbUsername = $target['username'];
    $dbPassword = (string) (getenv('DB_PASSWORD') ?: '');
    $port = (int) $target['port'];
} else {
    $targetPath = getenv('GATE1_TARGET_PATH') ?: $task.'/race2-target.json';
    $target = is_file($targetPath) ? json_decode((string) file_get_contents($targetPath), true) : null;
    $database = (string) ($target['database'] ?? '');
    $dbUsername = 'postgres';
    $dbPassword = '';
    $port = (int) ($target['port'] ?? 0);
}

$setEnv = static function (string $key, string $value): void {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
};
$privateStorage = $task.'/race-storage';
$privateCache = '.tmp/master-remediation-20261101/race-cache';
$privateViews = $task.'/race-views';
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $port, 'DB_DATABASE' => $database,
    'DB_USERNAME' => $dbUsername, 'DB_PASSWORD' => $dbPassword, 'DB_URL' => '', 'LARAVEL_STORAGE_PATH' => $privateStorage,
    'APP_CONFIG_CACHE' => $privateCache.'/config.php', 'APP_ROUTES_CACHE' => $privateCache.'/routes.php',
    'APP_EVENTS_CACHE' => $privateCache.'/events.php', 'APP_SERVICES_CACHE' => $privateCache.'/services.php',
    'APP_PACKAGES_CACHE' => $privateCache.'/packages.php', 'APP_VIEW_COMPILED_PATH' => '.tmp/master-remediation-20261101/race-views',
    'VIEW_COMPILED_PATH' => $privateViews,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'APP_DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    $setEnv($key, $value);
}
if (! is_dir($privateCache)) mkdir($privateCache, 0770, true);
if (! is_dir($privateStorage)) mkdir($privateStorage, 0770, true);
if (! is_dir($privateViews)) mkdir($privateViews, 0770, true);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($privateStorage);
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $ciTarget ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
if ($ciTarget) {
    Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
} else {
    $expectedData = realpath($task.'/pgdata');
    $marker = $task.'/pgdata/.codex-audit-owned.txt';
    if (! is_array($target) || ($target['database'] ?? null) !== $database || (int) ($target['port'] ?? 0) !== 49685
        || realpath((string) ($target['pgdata'] ?? '')) !== $expectedData || ($target['marker'] ?? null) !== 'master-remediation-20261101 Gate1 private PostgreSQL marker'
        || $identity->database !== $database || $identity->server_address !== '127.0.0.1' || (int) $identity->port !== 49685
        || realpath((string) $identity->data_directory) !== $expectedData || ! is_file($marker)
        || trim((string) file_get_contents($marker)) !== $target['marker']) {
        throw new RuntimeException('ABORT before writes: exact private Gate1 race database/port/data_directory/marker identity is required.');
    }
}

$grant = static function (User $actor, array $permissions, ?int $branch = null): void {
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => $permission,
            'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$uuid = static fn (): string => (string) Str::uuid();
$worker = $argv[1] ?? null;
if ($worker === '--worker') {
    $mode = $argv[2] ?? '';
    $actorId = (int) ($argv[3] ?? 0);
    $periodId = (int) ($argv[4] ?? 0);
    $customerId = (int) ($argv[5] ?? 0);
    $treasuryAccountId = (int) ($argv[6] ?? 0);
    $branchId = (int) ($argv[7] ?? 0);
    $date = (string) ($argv[8] ?? '');
    $idempotencyKey = (string) ($argv[9] ?? '');
    $run = (string) ($argv[10] ?? '');
    $controlDir = $task.'/race-control/'.$run;
    $readyPath = $controlDir.'/'.$mode.'.ready';
    $startPath = $controlDir.'/'.$mode.'.GO';
    $resultPath = $controlDir.'/'.$mode.'.result.json';
    if (! in_array($mode, ['close', 'collect'], true) || $actorId < 1 || $periodId < 1 || $customerId < 1
        || ! is_dir($controlDir) || ! touch($readyPath)) {
        throw new RuntimeException('Invalid race worker inputs.');
    }
    DB::select("select set_config('application_name', ?, false)", ['g1_'.$mode.'_'.$run]);
    $deadline = microtime(true) + 30;
    while (! is_file($startPath) && microtime(true) < $deadline) usleep(20000);
    if (! is_file($startPath)) throw new RuntimeException('Race start barrier timeout.');
    try {
        if ($mode === 'close') {
            app(FinancialReports::class)->closePeriod($actorId, $periodId);
        } else {
            $collection = app(TreasuryApi::class)->executeCustomerCollection($actorId, ['idempotency_key' => $idempotencyKey,
                'branch_id' => $branchId, 'customer_id' => $customerId, 'account_id' => $treasuryAccountId, 'date' => $date,
                'amount' => '1.00', 'allow_advance' => true, 'reference' => 'Gate1 concurrent close/post test'], []);
        }
        $result = ['ci_run_id' => getenv('GATE1_CI_RUN_ID') ?: null, 'mode' => $mode, 'outcome' => 'success', 'collection' => $collection ?? null];
    } catch (Throwable $error) {
        $expectedRejection = $error instanceof ValidationException
            || ($error instanceof ContractException && $error->errorCode === ErrorCode::Forbidden)
            || ($error instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && in_array($error->getStatusCode(), [403, 409, 422], true));
        $result = ['ci_run_id' => getenv('GATE1_CI_RUN_ID') ?: null, 'mode' => $mode, 'outcome' => $expectedRejection ? 'rejected' : 'error', 'exception' => $error::class,
            'message' => mb_substr($error->getMessage(), 0, 500)];
    }
    file_put_contents($resultPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    exit(($result['outcome'] === 'error') ? 1 : 0);
}

$receiptPath = $ciTarget ? $root.'/.tmp/ci-postgres/gate1-close-post-race-'.$ciRunId.'-'.$raceOrder.'.json'
    : (getenv('GATE1_EVIDENCE_PATH') ?: $root.'/docs/release/remediation/evidence/gate1/gate1-close-post-race-'.$raceOrder.'.json');
if (! $ciTarget && ! str_starts_with(str_replace('\\', '/', $receiptPath), str_replace('\\', '/', $root.'/docs/release/remediation/evidence/gate1/'))) {
    throw new RuntimeException('GATE1_EVIDENCE_PATH must stay under docs/release/remediation/evidence/gate1/.');
}
$result = ['ci_run_id' => getenv('GATE1_CI_RUN_ID') ?: null,
    'database' => $database.'@'.$identity->server_address.':'.(int) $identity->port, 'marker_verified' => ! $ciTarget,
    'ci_receipt_verified' => $ciTarget, 'ownership_verified' => true, 'result' => 'INCOMPLETE'];
try {
    if (DB::table('companies')->exists() || DB::table('branches')->exists() || DB::table('accounting_periods')->exists()) {
        throw new RuntimeException('Race database is not empty; refusing to overwrite existing isolated fixture data.');
    }
    DB::beginTransaction();
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $approverPoster = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $closer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $grant($maker, ['M01.company_update', 'M11.account_manage', 'M11.setup_manage', 'M11.tax_manage', 'M11.period_open', 'M11.opening_manage',
        'M11.opening_entry', 'M11.opening_review', 'M11.opening_approve', 'M11.financial_activate', 'M03.customer_view', 'M13.account_manage']);
    $grant($approverPoster, ['M11.opening_review', 'M11.opening_approve', 'M11.financial_activate']);
    $grant($closer, ['M11.period_close', 'M11.report_view']);
    app(SaveCompany::class)->execute($maker, ['code' => 'G1-RACE', 'name' => 'Gate 1 race test company',
        'legal_name' => 'Gate 1 Race Synthetic Company', 'tax_registration' => 'G1-RACE-TEST', 'address' => 'Synthetic race fixture only'], null);
    $branch = Branch::factory()->create(['code' => 'G1-RACE-'.strtoupper(Str::random(6))]);
    foreach ([[$maker, ['M11.source_post', 'M12.ar_view', 'M13.collection_execute']], [$closer, ['M11.report_view']]] as [$actor, $permissions]) {
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $grant($actor, $permissions, (int) $branch->id);
    }

    $actions = app(AccountingActions::class);
    $mappings = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $mappings[$purpose] = $actions->account((int) $maker->id, null, 0, ['code' => 'G1R-'.strtoupper($purpose).'-'.strtoupper(Str::random(4)),
            'name' => 'Gate 1 race '.$label, 'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]);
    }
    $date = now('Africa/Cairo')->toDateString();
    $start = now('Africa/Cairo')->startOfYear()->toDateString();
    $end = now('Africa/Cairo')->endOfYear()->toDateString();
    $period = $actions->period((int) $maker->id, ['name' => 'Gate 1 race period', 'starts_on' => $start, 'ends_on' => $end]);
    $activation = DB::table('accounting_activation')->where('id', 1)->first();
    if (! $activation || $activation->activated_at !== null) throw new RuntimeException('Race fixture requires an unused activation row.');
    $setup = app(FinancialActivation::class);
    $version = (int) $activation->version;
    $setup->save((int) $maker->id, $version, ['go_live_date' => $date, 'mappings' => $mappings]);
    $version++;
    foreach (['sale', 'purchase'] as $type) {
        $tax = ['code' => 'G1R-TAX-'.strtoupper($type), 'name' => 'Gate 1 race '.$type.' tax', 'transaction_type' => $type,
            'product_id' => null, 'rate' => '14', 'starts_on' => $date, 'ends_on' => null, 'treatment' => 'percentage', 'tax_scale' => 2,
            'legal_reference' => 'Synthetic isolated race test only'];
        if ($type === 'purchase') $tax['purchase_recoverable'] = true;
        $setup->addTaxRule((int) $maker->id, $version++, $tax);
    }
    $setup->validateTaxTreatment((int) $maker->id, $version++, 'Gate 1 race synthetic tax validation');
    $opening = app(OpeningBalances::class);
    $batch = $opening->save((int) $maker->id, $version, $uuid(), [], true, 'No opening balances declared for the Gate1 race test database.');
    $opening->advance((int) $maker->id, $batch, 1, 'submit');
    $opening->advance((int) $approverPoster->id, $batch, 2, 'approve');
    app(ActivateFinancialPosting::class)->execute((int) $approverPoster->id, $batch, 3);

    $advanceAccount = $actions->account((int) $maker->id, null, 0, ['code' => 'G1R-ADVANCE', 'name' => 'Gate 1 race customer advance liability',
        'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $setup->saveOptionalMapping((int) $maker->id, 'customer_advance', $advanceAccount);
    $treasuryAccount = app(TreasuryApi::class)->saveOperationalAccount((int) $maker->id, ['code' => 'G1R-CASH',
        'name' => 'Gate 1 race synthetic cash', 'kind' => 'cash', 'gl_account_id' => (int) $mappings['cash']]);
    $customer = Customer::factory()->create(['status' => 'active']);
    DB::commit();

    $run = bin2hex(random_bytes(8));
    $control = $task.'/race-control/'.$run;
    if (! mkdir($control, 0770, true)) throw new RuntimeException('Unable to prepare the private worker barrier.');
    $workerScript = escapeshellarg(__FILE__);
    $key = $uuid();
    $workerArgs = ['--worker', '', '', (string) $period, (string) $customer->id, (string) $treasuryAccount,
        (string) $branch->id, $date, $key, $run];
    $launch = static function (string $mode) use ($workerArgs, $workerScript, $control, $closer, $maker): array {
        $args = $workerArgs;
        $args[1] = $mode;
        $args[2] = (string) ($mode === 'close' ? $closer->id : $maker->id);
        $command = escapeshellarg(PHP_BINARY).' '.$workerScript.' '.implode(' ', array_map('escapeshellarg', $args));
        $pipes = [];
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [0 => ['file', $nullDevice, 'r'], 1 => ['file', $control.'/'.$mode.'.stdout', 'a'],
            2 => ['file', $control.'/'.$mode.'.stderr', 'a']], $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) throw new RuntimeException('Failed to launch '.$mode.' worker.');
        return [$process, $command];
    };
    [$closeProcess] = $launch('close');
    [$collectProcess] = $launch('collect');
    $deadline = microtime(true) + 30;
    while ((! is_file($control.'/close.ready') || ! is_file($control.'/collect.ready')) && microtime(true) < $deadline) usleep(20000);
    if (! is_file($control.'/close.ready') || ! is_file($control.'/collect.ready')) {
        throw new RuntimeException('One or both workers did not reach the synchronized start barrier.');
    }
    // Queue both workers behind our test transaction's advisory locks in the selected
    // order. Releasing both locks gives deterministic, real cross-process contention.
    DB::beginTransaction();
    DB::select('select pg_advisory_xact_lock(10401)');
    DB::select('select pg_advisory_xact_lock(11001)');
    $queueWorker = static function (string $mode) use ($control, $run): void {
        file_put_contents($control.'/'.$mode.'.GO', 'start '.gmdate(DATE_ATOM), LOCK_EX);
        $lockDeadline = microtime(true) + 30;
        $queued = false;
        while (! $queued && microtime(true) < $lockDeadline) {
            $queued = (bool) DB::selectOne("select pid from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", ['g1_'.$mode.'_'.$run]);
            if (! $queued) usleep(20000);
        }
        if (! $queued) throw new RuntimeException(ucfirst($mode).' worker did not queue on the held accounting lock.');
    };
    try {
        foreach ($raceOrder === 'close-first' ? ['close', 'collect'] : ['collect', 'close'] as $mode) {
            $queueWorker($mode);
        }
    } catch (Throwable $error) {
        DB::rollBack();
        throw $error;
    }
    DB::commit();
    $closeExit = proc_close($closeProcess);
    $collectExit = proc_close($collectProcess);
    $closeResult = json_decode((string) @file_get_contents($control.'/close.result.json'), true);
    $collectResult = json_decode((string) @file_get_contents($control.'/collect.result.json'), true);
    $periodRow = DB::table('accounting_periods')->find($period);
    $movement = DB::table('treasury_movements')->where('idempotency_key', $key)->first();
    $journalRow = $movement ? DB::table('accounting_journals')->find($movement->journal_id) : null;
    $advanceCount = (int) DB::table('rp_customer_advances')->where('customer_id', $customer->id)->count();
    $closure = DB::table('accounting_period_closures')->where('period_id', $period)->first();
    $stableClose = $periodRow && $periodRow->status === 'closed' && $closure && hash_equals($closure->sha256, hash('sha256', $closure->evidence));
    $orderConsistent = $raceOrder === 'close-first'
        ? (($closeResult['outcome'] ?? null) === 'success' && ($collectResult['outcome'] ?? null) === 'rejected'
            && ! $movement && ! $journalRow && $advanceCount === 0)
        : (($collectResult['outcome'] ?? null) === 'success' && $movement && $journalRow && $journalRow->status === 'posted'
            && $advanceCount === 1 && ($closeResult['outcome'] ?? null) === 'success');
    $pass = $closeExit === 0 && $collectExit === 0 && $stableClose && $orderConsistent
        && DB::table('accounting_period_closures')->where('period_id', $period)->count() === 1;
    $result = ['ci_run_id' => getenv('GATE1_CI_RUN_ID') ?: null,
        'database' => $database.'@'.$identity->server_address.':'.(int) $identity->port, 'marker_verified' => ! $ciTarget,
        'ci_receipt_verified' => $ciTarget, 'ownership_verified' => true,
        'synthetic_committed_fixture' => true, 'actor_ids' => ['creator' => (int) $maker->id, 'approver_poster' => (int) $approverPoster->id, 'closer' => (int) $closer->id],
        'branch_id' => (int) $branch->id, 'period_id' => (int) $period, 'customer_id' => (int) $customer->id,
        'workers' => ['close_exit' => $closeExit, 'close' => $closeResult, 'collect_exit' => $collectExit, 'collect' => $collectResult],
        'final_state' => ['period_status' => $periodRow->status ?? null, 'journal_status' => $journalRow->status ?? null,
            'treasury_movement_count' => DB::table('treasury_movements')->count(), 'customer_advance_count' => $advanceCount,
            'closure_count' => DB::table('accounting_period_closures')->where('period_id', $period)->count(),
            'closure_hash_valid' => $closure ? hash_equals($closure->sha256, hash('sha256', $closure->evidence)) : false],
        'race_order' => $raceOrder, 'serialized_invariant' => $orderConsistent,
        'expected_order' => $raceOrder === 'close-first'
            ? 'close commits under advisory lock before collection; later source posting is denied atomically'
            : 'source collection commits under advisory lock before close; close reconciles it and records one closure',
        'result' => $pass ? 'PASS' : 'FAIL'];
    if (! is_dir(dirname($receiptPath))) mkdir(dirname($receiptPath), 0770, true);
    file_put_contents($receiptPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    echo 'RESULT '.$result['result'].PHP_EOL;
    exit($pass ? 0 : 1);
} catch (Throwable $error) {
    if (DB::transactionLevel() > 0) DB::rollBack();
    $result += ['error_class' => $error::class, 'error' => mb_substr($error->getMessage(), 0, 1000), 'result' => 'FAIL'];
    if (! is_dir(dirname($receiptPath))) mkdir(dirname($receiptPath), 0770, true);
    file_put_contents($receiptPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    fwrite(STDERR, 'FAIL '.$error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
}
