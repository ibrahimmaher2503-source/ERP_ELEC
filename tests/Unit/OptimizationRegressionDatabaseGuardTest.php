<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\OptimizationRegressionDatabaseGuard;

class OptimizationRegressionDatabaseGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/optimization-guard-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function test_gate_one_requires_exact_private_cluster_marker_and_target(): void
    {
        $cluster = $this->root.'/.tmp/master-remediation-20261101/pgdata';
        mkdir($cluster, 0700, true);
        file_put_contents($cluster.'/.codex-audit-owned.txt', 'master-remediation-20261101 Gate1 private PostgreSQL marker');

        $target = OptimizationRegressionDatabaseGuard::target($this->root, $this->environment(49685, [
            'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'postgres',
        ]));

        $this->assertSame(49685, $target['port']);
        $this->assertSame('postgres', $target['username']);
        $this->assertSame('127.0.0.1', $target['connection_host']);
        $this->assertSame('127.0.0.1', $target['server_address']);
        $this->assertSame(realpath($cluster), realpath($target['data_directory']));
    }

    public function test_gate_one_rejects_alternate_marker_path_and_wrong_marker_text(): void
    {
        $cluster = $this->root.'/.tmp/master-remediation-20261101/pgdata';
        mkdir($cluster, 0700, true);
        file_put_contents($cluster.'/other-marker.txt', 'master-remediation-20261101 Gate1 private PostgreSQL marker');
        $environment = $this->environment(49685, ['DB_HOST' => '127.0.0.1']);

        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $environment));
        file_put_contents($cluster.'/.codex-audit-owned.txt', 'wrong marker');
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $environment));
    }

    public function test_gate_one_rejects_non_loopback_host_and_wrong_database(): void
    {
        $cluster = $this->root.'/.tmp/master-remediation-20261101/pgdata';
        mkdir($cluster, 0700, true);
        file_put_contents($cluster.'/.codex-audit-owned.txt', 'master-remediation-20261101 Gate1 private PostgreSQL marker');

        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $this->environment(49685, ['DB_HOST' => 'db.internal'])));

        $target = OptimizationRegressionDatabaseGuard::target($this->root, $this->environment(49685, ['DB_HOST' => '127.0.0.1']));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::verify($target, $this->identity(['database' => 'erp_app'])));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::verify($target, $this->identity(['port' => 5443])));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::verify($target, $this->identity(['data_directory' => $this->root])));
    }

    public function test_gate_two_ui_requires_exact_private_cluster_marker_and_target(): void
    {
        $cluster = $this->root.'/.tmp/master-remediation-20261101/ui-pgdata';
        $privateFiles = $this->root.'/.tmp/master-remediation-20261101/ui-private';
        mkdir($cluster, 0700, true);
        mkdir($privateFiles, 0700, true);
        $marker = 'master-remediation-20261101 Gate2 UI private PostgreSQL marker';
        file_put_contents($privateFiles.'/.codex-ui-owned.txt', $marker);
        $environment = $this->environment(49686, ['E2E_DB_PORT' => '', 'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'postgres']);

        $target = OptimizationRegressionDatabaseGuard::target($this->root, $environment);
        $this->assertSame(49686, $target['port']);
        $this->assertSame('postgres', $target['username']);
        $this->assertSame(realpath($cluster), realpath($target['data_directory']));
        OptimizationRegressionDatabaseGuard::verify($target, $this->identity([
            'port' => 49686, 'data_directory' => realpath($cluster),
        ]));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::verify($target, $this->identity([
            'port' => 49685, 'data_directory' => realpath($cluster),
        ])));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, array_replace($environment, ['DB_USERNAME' => 'erp_e2e'])));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, array_replace($environment, ['DB_DATABASE' => 'erp_development'])));
        file_put_contents($privateFiles.'/.codex-ui-owned.txt', 'wrong marker');
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $environment));
    }

    public function test_forms_ux_private_cluster_requires_matching_path_target_and_database_nonce(): void
    {
        $cluster = $this->root.'/.tmp/forms-ux-20261102/pgdata';
        $private = $this->root.'/.tmp/forms-ux-20261102/private';
        mkdir($cluster, 0700, true);
        mkdir($private, 0700, true);
        $nonce = bin2hex(random_bytes(32));
        $markerPath = $private.'/.codex-forms-ux-owned.json';
        file_put_contents($markerPath, json_encode([
            'kind' => 'codex-forms-ux-private-pg', 'repo' => realpath($this->root), 'port' => 55467,
            'database' => 'erp_elec_new_test', 'data_directory' => realpath($cluster), 'nonce' => $nonce,
        ], JSON_THROW_ON_ERROR));
        $environment = $this->environment(55467, ['DB_HOST' => '127.0.0.1', 'DB_USERNAME' => 'postgres',
            'E2E_CLUSTER_MARKER' => $markerPath, 'E2E_CLUSTER_TOKEN' => $nonce]);

        $target = OptimizationRegressionDatabaseGuard::target($this->root, $environment);
        $this->assertSame(55467, $target['port']);
        $this->assertSame(realpath($cluster), realpath($target['data_directory']));
        OptimizationRegressionDatabaseGuard::verify($target, $this->identity([
            'port' => 55467, 'data_directory' => realpath($cluster),
        ]), (object) ['nonce' => $nonce]);

        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, array_replace($environment, ['E2E_CLUSTER_TOKEN' => str_repeat('0', 64)])));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, array_replace($environment, ['DB_HOST' => 'localhost'])));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::verify($target, $this->identity([
            'port' => 55467, 'data_directory' => realpath($cluster),
        ]), (object) ['nonce' => str_repeat('0', 64)]));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::verify($target, $this->identity([
            'port' => 55467, 'data_directory' => $this->root,
        ]), (object) ['nonce' => $nonce]));
    }

    public function test_legacy_owned_markers_keep_their_exact_port_kind_repo_and_token_checks(): void
    {
        $cluster = $this->root.'/.tmp/e2e-automation/postgres';
        mkdir($cluster, 0700, true);
        file_put_contents($cluster.'/codex-owned.json', json_encode([
            'kind' => 'codex-e2e-postgres', 'repo' => realpath($this->root), 'port' => 55460,
            'database' => 'erp_elec_new_test', 'token' => 'legacy-token',
        ]));

        $target = OptimizationRegressionDatabaseGuard::target($this->root, $this->environment(55460, ['E2E_DB_PORT' => '']));
        $this->assertSame('erp_e2e', $target['username']);
        $this->assertSame(55460, $target['port']);
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $this->environment(55460, [
            'E2E_CLUSTER_MARKER' => $cluster.'/alternate.json',
        ])));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $this->environment(55461)));
    }

    public function test_ui_acceptance_marker_still_requires_matching_secret_token(): void
    {
        $cluster = $this->root.'/.tmp/ui-acceptance/postgres-55462';
        mkdir($cluster, 0700, true);
        file_put_contents($cluster.'/codex-owned.json', json_encode([
            'kind' => 'codex-ui-acceptance', 'repo' => realpath($this->root), 'port' => 55462,
            'database' => 'erp_elec_new_test', 'token' => 'expected-token',
        ]));

        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $this->environment(55462)));
        $target = OptimizationRegressionDatabaseGuard::target($this->root, $this->environment(55462, ['E2E_CLUSTER_TOKEN' => 'expected-token']));
        $this->assertSame(55462, $target['port']);
        $this->assertSame('erp_e2e', $target['username']);
    }

    public function test_ci_flags_alone_and_mismatched_receipts_never_authorize_a_target(): void
    {
        $environment = $this->ciEnvironment();
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $environment));

        $this->writeCiReceipt(['current_user' => 'postgres']);
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $environment));
        $this->writeCiReceipt(['current_user' => 'erp_ci', 'database_owner' => 'erp_ci', 'server_version_num' => 160000]);
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $environment));
        $this->writeCiReceipt(['current_user' => 'erp_ci', 'database_owner' => 'erp_ci', 'server_version_num' => 170006, 'nonce' => 'bad']);
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $environment));

        $this->writeCiReceipt();
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $this->ciEnvironmentWith(['GITHUB_ACTIONS' => 'false'])));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $this->ciEnvironmentWith(['DB_DATABASE' => 'erp_development'])));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $this->ciEnvironmentWith(['DB_USERNAME' => 'postgres'])));
    }

    public function test_ci_receipt_requires_matching_server_identity_and_database_nonce(): void
    {
        $dataDirectory = $this->root.'/pgdata';
        mkdir($dataDirectory, 0700, true);
        $receipt = $this->writeCiReceipt(['data_directory' => realpath($dataDirectory), 'server_address' => '172.18.0.2']);
        $target = OptimizationRegressionDatabaseGuard::target($this->root, $this->ciEnvironment());
        $identity = $this->identity([
            'database' => 'erp_elec_new_test', 'server_address' => '172.18.0.2', 'port' => 5432, 'current_user' => 'erp_ci',
            'database_owner' => 'erp_ci', 'server_version_num' => 170006, 'data_directory' => realpath($dataDirectory),
        ]);

        OptimizationRegressionDatabaseGuard::verify($target, $identity, (object) ['nonce' => $receipt['nonce']]);
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::verify($target, $identity, (object) ['nonce' => str_repeat('0', 64)]));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::verify($target, $this->identity([
            'database' => 'erp_elec_new_test', 'server_address' => '10.0.0.3', 'port' => 5432, 'current_user' => 'erp_ci',
            'database_owner' => 'erp_ci', 'server_version_num' => 170006, 'data_directory' => realpath($dataDirectory),
        ]), (object) ['nonce' => $receipt['nonce']]));
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $this->ciEnvironmentWith(['DB_HOST' => '172.18.0.2'])));
        $this->writeCiReceipt(['data_directory' => realpath($dataDirectory), 'server_address' => '203.0.113.5']);
        $this->assertRefused(fn () => OptimizationRegressionDatabaseGuard::target($this->root, $this->ciEnvironment()));
    }

    private function environment(int $port, array $overrides = []): array
    {
        return array_replace([
            'APP_ENV' => 'testing', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $port,
            'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'postgres', 'E2E_DB_PORT' => (string) $port,
        ], $overrides);
    }

    private function ciEnvironment(): array
    {
        return ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => 'true', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'erp_ci'];
    }

    private function ciEnvironmentWith(array $overrides): array
    {
        return array_replace($this->ciEnvironment(), $overrides);
    }

    private function writeCiReceipt(array $overrides = []): array
    {
        $receipt = array_replace([
            'database' => 'erp_elec_new_test', 'connection_host' => '127.0.0.1', 'port' => 5432,
            'connection_host' => '127.0.0.1', 'server_address' => '127.0.0.1', 'current_user' => 'erp_ci', 'database_owner' => 'erp_ci', 'server_version_num' => 170006,
            'data_directory' => '/var/lib/postgresql/data', 'nonce' => bin2hex(random_bytes(32)),
        ], $overrides);
        $path = $this->root.'/.tmp/ci-postgres';
        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }
        file_put_contents($path.'/ownership.json', json_encode($receipt));

        return $receipt;
    }

    private function identity(array $overrides = []): object
    {
        return (object) array_replace([
            'database' => 'erp_elec_new_test', 'server_address' => '127.0.0.1', 'port' => 49685,
            'current_user' => 'postgres', 'database_owner' => 'postgres', 'server_version_num' => 170006,
            'data_directory' => $this->root.'/.tmp/master-remediation-20261101/pgdata',
        ], $overrides);
    }

    private function assertRefused(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the ownership guard to refuse this target.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }
}
