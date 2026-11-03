<?php

use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
$port = $argv[4] ?? '';
if ($port !== '55444') {
    throw new RuntimeException('ABORT: M08 worker requires isolated PostgreSQL port 55444.');
}
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT='.$port);
putenv('DB_DATABASE=erp_elec_new_test');
putenv('DB_USERNAME=postgres');
putenv('DB_PASSWORD=');
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = $port;
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
$_ENV['DB_USERNAME'] = $_SERVER['DB_USERNAME'] = 'postgres';
$_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = '';
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => (int) $port,
    'database.connections.pgsql.database' => 'erp_elec_new_test', 'database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => '', 'database.connections.pgsql.url' => null]);
DB::purge('pgsql');
$db = DB::selectOne('select current_database() db,host(inet_server_addr()) address,inet_server_port() port');
if (DB::connection()->getDriverName() !== 'pgsql' || $db->db !== 'erp_elec_new_test' || $db->address !== '127.0.0.1' || (string) $db->port !== $port) {
    throw new RuntimeException('ABORT: wrong M08 worker database.');
}
$payload = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
$gate = $argv[2] ?? '';
$ready = $argv[3] ?? '';
if ($gate === '' || $ready === '') {
    throw new RuntimeException('ABORT: race barrier paths required.');
}
file_put_contents($ready, json_encode(['pg_backend_pid' => (int) DB::selectOne('select pg_backend_pid() pid')->pid], JSON_THROW_ON_ERROR), LOCK_EX);
$deadline = microtime(true) + 35;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(10000);
}if (! is_file($gate)) {
    throw new RuntimeException('M08 race barrier timed out.');
}
try {
    if (($payload['operation'] ?? '') === 'convert') {
        $id = app(MaintenanceActions::class)->createWorkOrder((int) $payload['actor'], (int) $payload['request'], (int) $payload['version'], $payload['key'], $payload['input']);
        echo json_encode(['status' => 'PASS', 'work_order_id' => $id], JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif (($payload['operation'] ?? '') === 'assign') {
        $id = app(MaintenanceExecution::class)->assign((int) $payload['actor'], (int) $payload['work'], 1, ['technician_id' => (int) $payload['technician']] + $payload['input']);
        echo json_encode(['status' => 'PASS', 'assignment_id' => $id], JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        throw new RuntimeException('Unsupported M08 race operation.');
    }
} catch (Throwable $error) {
    $code = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : null;
    echo json_encode(['status' => $code === 422 ? 'REJECTED' : 'ERROR', 'code' => $code, 'class' => $error::class], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($code === 422 ? 2 : 1);
}
