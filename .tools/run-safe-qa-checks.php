<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

const SAFE_QA_CHECKS = [
    'check-default-avatars.php' => ['module' => 'M01', 'scenarios' => ['SC-M01-AVATAR-001']],
    'check-module-route-auth.php' => ['module' => 'M01-M04', 'scenarios' => ['SC-MODULE-AUTH-001']],
    'check-m01.php' => ['module' => 'M01', 'scenarios' => ['SC-M01-COMPANY-001', 'SC-M01-BRANCH-001', 'SC-M01-SCOPE-001']],
    'check-m01-contracts.php' => ['module' => 'M01', 'scenarios' => ['SC-M01-API-001']],
    'check-m01-users.php' => ['module' => 'M01', 'scenarios' => ['SC-M01-USER-001', 'SC-M01-USER-002', 'SC-M01-USER-003']],
    'check-m01-rbac.php' => ['module' => 'M01', 'scenarios' => ['SC-M01-RBAC-001', 'SC-M01-RBAC-002', 'SC-M01-RBAC-003', 'SC-M01-AUDIT-001']],
    'check-administrator-defaults.php' => ['module' => 'M01', 'scenarios' => ['SC-M01-RBAC-ADMIN-001']],
    'check-role-permission-propagation.php' => ['module' => 'M01', 'scenarios' => ['SC-M01-RBAC-002']],
    'check-demo-factories.php' => ['module' => 'M01/M02', 'scenarios' => ['SC-M01-DEMO-001', 'SC-M02-DEMO-001']],
    'check-m02.php' => ['module' => 'M02', 'scenarios' => ['SC-M02-CATALOG-001', 'SC-M02-VALIDATION-001', 'SC-M02-UNIT-001', 'SC-M02-MEDIA-001', 'SC-M02-SECURITY-001']],
    'check-data-operations.php' => ['module' => 'M02/M03', 'scenarios' => ['SC-SYS-BULK-001', 'SC-SYS-IMPORT-001', 'SC-SYS-EXPORT-001']],
    'check-operation-route-http.php' => ['module' => 'M02/M03', 'scenarios' => ['SC-SYS-ROUTE-001']],
    'check-egypt-market-demo.php' => ['module' => 'M01-M04', 'scenarios' => ['SC-SYS-DEMO-001']],
    'check-m03-customers.php' => ['module' => 'M03-A', 'scenarios' => ['SC-M03-CUSTOMER-001', 'SC-M03-CUSTOMER-002', 'SC-M03-CUSTOMER-003', 'SC-M03-CUSTOMER-004']],
    'check-supplier-factories.php' => ['module' => 'M03-B', 'scenarios' => ['SC-M03-SUPPLIER-001', 'SC-M03-SUPPLIER-002', 'SC-M03-SUPPLIER-003', 'SC-M03-SUPPLIER-004']],
    'check-party-primary-edit.php' => ['module' => 'M03', 'scenarios' => ['SC-M03-PARTY-PRIMARY-001']],
    'check-party-readonly-role.php' => ['module' => 'M01/M03-B', 'scenarios' => ['SC-M03-SUPPLIER-RBAC-001']],
    'check-m04-quotations.php' => ['module' => 'M04-Quotations', 'scenarios' => ['SC-Q-DRAFT-001', 'SC-Q-PRICE-001', 'SC-Q-DISCOUNT-001', 'SC-Q-APPROVAL-001', 'SC-Q-ISSUE-001', 'SC-Q-ACCEPT-001', 'SC-Q-CONVERT-001', 'SC-Q-DOC-001', 'SC-Q-XLSX-001']],
    'check-m04-sales-orders.php' => ['module' => 'M04-Sales Orders', 'scenarios' => ['SC-SO-DRAFT-001', 'SC-SO-DRAFT-002', 'SC-SO-IDEMPOTENCY-001', 'SC-SO-BRANCH-001', 'SC-SO-REVIEW-001', 'SC-SO-REVIEW-IMMUTABLE-001', 'SC-SO-POLICY-001', 'SC-SO-CANCEL-001', 'SC-SO-READINESS-001', 'SC-SO-EXPORT-001']],
    'check-sales-order-factories.php' => ['module' => 'M04-Sales Orders', 'scenarios' => ['SC-SO-FACTORY-001']],
    'check-qa-regressions.php' => ['module' => 'M02/System', 'scenarios' => ['SC-M02-STATUS-001', 'SC-SYS-CLEANUP-001']],
];

const EXCLUDED_CHECKS = [
    'check-local-administrator-defaults.php' => 'Uses the local erp_elec_new database.',
    'check-local-customer-access.php' => 'Uses the local erp_elec_new database and checks owner-specific local grants.',
    'check-local-operation-route.php' => 'Uses the local erp_elec_new database and local operation receipts.',
    'check-local-supplier-access.php' => 'Uses the local erp_elec_new database and owner-specific local grants.',
];

const EXPECTED_COMPLETION_MARKERS = [
    'check-default-avatars.php' => 'neutral.svg exists as a local SVG no larger than 10 KB',
    'check-module-route-auth.php' => 'Disposable module-route auth fixtures rolled back.',
    'check-m01.php' => 'Disposable test transaction rolled back.',
    'check-m01-contracts.php' => 'Disposable test transaction rolled back.',
    'check-m01-users.php' => 'user fixtures removed by rollback',
    'check-m01-rbac.php' => 'all RBAC fixtures removed by rollback',
    'check-administrator-defaults.php' => 'Administrator-default check transaction rolled back.',
    'check-role-permission-propagation.php' => 'Role permission propagation transaction rolled back.',
    'check-demo-factories.php' => 'Demo checks passed:',
    'check-m02.php' => 'Disposable M02 database transaction rolled back.',
    'check-data-operations.php' => 'Disposable data-operations database transaction rolled back.',
    'check-operation-route-http.php' => 'Disposable HTTP route fixtures rolled back.',
    'check-egypt-market-demo.php' => 'Egypt-market demo fixture transaction rolled back; generated private files cleaned up.',
    'check-m03-customers.php' => 'M03 customer focused checks passed.',
    'check-supplier-factories.php' => 'Supplier factory and demo persistence checks passed.',
    'check-party-primary-edit.php' => 'Customer and Supplier primary-edit regression checks passed.',
    'check-party-readonly-role.php' => 'Supplier read-only role checks passed.',
    'check-m04-quotations.php' => 'Disposable M04 quotation transaction rolled back.',
    'check-m04-sales-orders.php' => 'Sales Order checks passed:',
    'check-sales-order-factories.php' => 'Sales order demo seeder is repeat-safe, creates a draft-only order and null-priced line, and fabricates no review history',
    'check-qa-regressions.php' => 'Disposable QA regression transaction rolled back; fake disk reset.',
];

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$listOnly = in_array('--list', $arguments, true);
$verbose = in_array('--verbose', $arguments, true);
$unknownArguments = array_values(array_filter($arguments, static fn (string $argument): bool => ! in_array($argument, ['--list', '--verbose'], true)));

if ($unknownArguments !== []) {
    fwrite(STDERR, 'Unknown options: '.implode(', ', $unknownArguments)."\n");
    exit(2);
}

if ($listOnly) {
    foreach (SAFE_QA_CHECKS as $script => $metadata) {
        fwrite(STDOUT, "SAFE\t{$script}\t{$metadata['module']}\t".implode(',', $metadata['scenarios'])."\n");
    }
    foreach (EXCLUDED_CHECKS as $script => $reason) {
        fwrite(STDOUT, "EXCLUDED\t{$script}\t{$reason}\n");
    }
    exit(0);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
if (! app()->environment('testing') || $connection->getDriverName() !== 'pgsql'
    || $connection->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Safe QA runner requires APP_ENV=testing and the exact PostgreSQL database erp_elec_new_test.');
}
$connection->disconnect();

$childEnvironment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'erp_elec_new_test',
    'DB_URL' => null,
];
$runStarted = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$results = [];

foreach (SAFE_QA_CHECKS as $script => $metadata) {
    $repositoryEntryPoints = [
        'check-m04-sales-orders.php' => $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.$script,
    ];
    $path = $repositoryEntryPoints[$script] ?? $root.DIRECTORY_SEPARATOR.'.tools'.DIRECTORY_SEPARATOR.$script;
    if (! is_file($path)) {
        $results[] = [
            'script' => $script,
            'module' => $metadata['module'],
            'scenarios' => $metadata['scenarios'],
            'status' => 'FAIL',
            'exit_code' => null,
            'pass_assertions' => 0,
            'duration_seconds' => 0,
            'completion_marker' => EXPECTED_COMPLETION_MARKERS[$script] ?? null,
            'completion_detected' => false,
            'reason' => 'Whitelisted check file is missing.',
        ];

        continue;
    }

    $process = new Process([PHP_BINARY, $path], $root, $childEnvironment, null, 600);
    $startedAt = microtime(true);
    $process->run($verbose ? static function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    } : null);
    $stdout = $process->getOutput();
    $stderr = $process->getErrorOutput();
    $passLines = preg_grep('/^\s*PASS\b/m', preg_split('/\R/', $stdout) ?: []) ?: [];
    $summaryLines = array_values(array_filter(preg_split('/\R/', $stdout) ?: [], static fn (string $line): bool => preg_match('/\b(pass(?:ed)?|fail(?:ed)?)\b/i', $line) === 1));
    $summaryLines = array_map(static fn (string $line): string => mb_scrub($line, 'UTF-8'), $summaryLines);
    $combinedOutput = $stdout."\n".$stderr;
    $completionMarker = EXPECTED_COMPLETION_MARKERS[$script] ?? null;
    if ($completionMarker === null) {
        throw new RuntimeException("No expected completion marker is configured for whitelisted check {$script}.");
    }
    $completionDetected = str_contains($stdout, $completionMarker);
    $failureLines = array_values(array_filter(
        preg_split('/\R/', $combinedOutput) ?: [],
        static fn (string $line): bool => preg_match('/^\s*(?:FAIL\b|ERROR\b|FATAL\b|PHP (?:Fatal|Parse) error\b|Uncaught\b|In .+ line \d+:|[A-Za-z_\\\\]*Exception:)/i', $line) === 1,
    ));
    $failureLines = array_map(static fn (string $line): string => mb_scrub($line, 'UTF-8'), array_slice($failureLines, -8));
    $status = $process->getExitCode() !== 0 || $failureLines !== [] ? 'FAIL' : (! $completionDetected ? 'INCOMPLETE' : 'PASS');
    if ($status === 'INCOMPLETE') {
        $failureLines[] = 'Runner: expected completion marker was not observed.';
    }

    $results[] = [
        'script' => $script,
        'module' => $metadata['module'],
        'scenarios' => $metadata['scenarios'],
        'status' => $status,
        'exit_code' => $process->getExitCode(),
        'pass_assertions' => count($passLines),
        'stdout_line_count' => count(preg_split('/\R/', trim($stdout)) ?: []),
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
        'completion_marker' => $completionMarker,
        'completion_detected' => $completionDetected,
        'summary_lines' => $summaryLines,
        'failure_summary' => $failureLines,
        'output_sha256' => hash('sha256', $combinedOutput),
    ];
}

$runFinished = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$passCount = count(array_filter($results, static fn (array $result): bool => $result['status'] === 'PASS'));
$failCount = count(array_filter($results, static fn (array $result): bool => $result['status'] === 'FAIL'));
$incompleteCount = count(array_filter($results, static fn (array $result): bool => $result['status'] === 'INCOMPLETE'));
$assertionCount = array_sum(array_column($results, 'pass_assertions'));
$evidenceDirectory = $root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'qa'.DIRECTORY_SEPARATOR.'evidence';
if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0775, true) && ! is_dir($evidenceDirectory)) {
    throw new RuntimeException('Could not create the QA evidence directory.');
}
$evidencePath = $evidenceDirectory.DIRECTORY_SEPARATOR.'safe-check-run-'.$runStarted->format('Ymd\THis\Z').'.json';
$report = [
    'run_started_utc' => $runStarted->format(DATE_ATOM),
    'run_finished_utc' => $runFinished->format(DATE_ATOM),
    'environment' => ['app_env' => 'testing', 'database_driver' => 'pgsql', 'database' => 'erp_elec_new_test'],
    'checks_total' => count($results),
    'checks_passed' => $passCount,
    'checks_failed' => $failCount,
    'checks_incomplete' => $incompleteCount,
    'explicit_pass_assertions' => $assertionCount,
    'excluded_local_checks' => EXCLUDED_CHECKS,
    'results' => $results,
];
file_put_contents($evidencePath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

fwrite(STDOUT, "Safe QA checks: {$passCount}/".count($results)." scripts passed; {$failCount} failed; {$incompleteCount} incomplete; {$assertionCount} explicit PASS assertions.\n");
foreach ($results as $result) {
    fwrite(STDOUT, sprintf("%s\t%s\texit=%s\tPASS=%d\t%.3fs\n", $result['status'], $result['script'], $result['exit_code'] === null ? 'missing' : (string) $result['exit_code'], $result['pass_assertions'], $result['duration_seconds']));
}
fwrite(STDOUT, 'Evidence: '.substr($evidencePath, strlen($root) + 1)."\n");

exit($failCount === 0 && $incompleteCount === 0 ? 0 : 1);
