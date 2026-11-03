<?php

declare(strict_types=1);

// Private router for the nonce-owned finance browser fixture. Never route requests to another database or storage path.
use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel;

$root = realpath(dirname(__DIR__, 2));
$public = realpath(($root ?: '').'/public');
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$staticPath = $public === false ? false : realpath($public.DIRECTORY_SEPARATOR.ltrim($uri, '/\\'));
if ($uri !== '/' && $staticPath !== false && is_file($staticPath)
    && str_starts_with($staticPath, rtrim((string) $public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
    return false;
}

$fail = static function (string $message): never {
    error_log('Gate2 finance UI router refused request: '.$message);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Private finance acceptance router refused this request.';
    exit;
};

$expectedPrivate = $root === false ? false : realpath($root.'/.tmp/master-remediation-20261101/ui-private');
$financeRoot = $expectedPrivate === false ? false : realpath($expectedPrivate.'/finance');
$cluster = $root === false ? false : realpath($root.'/.tmp/master-remediation-20261101/ui-pgdata');
$markerPath = $expectedPrivate === false ? false : realpath($expectedPrivate.'/.codex-ui-owned.txt');
$receiptPath = $financeRoot === false ? false : realpath($financeRoot.'/gate2-finance-ui-bootstrap.json');
$storagePath = $financeRoot === false ? false : realpath($financeRoot.'/storage');
$expectedPrivatePath = $root === false ? false : $root.DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.'master-remediation-20261101'.DIRECTORY_SEPARATOR.'ui-private';
$marker = 'master-remediation-20261101 Gate2 UI private PostgreSQL marker';
$nonce = (string) (getenv('E2E_FINANCE_DB_NONCE') ?: '');
$database = (string) (getenv('DB_DATABASE') ?: '');
$receipt = $receiptPath !== false && is_file($receiptPath)
    ? json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR) : null;

if ($root === false || $public === false || $expectedPrivate !== $expectedPrivatePath || $financeRoot === false
    || $cluster === false || $markerPath !== $expectedPrivate.DIRECTORY_SEPARATOR.'.codex-ui-owned.txt'
    || $receiptPath !== $financeRoot.DIRECTORY_SEPARATOR.'gate2-finance-ui-bootstrap.json'
    || $storagePath === false || ! str_starts_with($storagePath, $financeRoot.DIRECTORY_SEPARATOR)
    || trim((string) file_get_contents((string) $markerPath)) !== $marker
    || ! is_array($receipt) || ! preg_match('/^[a-f0-9]{64}$/D', $nonce)
    || $database !== 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32)
    || ($receipt['database'] ?? null) !== $database || ! hash_equals((string) ($receipt['ownership_nonce'] ?? ''), $nonce)
    || (int) ($receipt['port'] ?? 0) !== 49686 || realpath((string) ($receipt['data_directory'] ?? '')) !== $cluster
    || ($receipt['app_key'] ?? null) !== getenv('APP_KEY')
    || getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '49686'
    || getenv('E2E_DB_PORT') !== '49686' || getenv('DB_USERNAME') !== 'postgres' || (getenv('DB_PASSWORD') ?: '') !== ''
    || (getenv('DB_URL') ?: '') !== '') {
    $fail('private marker, receipt, nonce, database environment, or storage path did not match.');
}

try {
    $pdo = new PDO('pgsql:host=127.0.0.1;port=49686;dbname='.$database, 'postgres', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("select current_database() as database, host(inet_server_addr()) as server_address,
        inet_server_port() as port, current_setting('data_directory') as data_directory, current_user as current_user,
        current_setting('server_version_num') as server_version_num,
        (select r.rolname from pg_database d join pg_roles r on r.oid=d.datdba where d.datname=current_database()) as database_owner")
        ->fetch(PDO::FETCH_ASSOC);
    $receiptQuery = $pdo->prepare('select nonce from codex_test_guard.ownership_receipts where database = :database');
    $receiptQuery->execute(['database' => $database]);
    $databaseNonce = $receiptQuery->fetchColumn();
} catch (Throwable $error) {
    $fail('the nonce-owned PostgreSQL target could not be verified.');
}

if (! is_array($identity) || ($identity['database'] ?? null) !== $database || ($identity['server_address'] ?? null) !== '127.0.0.1'
    || (int) ($identity['port'] ?? 0) !== 49686 || ($identity['current_user'] ?? null) !== 'postgres'
    || ($identity['database_owner'] ?? null) !== 'postgres' || (int) ($identity['server_version_num'] ?? 0) < 170000
    || (int) ($identity['server_version_num'] ?? 0) >= 180000 || realpath((string) ($identity['data_directory'] ?? '')) !== $cluster
    || ! is_string($databaseNonce) || ! hash_equals($nonce, trim($databaseNonce))) {
    $fail('live PostgreSQL identity did not match the private Gate2 target.');
}

define('LARAVEL_START', microtime(true));
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storagePath);
$uploadRootPath = $financeRoot.'/storage/app/private/livewire-browser-uploads';
if (! is_dir($uploadRootPath) && ! mkdir($uploadRootPath, 0700, true) && ! is_dir($uploadRootPath)) {
    $fail('the private Livewire upload directory could not be created.');
}
$uploadRoot = realpath($uploadRootPath);
if ($uploadRoot === false || ! str_starts_with($uploadRoot, $financeRoot.DIRECTORY_SEPARATOR)) {
    $fail('the Livewire upload directory resolved outside private finance storage.');
}
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.tmp-for-tests' => [
    'driver' => 'local',
    'root' => $uploadRoot,
    'visibility' => 'private',
    'throw' => true,
]]);
$app->handleRequest(Request::capture());
