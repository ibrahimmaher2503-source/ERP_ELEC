<?php

/**
 * Shared assertions for the opt-in Gate 1 historical-financial chronology probes.
 * Call only after normal owner fixtures and permissions have been established.
 */
final class Gate1FinancialHistory
{
    public static function assertOwnedDatabase(string $root): array
    {
        $root = realpath($root) ?: $root;
        $connection = DB::connection();
        $environment = [
            'APP_ENV' => app()->environment(),
            'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
        ];
        if ($connection->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql'
            || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)) {
            throw new RuntimeException('ABORT before writes: Gate1 history checks require the selected PostgreSQL connection without a URL override.');
        }

        if ((int) config('database.connections.pgsql.port') === 5432) {
            require_once dirname(__DIR__).'/Support/OptimizationRegressionDatabaseGuard.php';
            $target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $environment);
            $identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address,
                inet_server_port() as port, current_setting('data_directory') as data_directory,
                current_user as current_user, current_setting('server_version_num') as server_version_num,
                (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
            $receipt = DB::selectOne('select nonce from codex_test_guard.ownership_receipts');
            Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, (object) [
                'database' => $identity->database, 'server_address' => $identity->server_address, 'port' => $identity->port,
                'current_user' => $identity->current_user, 'database_owner' => $identity->database_owner,
                'server_version_num' => $identity->server_version_num, 'data_directory' => $identity->data_directory,
            ], $receipt);
            $actualData = realpath((string) $identity->data_directory) ?: (string) $identity->data_directory;

            return ['database' => $identity->database, 'address' => $identity->server_address, 'connection_host' => $environment['DB_HOST'],
                'server_address' => $identity->server_address,
                'port' => (int) $identity->port, 'current_user' => $identity->current_user,
                'database_owner' => $identity->database_owner, 'server_version_num' => (int) $identity->server_version_num,
                'data_directory' => $actualData, 'marker_verified' => false, 'ci_receipt_verified' => true];
        }

        $markerPath = $root.'/.tmp/master-remediation-20261101/pgdata/.codex-audit-owned.txt';
        $expectedMarker = 'master-remediation-20261101 Gate1 private PostgreSQL marker';
        $marker = is_file($markerPath) ? trim((string) file_get_contents($markerPath)) : '';
        $identity = DB::selectOne("select current_database() as db, host(inet_server_addr()) as address, inet_server_port() as port, current_setting('data_directory') as data_directory");
        $expectedData = realpath($root.'/.tmp/master-remediation-20261101/pgdata');
        $actualData = realpath((string) $identity->data_directory) ?: (string) $identity->data_directory;

        if ($marker !== $expectedMarker || ! $expectedData || $actualData !== $expectedData
            || $identity->db !== 'erp_elec_new_test' || ! in_array($identity->address, ['127.0.0.1', '127.0.0.1/32'], true)
            || (int) $identity->port !== 49685 || (int) config('database.connections.pgsql.port') !== 49685
            || config('database.connections.pgsql.host') !== '127.0.0.1'
            || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)) {
            throw new RuntimeException('ABORT before writes: Gate1 history probe requires its exact marked private PostgreSQL identity or a valid hosted CI ownership receipt.');
        }

        return ['database' => $identity->db, 'address' => $identity->address, 'port' => (int) $identity->port,
            'data_directory' => $actualData, 'marker_verified' => true];
    }

    public static function snapshot(int $actor, int $branch, string $to, bool $withReports = true): array
    {
        $branches = [$branch];
        $rows = $withReports ? app(App\Modules\Accounting\Application\FinancialReports::class)->reconciliations($actor, $branch, $to) : [];
        $inventory = app(App\Modules\Inventory\Application\Contracts\InventoryApi::class)->financialValue($actor, $branches, $to);
        $transit = app(App\Modules\Inventory\Application\Contracts\InventoryApi::class)->transitValue($actor, $branches, $to);
        $grni = app(App\Modules\Purchasing\Application\Contracts\PurchasingApi::class)->grniValue($actor, $branches, $to);
        $subledger = app(App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi::class)->reconciliationValues($actor, $branches, $to);
        $treasury = app(App\Modules\Treasury\Application\Contracts\TreasuryApi::class)->reconciliationValues($actor, $branches, $to);
        if ($withReports) {
            $keys = array_column($rows, 'key');
            $required = ['ap', 'ar', 'grni', 'inventory', 'inventory_transit', 'bank', 'cash'];
            $failed = array_values(array_map(static fn (array $row): array => [
                'key' => $row['key'], 'gl' => (string) $row['gl'], 'source' => (string) $row['source'], 'difference' => (string) $row['difference'],
            ], array_filter($rows, static fn (array $row): bool => ! ($row['ok'] ?? false))));
            if (array_diff($required, $keys) !== [] || $failed !== []) {
                throw new RuntimeException('As-of GL/subledger reconciliation is incomplete or mismatched at '.$to.': '.json_encode([
                    'missing_keys' => array_values(array_diff($required, $keys)), 'failed_keys' => $failed,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
        }

        return [
            'cutoff' => $to,
            'inventory_fifo' => bcadd((string) $inventory, '0', 2),
            'inventory_transit' => bcadd((string) $transit, '0', 2),
            'grni' => bcadd((string) $grni, '0', 2),
            'receivables_payables' => array_map(static fn ($value): string => bcadd((string) $value, '0', 2), $subledger),
            'treasury' => array_map(static fn ($value): string => bcadd((string) $value, '0', 2), $treasury),
            'treasury_movements' => app(App\Modules\Treasury\Application\Contracts\TreasuryApi::class)->reconciliationMovements($actor, $branches, $to),
            'reconciliations' => array_map(static fn (array $row): array => [
                'key' => $row['key'], 'gl' => (string) $row['gl'], 'source' => (string) $row['source'],
                'difference' => (string) $row['difference'], 'ok' => (bool) $row['ok'],
            ], $rows),
        ];
    }

    public static function sameCutoff(array $before, array $after, string $label): void
    {
        if ($before !== $after) {
            $changed = [];
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
                if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                    $changed[$key] = ['before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
                }
            }
            throw new RuntimeException($label.' changed an earlier as-of snapshot: '.json_encode([
                'cutoff' => $before['cutoff'] ?? null, 'changed_fields' => array_keys($changed), 'values' => $changed,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
    }

    public static function reconciliationKeys(array $snapshot): array
    {
        return array_column($snapshot['reconciliations'], 'key');
    }

    public static function writeEvidence(string $root, string $name, array $evidence): void
    {
        $runId = getenv('GATE1_CI_RUN_ID');
        $validCiRunId = is_string($runId) && preg_match('/^[a-f0-9]{32}$/i', $runId) === 1;
        $directory = $validCiRunId ? $root.'/.tmp/ci-postgres/artifacts' : $root.'/docs/release/remediation/evidence/gate1/historical';
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create Gate1 historical evidence directory.');
        }
        if ($validCiRunId) {
            $evidence['ci_run_id'] = strtolower($runId);
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $basename = pathinfo($name, PATHINFO_FILENAME);
            $name = $basename.'-'.strtolower($runId).($extension === '' ? '' : '.'.$extension);
        }
        $path = $directory.'/'.$name;
        file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    }
}
