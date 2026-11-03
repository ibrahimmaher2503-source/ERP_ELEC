<?php

$root = dirname(__DIR__, 2);
$env = static fn (string $key): string => (string) (getenv($key) ?: '');
if ($env('GITHUB_ACTIONS') !== 'true' || $env('APP_ENV') !== 'testing'
    || $env('DB_CONNECTION') !== 'pgsql' || $env('DB_HOST') !== '127.0.0.1'
    || $env('DB_PORT') !== '5432' || $env('DB_DATABASE') !== 'erp_elec_new_test'
    || $env('DB_USERNAME') !== 'erp_ci') {
    throw new RuntimeException('Refusing CI PostgreSQL receipt setup outside the fixed hosted test target.');
}

try {
    $pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=erp_elec_new_test', 'erp_ci', $env('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port, current_user, current_setting('data_directory') as data_directory, current_setting('server_version_num')::int as server_version_num, (select pg_get_userbyid(datdba) from pg_database where datname = current_database()) as database_owner")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable) {
    throw new RuntimeException('Unable to verify the hosted CI PostgreSQL test target.');
}

$serverOctets = filter_var((string) $identity['server_address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
    ? [] : array_map('intval', explode('.', (string) $identity['server_address']));
$privateServerAddress = count($serverOctets) === 4 && ($serverOctets[0] === 127 || $serverOctets[0] === 10
    || ($serverOctets[0] === 172 && $serverOctets[1] >= 16 && $serverOctets[1] <= 31)
    || ($serverOctets[0] === 192 && $serverOctets[1] === 168));
if ($identity['database'] !== 'erp_elec_new_test' || ! $privateServerAddress
    || (int) $identity['port'] !== 5432 || $identity['current_user'] !== 'erp_ci'
    || $identity['database_owner'] !== 'erp_ci' || (int) $identity['server_version_num'] < 170000
    || (int) $identity['server_version_num'] >= 180000 || $identity['data_directory'] === '') {
    throw new RuntimeException('Refusing CI PostgreSQL that does not match the owned PostgreSQL 17 test database.');
}

$nonce = bin2hex(random_bytes(32));
try {
    $pdo->exec('create schema if not exists codex_test_guard');
    $pdo->exec('create table if not exists codex_test_guard.ownership_receipts (nonce char(64) primary key, created_at timestamptz not null default now())');
    $pdo->exec('delete from codex_test_guard.ownership_receipts');
    $statement = $pdo->prepare('insert into codex_test_guard.ownership_receipts (nonce) values (?)');
    $statement->execute([$nonce]);
} catch (Throwable) {
    throw new RuntimeException('Unable to record the hosted CI PostgreSQL ownership receipt.');
}

$receipt = $identity + ['connection_host' => '127.0.0.1', 'port' => 5432, 'nonce' => $nonce];
$directory = $root.'/.tmp/ci-postgres';
if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    throw new RuntimeException('Unable to create private CI receipt directory.');
}
$receiptPath = $directory.'/ownership.json';
if (file_put_contents($receiptPath, json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n", LOCK_EX) === false) {
    throw new RuntimeException('Unable to write private CI ownership receipt.');
}
chmod($receiptPath, 0600);
fwrite(STDOUT, "Verified disposable PostgreSQL 17 CI test target.\n");
