<?php

declare(strict_types=1);

// Creates one nonce-named, private Gate 2 finance UI database on the owned 49686 cluster.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = realpath(dirname(__DIR__, 2));
$privateRoot = $root.'/.tmp/master-remediation-20261101/ui-private';
$financeRoot = $privateRoot.'/finance';
$cluster = $root.'/.tmp/master-remediation-20261101/ui-pgdata';
$markerPath = $privateRoot.'/.codex-ui-owned.txt';
$marker = 'master-remediation-20261101 Gate2 UI private PostgreSQL marker';
$receiptPath = $financeRoot.'/gate2-finance-ui-bootstrap.json';
$markerPrivate = realpath($privateRoot);
$resolvedRoot = realpath($root);
$resolvedCluster = realpath($cluster);
if ($resolvedRoot === false || $resolvedCluster === false || $markerPrivate !== $resolvedRoot.DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.'master-remediation-20261101'.DIRECTORY_SEPARATOR.'ui-private'
    || ! str_starts_with($resolvedCluster, rtrim($resolvedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
    || ! is_file($markerPath) || realpath($markerPath) !== $markerPrivate.DIRECTORY_SEPARATOR.'.codex-ui-owned.txt'
    || trim((string) file_get_contents($markerPath)) !== $marker) {
    throw new RuntimeException('Refusing finance UI bootstrap without the exact private Gate 2 marker and in-checkout PGDATA.');
}
foreach ([$financeRoot, $financeRoot.'/cache', $financeRoot.'/views', $financeRoot.'/storage/framework/sessions',
    $financeRoot.'/storage/framework/cache', $financeRoot.'/storage/framework/views', $financeRoot.'/storage/logs'] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create a private finance UI runtime directory.');
    }
}
$resolvedFinanceRoot = realpath($financeRoot);
if ($resolvedFinanceRoot === false || ! str_starts_with($resolvedFinanceRoot, rtrim($markerPrivate, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Finance runtime paths must resolve beneath the exact private UI directory.');
}
if (is_file($receiptPath) && (! realpath($receiptPath) || ! str_starts_with(realpath($receiptPath), $resolvedFinanceRoot.DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Finance bootstrap receipt resolves outside its private runtime directory.');
}

// Check both the live server identity and its postmaster file before the first CREATE DATABASE.
$pidFile = $cluster.'/postmaster.pid';
if (! is_file($cluster.'/PG_VERSION') || trim((string) file_get_contents($cluster.'/PG_VERSION')) !== '17' || ! is_file($pidFile)) {
    throw new RuntimeException('The exact owned PostgreSQL 17 cluster is not live; refusing database creation.');
}
$pidLines = file($pidFile, FILE_IGNORE_NEW_LINES);
if (! is_array($pidLines) || count($pidLines) < 4 || ! ctype_digit((string) ($pidLines[0] ?? ''))
    || realpath((string) ($pidLines[1] ?? '')) !== $resolvedCluster || (int) ($pidLines[3] ?? 0) !== 49686) {
    throw new RuntimeException('Live PGDATA PID metadata does not match the exact private cluster and port.');
}
try {
    $adminPdo = new PDO('pgsql:host=127.0.0.1;port=49686;dbname=postgres', 'postgres', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $error) {
    throw new RuntimeException('The owned PostgreSQL cluster cannot be reached for a read-only identity check.', 0, $error);
}
$server = $adminPdo->query("select current_database() as database, host(inet_server_addr()) as server_address,
    inet_server_port() as port, current_setting('data_directory') as data_directory, current_user as current_user,
    current_setting('server_version_num') as server_version_num,
    (select r.rolname from pg_database d join pg_roles r on r.oid=d.datdba where d.datname=current_database()) as database_owner")->fetch(PDO::FETCH_ASSOC);
if (($server['database'] ?? null) !== 'postgres' || ($server['server_address'] ?? null) !== '127.0.0.1'
    || (int) ($server['port'] ?? 0) !== 49686 || ($server['current_user'] ?? null) !== 'postgres'
    || ($server['database_owner'] ?? null) !== 'postgres' || (int) ($server['server_version_num'] ?? 0) < 170000
    || (int) ($server['server_version_num'] ?? 0) >= 180000 || realpath((string) ($server['data_directory'] ?? '')) !== $resolvedCluster) {
    throw new RuntimeException('Live PostgreSQL identity differs from the exact owned Gate 2 cluster.');
}

$receipt = is_file($receiptPath) ? json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR) : null;
if (! is_array($receipt)) {
    $unowned = $adminPdo->query("select datname from pg_database where datname like 'erp_elec_new_finance_ui_%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($unowned !== []) {
        throw new RuntimeException('A finance UI database exists without the private receipt; refusing to adopt or replace it.');
    }
    $nonce = bin2hex(random_bytes(32));
    $receipt = ['database' => 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32), 'port' => 49686,
        'data_directory' => $resolvedCluster, 'ownership_nonce' => $nonce,
        'app_key' => 'base64:'.base64_encode(random_bytes(32)), 'created_at' => gmdate(DATE_ATOM)];
    $handle = fopen($receiptPath, 'x');
    if (! $handle) {
        throw new RuntimeException('Finance bootstrap receipt already exists; preserve it and inspect before retrying.');
    }
    try {
        if (fwrite($handle, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL) === false) {
            throw new RuntimeException('Could not write the private finance database receipt.');
        }
        fflush($handle);
    } finally {
        fclose($handle);
    }
}
$nonce = (string) ($receipt['ownership_nonce'] ?? '');
$database = (string) ($receipt['database'] ?? '');
if (! preg_match('/^[a-f0-9]{64}$/D', $nonce) || $database !== 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32)
    || (int) ($receipt['port'] ?? 0) !== 49686 || realpath((string) ($receipt['data_directory'] ?? '')) !== $resolvedCluster
    || ! preg_match('/^base64:[A-Za-z0-9+\/=]+$/D', (string) ($receipt['app_key'] ?? ''))) {
    throw new RuntimeException('Private finance bootstrap receipt does not match the owned database name, PGDATA, or nonce.');
}
$quotedDatabase = '"'.str_replace('"', '""', $database).'"';
$databaseRow = $adminPdo->prepare('select datname, pg_get_userbyid(datdba) as owner from pg_database where datname = :name');
$databaseRow->execute(['name' => $database]);
$existing = $databaseRow->fetch(PDO::FETCH_ASSOC);
$databaseWasExisting = is_array($existing);
if (! $existing) {
    $adminPdo->exec('CREATE DATABASE '.$quotedDatabase.' OWNER "postgres"');
} elseif (($existing['owner'] ?? null) !== 'postgres') {
    throw new RuntimeException('Nonce-named finance UI database is not owned by postgres; refusing to change it.');
}

foreach (['APP_ENV' => 'testing', 'APP_KEY' => $receipt['app_key'], 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '49686', 'DB_DATABASE' => $database, 'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '',
    'E2E_DB_PORT' => '49686', 'E2E_FINANCE_DB_NONCE' => $nonce,
    'APP_CONFIG_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/config.php',
    'APP_ROUTES_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/routes.php',
    'APP_EVENTS_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/events.php',
    'APP_SERVICES_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/services.php',
    'APP_PACKAGES_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/packages.php',
    'APP_VIEW_COMPILED_PATH' => $financeRoot.'/views', 'VIEW_COMPILED_PATH' => $financeRoot.'/views',
    'LARAVEL_STORAGE_PATH' => $financeRoot.'/storage', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($financeRoot.'/storage');
$app->make(Kernel::class)->bootstrap();
config(['app.key' => $receipt['app_key'], 'database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 49686, 'database.connections.pgsql.database' => $database,
    'database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => '', 'database.connections.pgsql.url' => null,
    'filesystems.disks.local.root' => $financeRoot.'/storage/app/private', 'filesystems.disks.local.visibility' => 'private',
    'filesystems.disks.local.throw' => true, 'cache.default' => 'array', 'session.driver' => 'file']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select r.rolname from pg_database d join pg_roles r on r.oid=d.datdba where d.datname=current_database()) as database_owner");
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$environment = ['APP_ENV' => 'testing', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '49686', 'E2E_DB_PORT' => '49686',
    'DB_DATABASE' => $database, 'DB_USERNAME' => 'postgres', 'E2E_FINANCE_DB_NONCE' => $nonce];
$target = OptimizationRegressionDatabaseGuard::target($root, $environment);
OptimizationRegressionDatabaseGuard::verifyIdentity($target, $identity);
if (($identity->database_owner ?? null) !== 'postgres' || (int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
    throw new RuntimeException('Finance UI database must use PostgreSQL 17 and the exact owned target before guard changes.');
}
$tableRows = DB::select("select table_schema, table_name from information_schema.tables where table_type='BASE TABLE'
    and table_schema not in ('pg_catalog','information_schema') and table_schema <> 'codex_test_guard'");
$guardTableExists = DB::scalar("select exists(select 1 from information_schema.tables where table_schema='codex_test_guard' and table_name='ownership_receipts')");
if ($databaseWasExisting && ! $guardTableExists) {
    throw new RuntimeException('Refusing to adopt an existing finance database without its in-database ownership receipt.');
}
if (! $guardTableExists) {
    if ($tableRows !== []) {
        throw new RuntimeException('Refusing a finance database with preexisting application tables and no nonce receipt.');
    }
    DB::statement('create schema codex_test_guard');
    DB::statement('create table codex_test_guard.ownership_receipts (database text primary key, nonce char(64) not null)');
}
$nonceRow = DB::table('codex_test_guard.ownership_receipts')->where('database', $database)->first(['nonce']);
if ($nonceRow === null) {
    if ($databaseWasExisting || $tableRows !== []) {
        throw new RuntimeException('Refusing to adopt existing finance database tables without the matching ownership nonce.');
    }
    DB::table('codex_test_guard.ownership_receipts')->insert(['database' => $database, 'nonce' => $nonce]);
    $nonceRow = (object) ['nonce' => $nonce];
}
OptimizationRegressionDatabaseGuard::verify($target, $identity, $nonceRow);
if (($identity->database_owner ?? null) !== 'postgres' || (int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
    throw new RuntimeException('Finance UI database must use PostgreSQL 17 and the exact owned target.');
}
$migrationExit = Artisan::call('migrate', ['--force' => true]);
if ($migrationExit !== 0) {
    throw new RuntimeException('Finance UI migrations failed; preserve the receipt and database for diagnosis.');
}
echo 'READY nonce-owned isolated finance UI database; existing owner records were not targeted.'.PHP_EOL;
