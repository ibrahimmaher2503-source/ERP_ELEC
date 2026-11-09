<?php

declare(strict_types=1);

// Read-only preflight for an independent Sales Order browser acceptance run.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = realpath(dirname(__DIR__, 2));
$private = $root.'/.tmp/master-remediation-20261101/ui-private';
$finance = $private.'/finance';
$bootstrapPath = $finance.'/gate2-finance-ui-bootstrap.json';
$runNonce = (string) ($argv[1] ?? '');
$runPath = $finance.'/gate2-finance-ui-run-'.$runNonce.'.json';
$bootstrap = is_file($bootstrapPath) ? json_decode((string) file_get_contents($bootstrapPath), true, 512, JSON_THROW_ON_ERROR) : null;
$run = preg_match('/^[a-f0-9]{32}$/D', $runNonce) && is_file($runPath)
    ? json_decode((string) file_get_contents($runPath), true, 512, JSON_THROW_ON_ERROR) : null;
$nonce = (string) (getenv('E2E_FINANCE_DB_NONCE') ?: '');
$database = (string) (getenv('DB_DATABASE') ?: '');
if (str_replace('\\', '/', (string) realpath($private)) !== str_replace('\\', '/', $private)
    || str_replace('\\', '/', (string) realpath($finance)) !== str_replace('\\', '/', $finance)
    || str_replace('\\', '/', (string) realpath($bootstrapPath)) !== str_replace('\\', '/', $bootstrapPath)
    || str_replace('\\', '/', (string) realpath($runPath)) !== str_replace('\\', '/', $runPath)
    || trim((string) @file_get_contents($private.'/.codex-ui-owned.txt')) !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
    || ! is_array($bootstrap) || ! is_array($run) || ($run['state'] ?? null) !== 'READY'
    || ! preg_match('/^[a-f0-9]{64}$/D', $nonce)
    || ! hash_equals((string) ($bootstrap['ownership_nonce'] ?? ''), $nonce)
    || ! hash_equals((string) ($run['ownership_nonce'] ?? ''), $nonce)
    || $database !== 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32)
    || $database !== ($run['database'] ?? null) || (int) ($run['port'] ?? 0) !== 49686) {
    throw new RuntimeException('Refusing Sales Order preflight outside the exact nonce-owned Gate2 finance run.');
}

foreach (['APP_ENV' => 'testing', 'APP_KEY' => $bootstrap['app_key'], 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '49686', 'DB_DATABASE' => $database,
    'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 49686, 'database.connections.pgsql.database' => $database,
    'database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => '',
    'database.connections.pgsql.url' => null]);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select r.rolname from pg_database d join pg_roles r on r.oid=d.datdba where d.datname=current_database()) as database_owner");
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, ['APP_ENV' => 'testing', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '49686', 'E2E_DB_PORT' => '49686', 'DB_DATABASE' => $database,
    'DB_USERNAME' => 'postgres', 'E2E_FINANCE_DB_NONCE' => $nonce]);
$nonceRow = DB::selectOne('select nonce from codex_test_guard.ownership_receipts where database = ?', [$database]);
Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, $identity, $nonceRow);

$required = [
    'maker' => ['M04.sales_order_view', 'M04.sales_order_create', 'M04.sales_order_submit'],
    'approver' => ['M04.sales_order_view', 'M04.sales_order_approve'],
];
$blockers = [];
foreach ($required as $role => $codes) {
    $actor = $run['actors'][$role] ?? [];
    $id = (int) ($actor['id'] ?? 0);
    $actual = DB::table('access_grants')->where('user_id', $id)->where('branch_id', (int) $run['branch_id'])
        ->whereIn('permission_code', $codes)->pluck('permission_code')->all();
    foreach (array_diff($codes, $actual) as $code) {
        $blockers[] = "$role lacks $code in branch {$run['branch_id']}";
    }
}
echo json_encode(['status' => $blockers ? 'BLOCKED' : 'READY_FOR_BROWSER_SETUP', 'database' => $database,
    'run_nonce' => $runNonce, 'blockers' => $blockers], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
