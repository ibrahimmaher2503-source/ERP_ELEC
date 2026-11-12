<?php

declare(strict_types=1);

// First-run ownership nonce, forward migrations, and explicit synthetic demo seed for Gate 2 browser acceptance.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 2);
$privateRoot = $root.'/.tmp/master-remediation-20261101';
$privateFiles = $privateRoot.'/ui-private';
$privateCache = '.tmp/master-remediation-20261101/ui-private/cache';
$cluster = $privateRoot.'/ui-pgdata';
$markerPath = $privateFiles.'/.codex-ui-owned.txt';
$marker = 'master-remediation-20261101 Gate2 UI private PostgreSQL marker';
$receiptPath = $privateFiles.'/gate2-ui-bootstrap.json';
if (! is_dir($cluster) || ! is_dir($privateFiles) || ! is_file($markerPath) || trim((string) file_get_contents($markerPath)) !== $marker) {
    throw new RuntimeException('Gate 2 UI database bootstrap requires the exact owned PGDATA marker.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '49686', 'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '',
    'E2E_DB_PORT' => '49686', 'E2E_DB_DATABASE' => 'erp_elec_new_test',
    'APP_CONFIG_CACHE' => $privateCache.'/config.php', 'APP_ROUTES_CACHE' => $privateCache.'/routes.php',
    'APP_EVENTS_CACHE' => $privateCache.'/events.php', 'APP_SERVICES_CACHE' => $privateCache.'/services.php',
    'APP_PACKAGES_CACHE' => $privateCache.'/packages.php', 'APP_VIEW_COMPILED_PATH' => $privateFiles.'/views',
    'VIEW_COMPILED_PATH' => $privateFiles.'/views', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach ([$privateFiles.'/cache', $privateFiles.'/views', $privateFiles.'/storage/framework/sessions',
    $privateFiles.'/storage/framework/cache', $privateFiles.'/storage/framework/views', $privateFiles.'/storage/logs'] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Cannot create private Gate 2 runtime directory.');
    }
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($privateFiles.'/storage');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 49686,
    'database.connections.pgsql.database' => 'erp_elec_new_test', 'database.connections.pgsql.username' => 'postgres',
    'database.connections.pgsql.password' => '', 'database.connections.pgsql.url' => null,
    'cache.default' => 'array', 'session.driver' => 'file']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user,
    current_setting('server_version_num') as server_version_num,
    (select r.rolname from pg_database d join pg_roles r on r.oid=d.datdba where d.datname=current_database()) as database_owner");
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = OptimizationRegressionDatabaseGuard::target($root, ['APP_ENV' => 'testing', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '49686', 'E2E_DB_PORT' => '49686', 'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'postgres']);
OptimizationRegressionDatabaseGuard::verify($target, $identity);
if ($identity->database_owner !== 'postgres' || (int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
    throw new RuntimeException('Gate 2 UI bootstrap requires PostgreSQL 17, postgres owner, and the exact private target.');
}

$receipt = is_file($receiptPath) ? json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR) : null;
if (! is_array($receipt)) {
    $tables = DB::select("select table_schema, table_name from information_schema.tables where table_type = 'BASE TABLE' and table_schema not in ('pg_catalog', 'information_schema')");
    if ($tables !== []) {
        throw new RuntimeException('Refusing to adopt a database with preexisting application tables.');
    }
    $receipt = ['database' => 'erp_elec_new_test', 'port' => 49686, 'data_directory' => realpath((string) $identity->data_directory),
        'nonce' => bin2hex(random_bytes(32)), 'app_key' => 'base64:'.base64_encode(random_bytes(32))];
    if (file_put_contents($receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Could not persist the private bootstrap receipt.');
    }
}
if (($receipt['database'] ?? null) !== 'erp_elec_new_test' || (int) ($receipt['port'] ?? 0) !== 49686
    || realpath((string) ($receipt['data_directory'] ?? '')) !== realpath($target['data_directory'])
    || ! preg_match('/^[a-f0-9]{64}$/', (string) ($receipt['nonce'] ?? '')) || ! str_starts_with((string) ($receipt['app_key'] ?? ''), 'base64:')) {
    throw new RuntimeException('Private bootstrap receipt does not match the live owned database.');
}
if (! is_file($receiptPath.'.initialized')) {
    DB::statement('create schema if not exists codex_test_guard');
    DB::statement('create table if not exists codex_test_guard.ownership_receipts (database text primary key, nonce char(64) not null)');
    $existingNonce = DB::table('codex_test_guard.ownership_receipts')->where('database', 'erp_elec_new_test')->value('nonce');
    if ($existingNonce !== null && ! hash_equals((string) $existingNonce, (string) $receipt['nonce'])) {
        throw new RuntimeException('Database nonce conflicts with the private ownership receipt.');
    }
    if ($existingNonce === null) {
        DB::table('codex_test_guard.ownership_receipts')->insert(['database' => 'erp_elec_new_test', 'nonce' => $receipt['nonce']]);
    }
    file_put_contents($receiptPath.'.initialized', hash('sha256', $receipt['nonce']), LOCK_EX);
} else {
    $stored = DB::table('codex_test_guard.ownership_receipts')->where('database', 'erp_elec_new_test')->value('nonce');
    if (! is_string($stored) || ! hash_equals((string) $receipt['nonce'], $stored)) {
        throw new RuntimeException('In-database ownership nonce no longer matches the private receipt.');
    }
}

putenv('APP_KEY='.$receipt['app_key']);
$_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $receipt['app_key'];
config(['app.key' => $receipt['app_key']]);
$migrateExit = Artisan::call('migrate', ['--force' => true]);
if ($migrateExit !== 0) {
    throw new RuntimeException('Gate 2 migrations failed; preserve the owned cluster and inspect private logs. '.Artisan::output());
}
if (($receipt['demo_seeded'] ?? false) === true) {
    $actorsPath = $privateFiles.'/gate2-ui-actors.json';
    $seederActorPath = $privateFiles.'/gate2-ui-seeder-actor.json';
    $actorFixture = is_file($actorsPath) ? json_decode((string) file_get_contents($actorsPath), true, 512, JSON_THROW_ON_ERROR) : null;
    $seederFixture = is_file($seederActorPath) ? json_decode((string) file_get_contents($seederActorPath), true, 512, JSON_THROW_ON_ERROR) : null;
    $actorReceiptMatches = (is_array($actorFixture) && hash_equals((string) $receipt['nonce'], (string) ($actorFixture['ownership_nonce'] ?? '')))
        || (is_array($seederFixture) && hash_equals((string) $receipt['nonce'], (string) ($seederFixture['ownership_nonce'] ?? '')));
    if (! $actorReceiptMatches) {
        throw new RuntimeException('Demo seed receipt exists but no matching private Gate 2 actor receipt remains.');
    }
    echo 'READY database=erp_elec_new_test port=49686 migrations=applied demo=already-seeded actor-receipt=verified'.PHP_EOL;
    exit(0);
}
$prepareActor = new Process([PHP_BINARY, $root.'/tests/Support/gate2-ui-actors.php', '--prepare-seeder-actor'], $root);
$prepareActor->setTimeout(90);
$prepareActor->run();
if (! $prepareActor->isSuccessful()) {
    throw new RuntimeException('Synthetic Gate 2 seeder actor preparation failed. '.$prepareActor->getErrorOutput().$prepareActor->getOutput());
}
$seederFixturePath = $privateFiles.'/gate2-ui-seeder-actor.json';
$seederFixture = is_file($seederFixturePath) ? json_decode((string) file_get_contents($seederFixturePath), true, 512, JSON_THROW_ON_ERROR) : null;
$seederActorId = (int) ($seederFixture['actor']['id'] ?? 0);
if (($seederFixture['database'] ?? null) !== 'erp_elec_new_test' || (int) ($seederFixture['port'] ?? 0) !== 49686
    || ! hash_equals((string) ($receipt['nonce'] ?? ''), (string) ($seederFixture['ownership_nonce'] ?? ''))
    || $seederActorId < 1 || ! is_string($seederFixture['actor']['email'] ?? null)
    || ! is_string($seederFixture['actor']['password'] ?? null)) {
    throw new RuntimeException('Synthetic seeder actor receipt does not match the owned Gate 2 database.');
}
foreach (['ERP_DEMO_ACTOR_ID' => (string) $seederActorId, 'ERP_DEMO_PRODUCTS' => '12', 'ERP_DEMO_CUSTOMERS' => '6',
    'ERP_DEMO_SUPPLIERS' => '6', 'ERP_DEMO_QUOTATIONS' => '6', 'ERP_DEMO_BRANCHES' => '2',
    'ERP_DEMO_USERS' => '4', 'ERP_DEMO_ORDERS' => '4'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$seedExit = Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EgyptMarketDemoSeeder', '--force' => true]);
if ($seedExit !== 0) {
    throw new RuntimeException('Synthetic demo seed failed; preserve the owned cluster and inspect private logs. '.Artisan::output());
}
$receipt['demo_seeded'] = true;
if (file_put_contents($receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
    throw new RuntimeException('Demo seed completed but its private bootstrap receipt could not be updated. Preserve the database and inspect the actor receipt.');
}
echo 'READY database=erp_elec_new_test port=49686 migrations=applied demo=EgyptMarketDemoSeeder actor='.$seederActorId.' nonce=verified'.PHP_EOL;
