<?php

use App\Modules\Accounting\Application\FinancialReports;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
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
$storage = $root.'/.tmp/phase1-supplier-invoice-storage';
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();

[$script, $operation, $payloadJson, $gate, $ready] = array_pad($argv, 5, null);
try {
    $payload = is_string($payloadJson) ? json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR) : null;
} catch (Throwable) {
    $payload = null;
}
if (! is_string($operation) || ! is_array($payload) || ! filter_var($payload['actor_id'] ?? null, FILTER_VALIDATE_INT)
    || ! is_string($gate) || ! is_string($ready)) {
    fwrite(STDERR, "invalid worker arguments\n");
    exit(2);
}

$connection = DB::connection();
$actual = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if ($connection->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql'
    || $connection->getDatabaseName() !== 'erp_elec_new_test' || config('database.connections.pgsql.database') !== 'erp_elec_new_test'
    || config('database.connections.pgsql.host') !== '127.0.0.1' || ! in_array((string) config('database.connections.pgsql.port'), ['5443', '55442'], true)
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true) || $actual->db !== 'erp_elec_new_test'
    || ! in_array($actual->address, ['127.0.0.1', '127.0.0.1/32'], true) || ! in_array((string) $actual->port, ['5443', '55442'], true)) {
    fwrite(STDERR, "ABORT: worker resolved an unexpected database\n");
    exit(3);
}

file_put_contents($ready, (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 30;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($gate)) {
    fwrite(STDERR, "barrier timeout\n");
    exit(4);
}

$actor = (int) $payload['actor_id'];
try {
    $result = match ($operation) {
        'post_supplier_invoice' => app(ReceivablesPayablesApi::class)->postSupplierInvoice($actor, (int) $payload['invoice_id'], (int) $payload['version']),
        'create_supplier_invoice' => ['invoice_id' => app(ReceivablesPayablesApi::class)->createSupplierInvoice($actor, $payload['data'])],
        'match_supplier_invoice' => app(ReceivablesPayablesApi::class)->matchSupplierInvoice($actor, (int) $payload['invoice_id'], (int) $payload['version']),
        'create_customer_invoice' => ['invoice_id' => app(ReceivablesPayablesApi::class)->createCustomerInvoice($actor, $payload['data'])],
        'create_credit_note' => ['credit_note_id' => app(ReceivablesPayablesApi::class)->createCreditNote($actor, $payload['data'])],
        'post_customer_invoice' => app(ReceivablesPayablesApi::class)->postCustomerInvoice($actor, (int) $payload['invoice_id'], (int) $payload['version']),
        'execute_supplier_payment' => app(TreasuryApi::class)->executeSupplierPayment($actor, (int) $payload['payment_id'], (int) $payload['version'],
            (string) $payload['idempotency_key'], $payload['allocations']),
        'post_credit_note' => app(ReceivablesPayablesApi::class)->postCreditNote($actor, (int) $payload['credit_note_id']),
        'close_period' => ['closed' => app(FinancialReports::class)->closePeriod($actor, (int) $payload['period_id'])],
        default => throw new RuntimeException('Unsupported worker operation.'),
    };
    echo json_encode(['outcome' => 'passed', 'operation' => $operation, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['outcome' => 'rejected', 'operation' => $operation, 'class' => $error::class,
        'code' => $error->getCode(), 'message' => mb_substr($error->getMessage(), 0, 350)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
