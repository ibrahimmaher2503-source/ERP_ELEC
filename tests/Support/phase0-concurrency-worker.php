<?php

use App\Application\PostPurchaseReceipt;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_DATABASE=erp_elec_new_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
if (getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '55433') {
    throw new RuntimeException('ABORT: worker requires isolated restore port 55433.');
}
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storageRoot = getenv('PHASE0_STORAGE_ROOT');
if (! is_string($storageRoot) || $storageRoot === '') {
    throw new RuntimeException('PHASE0_STORAGE_ROOT is required.');
}
$app->useStoragePath($storageRoot);
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
$actualConnection = DB::selectOne('select current_database() as db, host(inet_server_addr()) as address, inet_server_port() as port');
if ($connection->getDatabaseName() !== 'erp_elec_new_test' || $connection->getDriverName() !== 'pgsql'
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.host') !== '127.0.0.1'
    || (string) config('database.connections.pgsql.port') !== '55433'
    || config('database.connections.pgsql.database') !== 'erp_elec_new_test'
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)
    || $actualConnection->db !== 'erp_elec_new_test'
    || $actualConnection->address !== '127.0.0.1'
    || (string) $actualConnection->port !== '55433') {
    throw new RuntimeException('ABORT: wrong database.');
}
$gate = $argv[max(0, $argc - 2)] ?? '';
$ready = $argv[max(0, $argc - 1)] ?? '';
if ($gate === '' || $ready === '') {
    throw new RuntimeException('ABORT: race barrier paths required.');
}
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 25;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($gate)) {
    throw new RuntimeException('Race barrier timed out.');
}
$action = $argv[1] ?? '';
$id = (int) ($argv[2] ?? 0);
$actor = (int) ($argv[3] ?? 0);
try {
    if ($action === 'receipt') {
        app(PostPurchaseReceipt::class)->execute($actor, $id, 2);
    } elseif ($action === 'issue') {
        app(PurchaseOrders::class)->issue($actor, $id);
    } elseif ($action === 'create_receipt') {
        $orderLine = (int) ($argv[4] ?? 0);
        $warehouse = (int) ($argv[5] ?? 0);
        $quantity = (string) ($argv[6] ?? '');
        $key = (string) ($argv[7] ?? '');
        $label = (string) ($argv[8] ?? '');
        $receiving = app(PurchaseReceiving::class);
        $receiptId = $receiving->save($actor, ['creation_key' => $key, 'order_id' => $id, 'warehouse_id' => $warehouse,
            'receipt_date' => '2026-10-27', 'delivery_reference' => $label, 'notes' => 'synthetic two-process race',
            'lines' => [['order_line_id' => $orderLine, 'quantity' => $quantity]]], null, 0);
        $receiving->submit($actor, $receiptId, 1);
        echo json_encode(['action' => $action, 'order_id' => $id, 'receipt_id' => $receiptId, 'status' => 'PASS']).PHP_EOL;
        exit(0);
    } else {
        throw new RuntimeException('Unsupported concurrency operation.');
    }
    echo json_encode(['action' => $action, 'id' => $id, 'status' => 'PASS']).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['action' => $action, 'id' => $id, 'status' => 'ERROR', 'class' => $error::class], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(2);
}
