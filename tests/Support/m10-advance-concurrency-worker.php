<?php

declare(strict_types=1);

use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$root = dirname(__DIR__, 2);
$database = $argv[1] ?? '';
$receiptPath = $argv[2] ?? '';
$actor = (int) ($argv[3] ?? 0);
$advance = (int) ($argv[4] ?? 0);
$openItem = (int) ($argv[5] ?? 0);
$amount = (string) ($argv[6] ?? '');
$date = (string) ($argv[7] ?? '');
$mode = (string) ($argv[8] ?? '');
$key = (string) ($argv[9] ?? '');
$account = (int) ($argv[10] ?? 0);
$reference = (string) ($argv[11] ?? '');
$gate = $argv[12] ?? '';
$ready = $argv[13] ?? '';
$receipt = is_file($receiptPath) ? json_decode((string) file_get_contents($receiptPath), true) : null;
if (! preg_match('/^erp_elec_new_m10_advance_[a-f0-9]{16}$/', $database) || ! is_array($receipt)
    || ($receipt['database'] ?? null) !== $database || ! preg_match('/^[a-f0-9]{64}$/', (string) ($receipt['nonce'] ?? ''))
    || realpath(dirname($receiptPath)) !== realpath($root.'/.tmp/master-remediation-20261101/m10-advance-'.$receipt['nonce'])
    || ! in_array($mode, ['application', 'refund'], true) || $actor < 1 || $advance < 1 || $openItem < 1 || $account < 1 || $gate === '' || $ready === '') {
    throw new RuntimeException('M10 advance worker private disposable database receipt or inputs are invalid.');
}
putenv('APP_ENV=testing'); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => (string) ($receipt['connection_host'] ?? '127.0.0.1'),
    'database.connections.pgsql.port' => (int) ($receipt['port'] ?? 0), 'database.connections.pgsql.database' => $database,
    'database.connections.pgsql.username' => (string) ($receipt['current_user'] ?? ''),
    'database.connections.pgsql.password' => (string) (getenv('DB_PASSWORD') ?: ''), 'database.connections.pgsql.url' => null]);
DB::purge('pgsql');
$live = DB::selectOne("select current_database() as database,host(inet_server_addr()) as server_address,inet_server_port() as port,
    current_setting('data_directory') as data_directory,current_user as current_user,current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner,
    (select nonce from codex_test_guard.ownership_receipts where database=current_database()) as nonce");
if ($live->database !== $database || $live->server_address !== ($receipt['server_address'] ?? null)
    || (int) $live->port !== (int) ($receipt['port'] ?? 0) || $live->current_user !== ($receipt['current_user'] ?? null)
    || $live->database_owner !== ($receipt['current_user'] ?? null) || realpath($live->data_directory) !== ($receipt['data_directory'] ?? null)
    || (int) $live->server_version_num < 170000 || (int) $live->server_version_num >= 180000
    || ! hash_equals((string) $receipt['nonce'], (string) $live->nonce)) {
    throw new RuntimeException('M10 advance worker live database identity or private nonce mismatch.');
}
file_put_contents($ready, json_encode(['pg_backend_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid, 'mode' => $mode], JSON_THROW_ON_ERROR), LOCK_EX);
$deadline = microtime(true) + 35;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($gate)) {
    throw new RuntimeException('M10 advance race barrier timed out.');
}
try {
    if ($mode === 'application') {
        $result = app(ReceivablesPayablesApi::class)->applyCustomerAdvance($actor, $advance, $key, $date,
            [['open_item_id' => $openItem, 'amount' => $amount]]);
    } else {
        $result = app(TreasuryApi::class)->refundCustomerAdvance($actor, $advance, $account, $amount, $date, $key, $reference);
    }
    echo json_encode(['status' => 'PASS', 'mode' => $mode, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $code = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ValidationException ? 422 : null);
    echo json_encode(['status' => $code === 422 ? 'REJECTED' : 'ERROR', 'mode' => $mode, 'code' => $code, 'class' => $error::class], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($code === 422 ? 2 : 1);
}
