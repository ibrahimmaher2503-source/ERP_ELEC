<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
$allowed = realpath($root.'/.tmp/page-speed-audit/after');
$input = isset($argv[1]) ? realpath($argv[1]) : false;
$output = isset($argv[2]) ? $argv[2] : '';
$outputDir = $output !== '' ? realpath(dirname($output)) : false;
if (!$allowed || !$input || !$outputDir || !str_starts_with($input, $allowed.DIRECTORY_SEPARATOR) || !str_starts_with($outputDir, $allowed.DIRECTORY_SEPARATOR)
    || !is_file($input) || basename($output) !== 'actual-query-plans-private.json') {
    fwrite(STDERR, "Refusing EXPLAIN outside the private after-run workspace.\n");
    exit(2);
}
$markerPath = $root.'/.tmp/page-speed-audit/postgres-55461/codex-owned.json';
$marker = is_file($markerPath) ? json_decode((string) file_get_contents($markerPath), true) : null;
if (!is_array($marker) || ($marker['kind'] ?? null) !== 'codex-e2e-postgres' || ($marker['repo'] ?? null) !== $root
    || ($marker['port'] ?? null) !== 55461 || ($marker['token'] ?? null) !== getenv('E2E_CLUSTER_TOKEN') || strlen((string) getenv('E2E_CLUSTER_TOKEN')) < 32) {
    fwrite(STDERR, "Refusing EXPLAIN: PostgreSQL ownership marker/token mismatch.\n");
    exit(3);
}

$candidates = json_decode((string) file_get_contents($input), true, flags: JSON_THROW_ON_ERROR)['candidates'] ?? [];
if (!is_array($candidates) || count($candidates) > 32) {
    fwrite(STDERR, "Refusing malformed or oversized EXPLAIN candidate set.\n");
    exit(2);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
$identity = $connection->selectOne("select current_database() as database, current_setting('port') as port, current_setting('data_directory') as data_directory");
if (($identity->database ?? null) !== 'erp_elec_new_test' || (string) ($identity->port ?? '') !== '55461'
    || realpath((string) ($identity->data_directory ?? '')) !== realpath($root.'/.tmp/page-speed-audit/postgres-55461')) {
    fwrite(STDERR, "Refusing EXPLAIN: active PostgreSQL identity does not match the owned canonical test cluster.\n");
    exit(3);
}

$results = [];
$connection->beginTransaction();
try {
    $connection->statement('SET TRANSACTION READ ONLY');
    $connection->statement("SET LOCAL statement_timeout = '3000ms'");
    foreach ($candidates as $candidate) {
        $sql = trim((string) ($candidate['sql'] ?? ''));
        $sql = preg_replace('/^(?:(?:\s+)|(?:--[^\r\n]*(?:\r?\n|$))|(?:\/\*[\s\S]*?\*\/))+/', '', $sql) ?? '';
        $params = $candidate['params'] ?? [];
        $row = [
            'module' => $candidate['module'] ?? 'Unknown',
            'route_case' => $candidate['route_case'] ?? null,
            'statement_index' => $candidate['statement_index'] ?? null,
            'duration_ms' => $candidate['duration_ms'] ?? null,
            'sql' => $candidate['sql'] ?? null,
            'params' => $params,
            'status' => 'skipped',
        ];
        if (!preg_match('/^(?:select|with)\b/i', $sql) || str_contains($sql, ';') || !is_array($params) || substr_count($sql, '?') !== count($params)) {
            $row['error_code'] = 'not_a_single_read_statement';
            $results[] = $row;
            continue;
        }
        try {
            $connection->statement('SAVEPOINT explain_candidate');
            $plan = $connection->select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, array_values($params));
            $row['status'] = 'explained';
            $row['plan'] = json_decode((string) ($plan[0]->{'QUERY PLAN'} ?? 'null'), true, flags: JSON_THROW_ON_ERROR);
            $connection->statement('RELEASE SAVEPOINT explain_candidate');
        } catch (Throwable $error) {
            try { $connection->statement('ROLLBACK TO SAVEPOINT explain_candidate'); $connection->statement('RELEASE SAVEPOINT explain_candidate'); } catch (Throwable) {}
            $row['status'] = 'failed';
            $row['error_code'] = $error instanceof \Illuminate\Database\QueryException
                ? (string) ($error->errorInfo[0] ?? 'database_error')
                : 'explain_error';
        }
        $results[] = $row;
    }
} finally {
    $connection->rollBack();
}

$payload = ['generated_at' => gmdate(DATE_ATOM), 'mode' => 'EXPLAIN ANALYZE with BUFFERS; no writes; read-only transaction; 3 second statement timeout', 'results' => $results];
file_put_contents($output, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
@chmod($output, 0600);
fwrite(STDOUT, json_encode(['status' => 'complete', 'candidates' => count($results), 'explained' => count(array_filter($results, fn ($row) => $row['status'] === 'explained'))])."\n");
