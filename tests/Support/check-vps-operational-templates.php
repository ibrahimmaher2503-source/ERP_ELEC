<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        throw new RuntimeException("Missing operational template [{$relative}].");
    }

    return (string) file_get_contents($path);
};
$checks = [];
$check = static function (bool $condition, string $name) use (&$checks): void {
    $checks[] = ['check' => $name, 'result' => $condition ? 'PASS' : 'FAIL'];
};

$guard = $read('ops/vps/target-guard.sh');
$backup = $read('ops/vps/backup.sh');
$restore = $read('ops/vps/restore.sh');
$queue = $read('ops/vps/electrical-erp-queue.service');
$envExample = $read('.env.example');
$queueConfig = $read('config/queue.php');
$operation = $read('app/Modules/System/Application/DataOperations/ProcessOperation.php');

$check(
    str_contains($guard, 'current_database()') && str_contains($guard, 'current_user')
        && str_contains($guard, 'host(inet_server_addr())') && str_contains($guard, 'inet_server_port()')
        && str_contains($guard, "current_setting('data_directory')")
        && str_contains($guard, '[[ "$(cat "$ERP_TARGET_MARKER")" == "$ERP_TARGET_NONCE" ]]'),
    'Target guard binds the marker nonce and exact live PostgreSQL identity'
);
$check(
    str_contains($guard, '[[ -f "$PGPASSFILE" && ! -L "$PGPASSFILE"')
        && str_contains($guard, '$(stat -c %a "$PGPASSFILE")" == 600'),
    'Credential file must be a non-symlink regular file with mode 600'
);
$check(
    str_contains($backup, '[[ -d "$private" && ! -L "$private"')
        && str_contains($backup, 'find "$private" -type l -print -quit'),
    'Backup refuses a symlinked private-storage root and nested links'
);
$check(
    str_contains($backup, 'ERP_BACKUP_SIGNING_KEY')
        && str_contains($backup, '[[ -f "$ERP_BACKUP_SIGNING_KEY" && ! -L "$ERP_BACKUP_SIGNING_KEY"')
        && str_contains($backup, 'openssl pkeyutl -sign -rawin -inkey "$ERP_BACKUP_SIGNING_KEY" -in "$set_dir/SHA256SUMS" -out "$set_dir/SHA256SUMS.sig"')
        && strpos($backup, 'sha256sum database.dump.age private-files.tar.gz.age environment.env.age migrations.txt release-path.txt > SHA256SUMS')
            < strpos($backup, 'openssl pkeyutl -sign -rawin'),
    'Backup signs the completed fixed-file SHA256 manifest with a protected Ed25519 key'
);
$check(
    str_contains($restore, 'ERP_TARGET_PURPOSE:-}" == isolated-recovery')
        && str_contains($restore, 'ERP_EXPECTED_DB" == *_recovery')
        && str_contains($restore, '/srv/electrical-erp/recovery/*')
        && str_contains($restore, '[[ -f "$ERP_RECOVERY_ROOT/.erp-recovery-owned" && ! -L "$ERP_RECOVERY_ROOT/.erp-recovery-owned"')
        && str_contains($restore, '[[ "$(cat "$ERP_RECOVERY_ROOT/.erp-recovery-owned")" == "$ERP_TARGET_NONCE" ]]')
        && str_contains($restore, '[[ -f "$ERP_RECOVERY_KEY" && ! -L "$ERP_RECOVERY_KEY"'),
    'Restore requires an explicitly marked isolated recovery database and filesystem'
);
$check(
    str_contains($restore, '[[ -d "$ERP_BACKUP_SET" && ! -L "$ERP_BACKUP_SET" ]]'),
    'Restore backup-set directory is a non-symlink directory'
);
$check(
    str_contains($restore, 'tar -tzf "$scratch/private-files.tar.gz" | awk')
        && str_contains($restore, 'substr($0,1,1)!~/^[-d]$/')
        && str_contains($restore, '--no-same-owner --no-same-permissions'),
    'Restore rejects traversal, links and special archive entries before extraction'
);
$signatureCheck = strpos($restore, 'openssl pkeyutl -verify -rawin -pubin');
$decrypt = strpos($restore, 'age --decrypt --identity');
$restoreDb = strpos($restore, 'pg_restore --exit-on-error --single-transaction');
$extractFiles = strpos($restore, 'tar --extract --gzip');
$check(
    str_contains($restore, 'ERP_BACKUP_VERIFY_KEY_SHA256')
        && str_contains($restore, '[[ -f "$ERP_BACKUP_VERIFY_KEY" && ! -L "$ERP_BACKUP_VERIFY_KEY"')
        && str_contains($restore, 'sha256sum "$ERP_BACKUP_VERIFY_KEY"')
        && $signatureCheck !== false && $decrypt !== false && $restoreDb !== false && $extractFiles !== false
        && $signatureCheck < $decrypt && $signatureCheck < $restoreDb && $signatureCheck < $extractFiles,
    'Restore pins the trusted Ed25519 public key and verifies the signed manifest before decrypting or restoring'
);
$check(
    str_contains($restore, 'for name in database.dump.age private-files.tar.gz.age environment.env.age migrations.txt release-path.txt; do')
        && str_contains($restore, '[[ "$expected_hash" =~ ^[0-9a-f]{64}$')
        && str_contains($restore, '$(sha256sum "$name" | cut -d\' \' -f1)" == "$expected_hash"'),
    'Restore verifies every fixed manifest entry and its SHA256 before use'
);
$check(
    str_contains($restore, '[[ "$tables" == 0 ]]')
        && str_contains($restore, '[[ ! -e "$ERP_RECOVERY_ROOT/private" ]]')
        && str_contains($restore, 'pg_restore --exit-on-error --single-transaction'),
    'Restore refuses existing public tables/files and applies the database dump atomically'
);
$check(
    str_contains($operation, 'public int $timeout = 120;')
        && str_contains($queue, '--timeout=120') && str_contains($queue, 'TimeoutStopSec=150')
        && str_contains($queue, '--queue=data-operations,default')
        && preg_match('/DB_QUEUE_RETRY_AFTER=([0-9]+)/', $envExample, $dbRetry) === 1
        && preg_match('/REDIS_QUEUE_RETRY_AFTER=([0-9]+)/', $envExample, $redisRetry) === 1
        && preg_match("/'retry_after' => \(int\) env\('DB_QUEUE_RETRY_AFTER', ([0-9]+)\)/", $queueConfig, $dbDefault) === 1
        && preg_match("/'retry_after' => \(int\) env\('REDIS_QUEUE_RETRY_AFTER', ([0-9]+)\)/", $queueConfig, $redisDefault) === 1
        && (int) $dbRetry[1] > 120 && (int) $redisRetry[1] > 120
        && (int) $dbDefault[1] > 120 && (int) $redisDefault[1] > 120,
    'Queue includes data-operations and DB/Redis retry_after exceeds the 120-second job timeout'
);
$check(
    preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|age1[0-9a-z]{40,}|postgres(?:ql)?:\/\/[^\s:@]+:[^\s@]+@/i', $guard.$backup.$restore) === 0,
    'Operational scripts contain no embedded private keys, age identities or credentialed database URLs'
);

$failed = array_values(array_filter($checks, static fn (array $row): bool => $row['result'] !== 'PASS'));
fwrite(STDOUT, json_encode(['all_pass' => $failed === [], 'results' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
exit($failed === [] ? 0 : 1);
