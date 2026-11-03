<?php

use App\Modules\Projects\Application\ProjectMaterials;
use App\Modules\Projects\Application\ProjectPlanning;
use App\Modules\Projects\Application\ProjectSources;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
$storage = $root.'/.tmp/phase2-concurrency-storage';
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();

[$script, $operation, $payloadJson, $gate, $ready] = array_pad($argv, 5, null);
$payload = is_string($payloadJson) ? json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR) : null;
if (! is_string($operation) || ! is_array($payload) || ! is_string($gate) || ! is_string($ready)) {
    fwrite(STDERR, "invalid worker arguments\n");
    exit(2);
}

$connection = DB::connection();
$actual = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port, pg_backend_pid() as pid');
if ($connection->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql'
    || $connection->getDatabaseName() !== 'erp_elec_new_test' || config('database.connections.pgsql.database') !== 'erp_elec_new_test'
    || config('database.connections.pgsql.host') !== '127.0.0.1' || (string) config('database.connections.pgsql.port') !== '55437'
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true) || $actual->db !== 'erp_elec_new_test'
    || ! in_array($actual->address, ['127.0.0.1', '127.0.0.1/32'], true) || (string) $actual->port !== '55437') {
    fwrite(STDERR, "ABORT: worker resolved an unexpected PostgreSQL target\n");
    exit(3);
}

file_put_contents($ready, json_encode(['pid' => getmypid(), 'pg_backend_pid' => (int) $actual->pid], JSON_THROW_ON_ERROR), LOCK_EX);
$deadline = microtime(true) + 30;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($gate)) {
    fwrite(STDERR, "barrier timeout\n");
    exit(4);
}

try {
    $result = match ($operation) {
        'approve_budget' => app(ProjectPlanning::class)->decide((int) $payload['actor_id'], (int) $payload['project_id'],
            (int) $payload['version'], (int) $payload['change_id'], true, 'Concurrent synthetic M07 baseline acceptance.'),
        'reserve_material' => app(ProjectMaterials::class)->reserve((int) $payload['actor_id'], (int) $payload['project_id'],
            (int) $payload['requirement_id'], (int) $payload['warehouse_id'], (string) $payload['quantity'], (string) $payload['key']),
        'link_source' => app(ProjectSources::class)->link((int) $payload['actor_id'], (int) $payload['project_id'],
            (string) $payload['source_type'], (int) $payload['source_id']),
        'close_project' => app(ProjectPlanning::class)->transition((int) $payload['actor_id'], (int) $payload['project_id'],
            (int) $payload['version'], 'closed', 'Concurrent synthetic M07 close acceptance.'),
        default => throw new RuntimeException('Unsupported worker operation.'),
    };
    echo json_encode(['outcome' => 'passed', 'operation' => $operation, 'pid' => getmypid(), 'pg_backend_pid' => (int) $actual->pid,
        'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['outcome' => 'rejected', 'operation' => $operation, 'pid' => getmypid(), 'pg_backend_pid' => (int) $actual->pid,
        'class' => $error::class, 'code' => $error->getCode(), 'message' => mb_substr($error->getMessage(), 0, 350)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
}
