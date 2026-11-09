<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$alignUploadedTempTimes = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && str_ends_with($path, '/upload-file');
$file = $root.'/public'.(str_starts_with($path, '/') ? $path : '/'.$path);
if ($path !== '/' && is_file($file)) {
    return false;
}

$expectedPort = '55475';
$storageRoot = str_replace('\\', '/', (string) getenv('LARAVEL_STORAGE_PATH'));
if (getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== $expectedPort
    || getenv('PHASE5_WORKSHOP_DB_PORT') !== $expectedPort || getenv('DB_DATABASE') !== 'erp_elec_new_test'
    || getenv('DB_USERNAME') !== 'postgres' || getenv('DB_URL') !== ''
    || getenv('PHASE5_WORKSHOP_TEST_NOW') !== '2027-10-30T12:00:00+02:00'
    || ! str_contains($storageRoot, '/.tmp/m09-browser-20261031/storage-')) {
    http_response_code(403);
    exit('Guard rejected M09 acceptance router configuration.');
}

try {
    $pdo = new PDO('pgsql:host=127.0.0.1;port='.$expectedPort.';dbname=erp_elec_new_test', 'postgres', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port')->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== 'erp_elec_new_test' || ! in_array($identity['address'], ['127.0.0.1', '127.0.0.1/32'], true)
        || (string) $identity['port'] !== $expectedPort) {
        http_response_code(403);
        exit('Guard rejected nonmatching M09 PostgreSQL identity.');
    }
} catch (Throwable) {
    http_response_code(503);
    exit('Guard could not verify the isolated M09 PostgreSQL identity.');
}

require $root.'/vendor/autoload.php';
$testNow = '2027-10-30T12:00:00+02:00';
$isolatedStorage = (string) getenv('LARAVEL_STORAGE_PATH');
$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $isolatedStorage;
\Carbon\Carbon::setTestNow($testNow);
\Carbon\CarbonImmutable::setTestNow($testNow);
\Illuminate\Support\Carbon::setTestNow($testNow);

$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($isolatedStorage);
$app->handleRequest(\Illuminate\Http\Request::capture());
if ($alignUploadedTempTimes && http_response_code() < 400) {
    $virtualTime = strtotime($testNow);
    foreach (glob($isolatedStorage.'/app/private/livewire-tmp/*') ?: [] as $temporaryUpload) {
        if (is_file($temporaryUpload)) { touch($temporaryUpload, $virtualTime); }
    }
}
