<?php

declare(strict_types=1);

use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceBilling;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\FieldMaintenance\Application\MaintenanceParts;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

[$script,$mode,$json,$gate,$ready] = $argv;
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$root = dirname(__DIR__, 2);
$port = getenv('PHASE3_FINANCIAL_PORT') ?: '55441';
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=pgsql');
putenv('DB_URL=');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
Carbon::setTestNow(CarbonImmutable::parse($data['posting_date'], 'Africa/Cairo')->startOfDay()->addHours(12));
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => (int) $port, 'database.connections.pgsql.database' => 'erp_elec_new_test', 'database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => '', 'database.connections.pgsql.url' => null]);
DB::purge('pgsql');
$c = DB::selectOne('select current_database() db, inet_server_addr()::text address, inet_server_port() port');
if ($port !== '55441' || $c->db !== 'erp_elec_new_test' || ! in_array($c->address, ['127.0.0.1', '127.0.0.1/32'], true) || (string) $c->port !== '55441') {
    fwrite(STDERR, "ABORT wrong database\n");
    exit(2);
}
file_put_contents($ready, 'ready', LOCK_EX);
$deadline = microtime(true) + 30;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($gate)) {
    fwrite(STDERR, "ABORT barrier timeout\n");
    exit(3);
}
DB::beginTransaction();
try {
    $result = match ($mode) {
        'issue' => app(MaintenanceParts::class)->issue((int) $data['actor'], (int) $data['work'], (int) $data['reservation'], $data['quantity'], $data['key']),
        'reserve' => app(MaintenanceParts::class)->reserve((int) $data['actor'], (int) $data['work'], (int) $data['requirement'], $data['quantity'], $data['key']),
        'assign' => app(MaintenanceExecution::class)->assign((int) $data['actor'], (int) $data['work'], (int) $data['version'], ['technician_id' => (int) $data['technician_id'], 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'replace_id' => (int) $data['replace_id'], 'override_reason' => $data['override_reason'] ?? null]),
        'service_invoice' => app(MaintenanceBilling::class)->request((int) $data['actor'], (int) $data['work'], $data['key'], $data['invoice_date'], $data['due_date']),
        'state' => app(MaintenanceActions::class)->state((int) $data['actor'], (int) $data['work'], (int) $data['version'], $data['action'], $data['reason']),
        default => throw new RuntimeException('Unknown worker mode '.$mode),
    };
    DB::commit();
    echo json_encode(['status' => 'accepted', 'result' => $result], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : ($e instanceof ValidationException ? 422 : null);
    if ($status !== null) {
        echo json_encode(['status' => 'rejected', 'http_status' => $status, 'error_class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n");
    exit(1);
}
