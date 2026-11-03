<?php

namespace Tests\Support;

use RuntimeException;

final class OptimizationRegressionDatabaseGuard
{
    public static function target(string $root, array $environment): array
    {
        if (($environment['APP_ENV'] ?? '') !== 'testing') {
            throw new RuntimeException('Refusing optimization regression checks outside APP_ENV=testing.');
        }

        $root = realpath($root) ?: $root;
        $port = (int) (($environment['E2E_DB_PORT'] ?? '') ?: ($environment['DB_PORT'] ?? 0));

        if ($port === 55467) {
            $cluster = $root.'/.tmp/forms-ux-20261102/pgdata';
            $markerPath = $root.'/.tmp/forms-ux-20261102/private/.codex-forms-ux-owned.json';
            $marker = is_file($markerPath) ? json_decode((string) file_get_contents($markerPath), true) : null;
            $nonce = (string) ($environment['E2E_CLUSTER_TOKEN'] ?? '');
            $expectedCluster = realpath($cluster);
            if (! is_array($marker) || ($marker['kind'] ?? null) !== 'codex-forms-ux-private-pg'
                || ($marker['repo'] ?? null) !== $root || (int) ($marker['port'] ?? 0) !== $port
                || ($marker['database'] ?? null) !== 'erp_elec_new_test'
                || ($environment['DB_HOST'] ?? '') !== '127.0.0.1'
                || ($environment['DB_DATABASE'] ?? '') !== 'erp_elec_new_test'
                || ($environment['DB_USERNAME'] ?? '') !== 'postgres'
                || realpath((string) ($environment['E2E_CLUSTER_MARKER'] ?? '')) !== realpath($markerPath)
                || ! preg_match('/^[a-f0-9]{64}$/D', $nonce)
                || ! hash_equals((string) ($marker['nonce'] ?? ''), $nonce)
                || $expectedCluster === false || realpath((string) ($marker['data_directory'] ?? '')) !== $expectedCluster
                || realpath($markerPath) !== realpath($root.'/.tmp/forms-ux-20261102/private/.codex-forms-ux-owned.json')) {
                throw new RuntimeException('Refusing form UX checks without the matching private PostgreSQL cluster marker and nonce.');
            }

            return self::makeTarget($root, '127.0.0.1', '127.0.0.1', $port, 'erp_elec_new_test', 'postgres', $cluster, $markerPath, $nonce);
        }

        if ($port === 55460 || $port === 55462) {
            $cluster = $port === 55460 ? $root.'/.tmp/e2e-automation/postgres' : $root.'/.tmp/ui-acceptance/postgres-55462';
            $expectedMarkerPath = $cluster.'/codex-owned.json';
            $markerPath = (string) (($environment['E2E_CLUSTER_MARKER'] ?? '') ?: $expectedMarkerPath);
            $kind = $port === 55460 ? 'codex-e2e-postgres' : 'codex-ui-acceptance';
            $marker = is_file($markerPath) ? json_decode((string) file_get_contents($markerPath), true) : null;
            $tokenMatches = is_array($marker) && ($port === 55462
                ? (($environment['E2E_CLUSTER_TOKEN'] ?? '') !== '' && hash_equals((string) ($marker['token'] ?? ''), (string) $environment['E2E_CLUSTER_TOKEN']))
                : is_string($marker['token'] ?? null) && $marker['token'] !== '');

            if (! is_array($marker) || ($marker['kind'] ?? null) !== $kind
                || ($marker['repo'] ?? null) !== $root || (int) ($marker['port'] ?? 0) !== $port
                || ($marker['database'] ?? 'erp_elec_new_test') !== 'erp_elec_new_test'
                || realpath($markerPath) !== realpath($expectedMarkerPath) || ! $tokenMatches) {
                throw new RuntimeException('Refusing optimization regression checks without a matching explicit isolated PostgreSQL ownership marker.');
            }

            return self::makeTarget($root, '127.0.0.1', '127.0.0.1', $port, 'erp_elec_new_test', 'erp_e2e', $cluster, $markerPath, $marker['token'] ?? null);
        }

        if ($port === 49685) {
            $cluster = $root.'/.tmp/master-remediation-20261101/pgdata';
            $markerPath = $cluster.'/.codex-audit-owned.txt';
            if (($environment['DB_HOST'] ?? '127.0.0.1') !== '127.0.0.1' || ! is_file($markerPath)
                || realpath($markerPath) !== realpath($cluster.'/.codex-audit-owned.txt')
                || trim((string) file_get_contents($markerPath)) !== 'master-remediation-20261101 Gate1 private PostgreSQL marker') {
                throw new RuntimeException('Refusing optimization regression checks without the exact Gate1 private PostgreSQL marker.');
            }

            return self::makeTarget($root, '127.0.0.1', '127.0.0.1', $port, 'erp_elec_new_test', 'postgres', $cluster, $markerPath);
        }

        if ($port === 49686) {
            $cluster = $root.'/.tmp/master-remediation-20261101/ui-pgdata';
            $markerPath = $root.'/.tmp/master-remediation-20261101/ui-private/.codex-ui-owned.txt';
            $privateFiles = realpath(dirname($markerPath));
            $resolvedRoot = realpath($root);
            $resolvedCluster = realpath($cluster);
            $resolvedMarker = realpath($markerPath);
            if ($resolvedRoot === false || $resolvedCluster === false || $privateFiles === false
                || ! str_starts_with($resolvedCluster, rtrim($resolvedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
                || $privateFiles !== $resolvedRoot.DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.'master-remediation-20261101'.DIRECTORY_SEPARATOR.'ui-private'
                || $resolvedMarker !== $privateFiles.DIRECTORY_SEPARATOR.'.codex-ui-owned.txt') {
                throw new RuntimeException('Refusing Gate2 UI PostgreSQL path unless the resolved cluster and private marker stay inside this checkout.');
            }
            $database = (string) ($environment['DB_DATABASE'] ?? '');
            $nonce = null;
            if ($database !== 'erp_elec_new_test') {
                $financeNonce = (string) ($environment['E2E_FINANCE_DB_NONCE'] ?? '');
                if (! preg_match('/^erp_elec_new_finance_ui_([a-f0-9]{32})$/D', $database, $matches)
                    || ! preg_match('/^[a-f0-9]{64}$/D', $financeNonce)
                    || ! hash_equals(substr($financeNonce, 0, 32), $matches[1])) {
                    throw new RuntimeException('Refusing Gate2 finance UI database without its matching private 64-character nonce.');
                }
                $nonce = $financeNonce;
            }
            if (($environment['DB_HOST'] ?? '127.0.0.1') !== '127.0.0.1'
                || ($environment['DB_USERNAME'] ?? '') !== 'postgres'
                || ! is_file($markerPath)
                || realpath($markerPath) !== realpath($root.'/.tmp/master-remediation-20261101/ui-private/.codex-ui-owned.txt')
                || trim((string) file_get_contents($markerPath)) !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker') {
                throw new RuntimeException('Refusing Gate2 UI acceptance without the exact private PostgreSQL marker and target.');
            }

            return self::makeTarget($root, '127.0.0.1', '127.0.0.1', $port, $database, 'postgres', $resolvedCluster, $markerPath, $nonce);
        }

        if ($port === 5432) {
            $receiptPath = $root.'/.tmp/ci-postgres/ownership.json';
            $receipt = is_file($receiptPath) ? json_decode((string) file_get_contents($receiptPath), true) : null;
            if (($environment['GITHUB_ACTIONS'] ?? '') !== 'true'
                || ($environment['DB_HOST'] ?? '') !== '127.0.0.1'
                || ($environment['DB_DATABASE'] ?? '') !== 'erp_elec_new_test'
                || ($environment['DB_USERNAME'] ?? '') !== 'erp_ci'
                || ! is_array($receipt) || ($receipt['database'] ?? null) !== 'erp_elec_new_test'
                || ($receipt['connection_host'] ?? null) !== '127.0.0.1'
                || ! self::isAllowedCiServerAddress((string) ($receipt['server_address'] ?? ''))
                || (int) ($receipt['port'] ?? 0) !== 5432
                || ($receipt['current_user'] ?? null) !== 'erp_ci'
                || ($receipt['database_owner'] ?? null) !== 'erp_ci'
                || ($receipt['server_version_num'] ?? null) < 170000 || ($receipt['server_version_num'] ?? null) >= 180000
                || ! is_string($receipt['data_directory'] ?? null) || $receipt['data_directory'] === ''
                || ! is_string($receipt['nonce'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $receipt['nonce'])) {
                throw new RuntimeException('Refusing hosted CI PostgreSQL without a matching local database ownership receipt.');
            }

            return self::makeTarget($root, '127.0.0.1', $receipt['server_address'], 5432, 'erp_elec_new_test', 'erp_ci', null, $receiptPath, $receipt['nonce'], $receipt);
        }

        throw new RuntimeException('Refusing optimization regression checks outside explicitly owned isolated PostgreSQL targets.');
    }

    public static function verify(array $target, object $identity, ?object $receiptRow = null): void
    {
        self::verifyIdentity($target, $identity);

        if ($target['ci_receipt'] !== null) {
            foreach (['database', 'server_address', 'port', 'current_user', 'database_owner', 'server_version_num', 'data_directory'] as $field) {
                $actual = $identity->{$field} ?? null;
                if ($field === 'data_directory') {
                    $actual = realpath((string) ($identity->data_directory ?? '')) ?: (string) ($identity->data_directory ?? '');
                } elseif ($field === 'port' || $field === 'server_version_num') {
                    $actual = (int) $actual;
                }
                if ((string) $actual !== (string) ($target['ci_receipt'][$field] ?? null)) {
                    throw new RuntimeException("Refusing hosted CI PostgreSQL: [{$field}] differs from its ownership receipt.");
                }
            }
            if ($receiptRow === null || ! hash_equals((string) $target['nonce'], (string) ($receiptRow->nonce ?? ''))) {
                throw new RuntimeException('Refusing hosted CI PostgreSQL without the matching in-database ownership nonce.');
            }
        }

        if ($target['ci_receipt'] === null && is_string($target['nonce'] ?? null)) {
            if ($receiptRow === null || ! hash_equals((string) $target['nonce'], (string) ($receiptRow->nonce ?? ''))) {
                throw new RuntimeException('Refusing Gate2 finance UI database without its matching in-database ownership nonce.');
            }
        }
    }

    public static function verifyIdentity(array $target, object $identity): void
    {
        $actualDirectory = realpath((string) ($identity->data_directory ?? '')) ?: (string) ($identity->data_directory ?? '');
        $expectedDirectory = $target['data_directory'] === null ? null : (realpath($target['data_directory']) ?: $target['data_directory']);
        if (($identity->database ?? null) !== $target['database']
            || ($identity->server_address ?? null) !== $target['server_address'] || (int) ($identity->port ?? 0) !== $target['port']
            || ($identity->current_user ?? null) !== $target['username']
            || ($expectedDirectory !== null && $actualDirectory !== $expectedDirectory)) {
            throw new RuntimeException('Refusing optimization regression fixture writes outside the selected owned PostgreSQL cluster.');
        }
    }

    private static function isAllowedCiServerAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $octets = array_map('intval', explode('.', $address));

        return $octets[0] === 127 || $octets[0] === 10
            || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
            || ($octets[0] === 192 && $octets[1] === 168);
    }

    private static function makeTarget(string $root, string $connectionHost, string $serverAddress, int $port, string $database, string $username, ?string $dataDirectory, string $markerPath, mixed $nonce = null, ?array $ciReceipt = null): array
    {
        return compact('root', 'port', 'database', 'username', 'markerPath') + [
            'connection_host' => $connectionHost,
            'server_address' => $serverAddress,
            'data_directory' => $dataDirectory,
            'nonce' => $nonce,
            'ci_receipt' => $ciReceipt,
        ];
    }
}
