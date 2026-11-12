<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 3);
require_once $root.'/vendor/autoload.php';
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';

$environment = [];
foreach (['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'E2E_DB_PORT', 'E2E_CLUSTER_MARKER', 'E2E_CLUSTER_TOKEN'] as $key) {
    $environment[$key] = (string) (getenv($key) ?: '');
}
$target = OptimizationRegressionDatabaseGuard::target($root, $environment);
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = getenv('LARAVEL_STORAGE_PATH');
if (! is_string($storage) || $storage === '') {
    throw new RuntimeException('Owned check runtime storage path is missing.');
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address,
    current_setting('port')::integer as port, current_user as current_user,
    current_setting('data_directory') as data_directory");
$receipt = DB::table('codex_test_guard.ownership_receipts')
    ->where('database', DB::connection()->getDatabaseName())
    ->first(['nonce']);
OptimizationRegressionDatabaseGuard::verify($target, $identity, $receipt);

fwrite(STDOUT, "OWNED target verified: PostgreSQL 127.0.0.1:55467/erp_elec_new_test\n");
