<?php

declare(strict_types=1);

use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$requiredEnvironment = [
    'GITHUB_ACTIONS' => 'true',
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '5432',
    'DB_DATABASE' => 'erp_elec_new_test',
    'DB_USERNAME' => 'erp_ci',
];
foreach ($requiredEnvironment as $key => $value) {
    if (getenv($key) !== $value) {
        throw new RuntimeException("Refusing Gate1 CI runner: required hosted PostgreSQL setting [{$key}] does not match.");
    }
}

$processEnvironment = array_replace(getenv(), $requiredEnvironment, [
    'DB_URL' => '',
    'E2E_DB_PORT' => '',
    'E2E_CLUSTER_MARKER' => '',
    'E2E_CLUSTER_TOKEN' => '',
    'GATE1_TARGET_PATH' => '',
    'GATE1_HISTORY_CHECK' => '',
    'GATE1_FINANCIAL_RISK_CHECK' => '',
    'GATE1_RACE_ORDER' => '',
    'GATE1_DATABASE' => '',
    'PHASE1_COMMIT_RACE_FIXTURES' => '',
    'GATE1_MAINTENANCE_HISTORY_CHECK' => '',
]);
$runId = bin2hex(random_bytes(16));
$processEnvironment['GATE1_CI_RUN_ID'] = $runId;
$php = PHP_BINARY;
$currentStep = 'CI ownership receipt bootstrap';
$passedSteps = [];
$raceTeardowns = [];
$childProcesses = [];
$summaryPath = $root.'/.tmp/ci-postgres/artifacts/gate1-summary.json';
$privateLogDirectory = $root.'/.tmp/ci-postgres/private/'.$runId;
$summaryTarget = null;

$writeSummary = static function (string $status, ?string $errorClass = null) use (&$passedSteps, &$raceTeardowns, &$childProcesses, $summaryPath, &$summaryTarget, &$currentStep, $runId): void {
    $directory = dirname($summaryPath);
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Gate1 CI cannot create its curated summary directory.');
    }
    $summary = [
        'run_id' => $runId,
        'result' => $status,
        'failed_step' => $status === 'FAIL' ? $currentStep : null,
        'error_class' => $errorClass,
        'target' => $summaryTarget,
        'steps' => $passedSteps,
        'race_fixture_teardown' => $raceTeardowns,
        'child_processes' => $childProcesses,
        'concurrency_fixture_policy' => 'Committed only on the nonce-owned ephemeral CI database for two-process races; reset with verified migrate:fresh after each order.',
    ];
    if (file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Gate1 CI cannot write its curated summary.');
    }
    chmod($summaryPath, 0600);
};

$recordPass = static function (string $label) use (&$passedSteps, $writeSummary): void {
    $passedSteps[] = ['check' => $label, 'status' => 'PASS'];
    $writeSummary('IN_PROGRESS');
};

$runProcess = static function (array $command, array $overrides, string $label, callable $assertOutput) use ($root, $processEnvironment, $privateLogDirectory, &$childProcesses, $writeSummary, &$currentStep): array {
    $startedAt = microtime(true);
    $safeLabel = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $label) ?: 'child';
    $logStem = sprintf('%02d-%s', count($childProcesses) + 1, trim($safeLabel, '-'));
    $stdoutPath = $privateLogDirectory.'/'.$logStem.'.stdout.log';
    $stderrPath = $privateLogDirectory.'/'.$logStem.'.stderr.log';
    $ensurePrivateLogs = static function () use ($privateLogDirectory): void {
        if (! is_dir($privateLogDirectory) && ! mkdir($privateLogDirectory, 0700, true) && ! is_dir($privateLogDirectory)) {
            throw new RuntimeException('Gate1 CI could not create its private child-log directory.');
        }
        chmod($privateLogDirectory, 0700);
    };
    $redact = static function (string $text) use ($processEnvironment, $overrides): string {
        foreach (array_replace($processEnvironment, $overrides) as $key => $value) {
            if (is_string($key) && preg_match('/PASSWORD|APP_KEY|TOKEN|SECRET|COOKIE|CREDENTIAL|PRIVATE/i', $key)
                && is_string($value) && $value !== '') {
                $text = str_replace($value, '[REDACTED]', $text);
            }
        }

        return preg_replace([
            '/(authorization\s*[:=]\s*(?:bearer\s+)?)[^\s,;]+/i',
            '/((?:password|passwd|token|secret|api[_-]?key|app[_-]?key|cookie)\s*[:=]\s*)[^\s,;]+/i',
            '/(postgres(?:ql)?:\/\/[^:\s\/]+:)[^@\s\/]+@/i',
        ], ['$1[REDACTED]', '$1[REDACTED]', '$1[REDACTED]@'], $text) ?? $text;
    };
    $bounded = static function (string $text) use ($redact): string {
        $text = $redact($text);
        $excerpt = strlen($text) > 8192 ? '[earlier output omitted]\n'.substr($text, -8192) : $text;
        return mb_scrub($excerpt, 'UTF-8');
    };
    $finishEvidence = static function (?int $exitCode, bool $timedOut, string $stdout, string $stderr, ?string $launchError = null, bool $includeExcerpt = false) use (&$childProcesses, $label, $stdoutPath, $stderrPath, $startedAt, $bounded, $writeSummary, $root): void {
        $childProcesses[] = [
            'label' => $label,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
            'private_stdout' => is_file($stdoutPath) ? str_replace('\\', '/', substr($stdoutPath, strlen($root) + 1)) : null,
            'private_stderr' => is_file($stderrPath) ? str_replace('\\', '/', substr($stderrPath, strlen($root) + 1)) : null,
            'terminal_assertion' => $exitCode === 0 && ! $timedOut ? ($includeExcerpt ? 'FAIL' : 'PASS') : null,
            'stdout_excerpt' => $includeExcerpt ? $bounded($stdout) : null,
            'stderr_excerpt' => $includeExcerpt ? $bounded($stderr) : null,
            'launch_error' => $launchError,
        ];
        $writeSummary('IN_PROGRESS');
    };

    $pipes = [];
    $env = array_replace($processEnvironment, $overrides);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        $finishEvidence(null, false, '', '', 'proc_open failed');
        throw new RuntimeException("Gate1 CI could not start [{$label}].");
    }
    try {
        $ensurePrivateLogs();
        $stdoutFile = fopen($stdoutPath, 'xb');
        $stderrFile = fopen($stderrPath, 'xb');
        if ($stdoutFile === false || $stderrFile === false) {
            throw new RuntimeException('Gate1 CI could not open private child-output files.');
        }
        chmod($stdoutPath, 0600);
        chmod($stderrPath, 0600);
    } catch (Throwable $error) {
        proc_terminate($process, 9);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
        proc_close($process);
        $finishEvidence(null, false, '', '', $error::class);
        throw new RuntimeException("Gate1 CI could not preserve private output for [{$label}].", 0, $error);
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $exitCode = null;
    $timedOut = false;
    $deadline = $startedAt + 720;
    do {
        $outChunk = (string) stream_get_contents($pipes[1]);
        $errChunk = (string) stream_get_contents($pipes[2]);
        if ($outChunk !== '') { fwrite($stdoutFile, $outChunk); $stdout .= $outChunk; }
        if ($errChunk !== '') { fwrite($stderrFile, $errChunk); $stderr .= $errChunk; }
        $status = proc_get_status($process);
        if (! $status['running']) {
            $exitCode = (int) $status['exitcode'];
        } elseif (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process, 15);
            usleep(200_000);
            $status = proc_get_status($process);
            if ($status['running']) proc_terminate($process, 9);
            $exitCode = 124;
        } else {
            usleep(20_000);
        }
    } while ($status['running']);
    $outChunk = (string) stream_get_contents($pipes[1]);
    $errChunk = (string) stream_get_contents($pipes[2]);
    if ($outChunk !== '') { fwrite($stdoutFile, $outChunk); $stdout .= $outChunk; }
    if ($errChunk !== '') { fwrite($stderrFile, $errChunk); $stderr .= $errChunk; }
    fclose($pipes[1]);
    fclose($pipes[2]);
    fclose($stdoutFile);
    fclose($stderrFile);
    $closedCode = proc_close($process);
    $exitCode = $exitCode === null || $exitCode === -1 ? $closedCode : $exitCode;
    fwrite(STDOUT, "CI child [{$label}] raw output: {$stdoutPath} / {$stderrPath}\n");
    if ($timedOut) {
        $finishEvidence($exitCode, true, $stdout, $stderr, null, true);
        throw new RuntimeException("Gate1 CI step [{$label}] timed out after 720 seconds; raw output is private under .tmp/ci-postgres/private/.");
    }
    if ($exitCode !== 0) {
        $finishEvidence($exitCode, false, $stdout, $stderr, null, true);
        throw new RuntimeException("Gate1 CI step [{$label}] failed (exit {$exitCode}); raw output is private under .tmp/ci-postgres/private/.");
    }
    try {
        $assertOutput($stdout, $stderr);
    } catch (Throwable $error) {
        $finishEvidence($exitCode, false, $stdout, $stderr, $error::class, true);
        throw new RuntimeException("Gate1 CI step [{$label}] did not produce its required terminal evidence; raw output is private under .tmp/ci-postgres/private/.", 0, $error);
    }
    $finishEvidence($exitCode, false, $stdout, $stderr);

    return [$stdout, $stderr];
};

$verifyOwnership = static function () use ($root, $processEnvironment): array {
    $target = OptimizationRegressionDatabaseGuard::target($root, $processEnvironment);
    if ($target['port'] !== 5432 || $target['database'] !== 'erp_elec_new_test' || $target['username'] !== 'erp_ci'
        || $target['ci_receipt'] === null) {
        throw new RuntimeException('Refusing Gate1 CI: only the receipt-owned erp_elec_new_test@127.0.0.1:5432 target is allowed.');
    }

    try {
        $pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=erp_elec_new_test', 'erp_ci', (string) (getenv('DB_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $identity = (object) $pdo->query("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port, current_user, current_setting('data_directory') as data_directory, current_setting('server_version_num')::int as server_version_num, (select pg_get_userbyid(datdba) from pg_database where datname = current_database()) as database_owner")->fetch(PDO::FETCH_ASSOC);
        $receipt = $pdo->query('select nonce from codex_test_guard.ownership_receipts')->fetch(PDO::FETCH_OBJ);
        OptimizationRegressionDatabaseGuard::verify($target, $identity, $receipt ?: null);
    } catch (Throwable) {
        throw new RuntimeException('Gate1 CI refused a PostgreSQL connection whose live identity or database nonce failed ownership verification.');
    }

    return $target;
};

$terminalJson = static function (string $output, string $label): array {
    $output = rtrim($output);
    $output = preg_replace('/\R(?:RESULT all_pass=true assertions=\d+|RESULT PASS|FIN-P0 RESULT PASS assertions=\d+)\s*$/', '', $output) ?? $output;
    $lastResult = null;
    for ($offset = 0; ($offset = strpos($output, '{', $offset)) !== false; $offset++) {
        $decoded = json_decode(substr($output, $offset), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $lastResult = $decoded;
        }
    }
    if ($lastResult !== null) {
        return $lastResult;
    }

    throw new RuntimeException("Gate1 CI step [{$label}] produced no complete JSON result.");
};

$assertAllPass = static function (array $result, string $label, bool $requireResults = true): void {
    if (($result['all_pass'] ?? false) !== true || ($requireResults && (! is_array($result['results'] ?? null)
        || $result['results'] === [] || array_filter($result['results'], static fn ($row): bool => ($row['status'] ?? null) !== 'PASS') !== []))) {
        throw new RuntimeException("Gate1 CI step [{$label}] did not report all scenarios passing.");
    }
};

$assertHistoryEvidence = static function (string $path, string $label) use ($runId): void {
    $evidence = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (! is_array($evidence) || ($evidence['ci_run_id'] ?? null) !== $runId
        || ($evidence['all_required_scenarios_pass'] ?? false) !== true
        || ! is_array($evidence['scenario_status'] ?? null) || $evidence['scenario_status'] === []
        || array_filter($evidence['scenario_status'], static fn ($status): bool => $status !== 'PASS') !== []) {
        throw new RuntimeException("Gate1 CI step [{$label}] did not produce complete passing chronology evidence.");
    }
};

$assertTerminalLine = static function (string $output, string $pattern, string $label): void {
    $output = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output;
    $lines = preg_split('/\R/', trim($output)) ?: [];
    $last = $lines === [] ? '' : trim((string) end($lines));
    if ($last === '' || preg_match($pattern, $last) !== 1) {
        throw new RuntimeException("Gate1 CI step [{$label}] did not end with its explicit all-pass result.");
    }
};

$runJsonCheck = static function (string $label, string $script, array $overrides, ?callable $validate = null, ?string $terminalPattern = null) use ($runProcess, $php, $terminalJson, $assertTerminalLine, $recordPass, &$currentStep, $verifyOwnership): array {
    $currentStep = $label;
    $verifyOwnership();
    [$stdout] = $runProcess([$php, $script], $overrides, $label, static function (string $stdout) use ($label, $terminalPattern, $assertTerminalLine, $terminalJson): void {
        if ($terminalPattern !== null) {
            $assertTerminalLine($stdout, $terminalPattern, $label);
        }
        $terminalJson($stdout, $label);
    });
    $verifyOwnership();
    if ($terminalPattern !== null) {
        $assertTerminalLine($stdout, $terminalPattern, $label);
    }
    $result = $terminalJson($stdout, $label);
    if ($validate !== null) {
        $validate($result);
    }
    $recordPass($label);
    fwrite(STDOUT, "PASS {$label}\n");

    return $result;
};

try {
    $runProcess([$php, 'tests/Support/bootstrap-ci-postgres.php'], [], 'CI ownership receipt bootstrap', static function (string $stdout): void {
        if (trim($stdout) !== 'Verified disposable PostgreSQL 17 CI test target.') {
            throw new RuntimeException('CI ownership bootstrap did not emit its exact verified-target marker.');
        }
    });
    $summaryTarget = $verifyOwnership();
    $summaryTarget = [
        'database' => $summaryTarget['database'], 'connection_host' => $summaryTarget['connection_host'],
        'server_address' => $summaryTarget['server_address'], 'port' => $summaryTarget['port'],
        'username' => $summaryTarget['username'], 'database_owner' => $summaryTarget['ci_receipt']['database_owner'],
        'server_version_num' => $summaryTarget['ci_receipt']['server_version_num'],
    ];
    $recordPass('CI ownership receipt verified');

    // The receipt schema is outside the migration search path; verify it survives this reset.
    $currentStep = 'clean CI migration baseline';
    $verifyOwnership();
    $runProcess([$php, 'artisan', 'migrate:fresh', '--force', '--no-interaction', '--env=testing'], [], 'clean CI migration baseline', static function (string $stdout) use ($assertTerminalLine): void {
        $assertTerminalLine($stdout, '/^.*\bDONE$/', 'clean CI migration baseline');
    });
    $verifyOwnership();
    $recordPass('clean CI migration baseline');

    $closeReceiptPath = $root.'/.tmp/ci-postgres/gate1-close-controls-'.$runId.'.json';
    $runJsonCheck('Gate1 close controls', 'tests/Support/check-gate1-financial-close-controls.php', [
        'GATE1_DATABASE' => 'erp_elec_new_test',
    ], static function (array $result) use ($runId, $closeReceiptPath): void {
        $receipt = is_file($closeReceiptPath) ? json_decode((string) file_get_contents($closeReceiptPath), true) : null;
        $resultChecks = is_array($result['results'] ?? null) ? $result['results'] : [];
        $receiptResults = is_array($receipt['results'] ?? null) ? $receipt['results'] : [];
        if (($result['ci_run_id'] ?? null) !== $runId || (int) ($result['assertions'] ?? 0) < 1
            || $resultChecks === [] || array_filter($resultChecks, static fn ($check): bool => ($check['result'] ?? null) !== 'PASS') !== []
            || ($result['rolled_back'] ?? false) !== true || ! is_array($receipt)
            || ($receipt['ci_run_id'] ?? null) !== $runId || (int) ($receipt['assertions'] ?? 0) < 1
            || ($receipt['rolled_back'] ?? false) !== true || $receiptResults === []
            || array_filter($receiptResults, static fn ($check): bool => ($check['result'] ?? null) !== 'PASS') !== []) {
            throw new RuntimeException('Gate1 close controls did not report a passing rollback-only result.');
        }
    }, '/^RESULT all_pass=true assertions=[1-9][0-9]*$/');

    $inventoryPath = $root.'/.tmp/ci-postgres/artifacts/inventory-chronology-'.$runId.'.json';
    $runJsonCheck('Gate1 historical inventory', 'tests/Support/check-phase0-valued-inventory.php', [
        'GATE1_HISTORY_CHECK' => '1',
    ], static function (array $result) use ($assertAllPass, $assertHistoryEvidence, $inventoryPath, $runId): void {
        $assertAllPass($result, 'historical inventory');
        if (($result['ci_run_id'] ?? null) !== $runId) {
            throw new RuntimeException('Historical inventory result belongs to a different CI run.');
        }
        $assertHistoryEvidence($inventoryPath, 'historical inventory');
    });

    $treasuryPath = $root.'/.tmp/ci-postgres/artifacts/ar-ap-treasury-chronology-'.$runId.'.json';
    $phase1RunPath = $root.'/.tmp/ci-postgres/phase1-financial-journeys-'.$runId.'.json';
    $riskPath = $root.'/.tmp/ci-postgres/financial-risk-cases-'.$runId.'.json';
    $runJsonCheck('Gate1 historical AR/AP/Treasury and original risk cases', 'tests/Support/check-phase1-financial-journeys.php', [
        'GATE1_HISTORY_CHECK' => '1',
        'GATE1_FINANCIAL_RISK_CHECK' => '1',
    ], static function (array $result) use ($assertAllPass, $assertHistoryEvidence, $treasuryPath, $phase1RunPath, $riskPath, $runId): void {
        $assertAllPass($result, 'historical AR/AP/Treasury and original risk cases', false);
        if (($result['ci_run_id'] ?? null) !== $runId) {
            throw new RuntimeException('Historical AR/AP/Treasury result belongs to a different CI run.');
        }
        $assertHistoryEvidence($treasuryPath, 'historical AR/AP/Treasury');
        $phase1Receipt = is_file($phase1RunPath) ? json_decode((string) file_get_contents($phase1RunPath), true) : null;
        if (! is_array($phase1Receipt) || ($phase1Receipt['ci_run_id'] ?? null) !== $runId
            || ($phase1Receipt['all_pass'] ?? false) !== true
            || ($phase1Receipt['gate1_financial_risk_cases']['result'] ?? null) !== 'PASS') {
            throw new RuntimeException('Historical AR/AP/Treasury run receipt is missing, stale, or incomplete.');
        }
        $risk = $result['gate1_financial_risk_cases'] ?? null;
        $receipt = is_file($riskPath) ? json_decode((string) file_get_contents($riskPath), true) : null;
        if (! is_array($risk) || ($risk['result'] ?? null) !== 'PASS' || (int) ($risk['assertions'] ?? 0) < 3
            || count($risk['findings'] ?? []) !== 3
            || array_filter($risk['findings'], static fn ($case): bool => ($case['test_result'] ?? null) !== 'PASS') !== []
            || ! is_array($receipt) || ($receipt['ci_run_id'] ?? null) !== $runId
            || ($receipt['rollback_only'] ?? false) !== true || ($receipt['result'] ?? null) !== 'PASS'
            || count($receipt['findings'] ?? []) !== 3
            || array_filter($receipt['findings'], static fn ($case): bool => ($case['test_result'] ?? null) !== 'PASS') !== []) {
            throw new RuntimeException('The three original Gate1 financial risk reproductions did not all pass.');
        }
    }, '/^FIN-P0 RESULT PASS assertions=3$/');

    $raceRun = static function (string $ordering) use ($root, $runProcess, $php, $verifyOwnership, $terminalJson, $assertTerminalLine, $recordPass, &$currentStep, &$raceTeardowns, $writeSummary, $runId): void {
        $currentStep = "Gate1 {$ordering} two-process close/post race";
        $verifyOwnership();
        $runProcess([$php, 'artisan', 'migrate:fresh', '--force', '--no-interaction', '--env=testing'], [], "reset before {$ordering} CI race", static function (string $stdout) use ($assertTerminalLine, $ordering): void {
            $assertTerminalLine($stdout, '/^.*\bDONE$/', "reset before {$ordering} CI race");
        });
        $verifyOwnership();
        $failure = null;
        try {
            [$stdout] = $runProcess([$php, 'tests/Support/check-gate1-close-post-race.php'], [
                'GATE1_RACE_ORDER' => $ordering,
                'GATE1_DATABASE' => 'erp_elec_new_test',
            ], "Gate1 {$ordering} two-process close/post race", static function (string $stdout) use ($assertTerminalLine, $ordering): void {
                $assertTerminalLine($stdout, '/^RESULT PASS$/', "{$ordering} race");
            });
            $assertTerminalLine($stdout, '/^RESULT PASS$/', "{$ordering} race");
            $result = $terminalJson($stdout, "{$ordering} race");
            if (($result['result'] ?? null) !== 'PASS' || ($result['race_order'] ?? null) !== $ordering
                || ($result['ci_run_id'] ?? null) !== $runId || ($result['ci_receipt_verified'] ?? false) !== true
                || ($result['ownership_verified'] ?? false) !== true || ($result['synthetic_committed_fixture'] ?? false) !== true) {
                throw new RuntimeException("Gate1 CI race [{$ordering}] did not report its exact passing ordering.");
            }
            $receiptPath = $root.'/.tmp/ci-postgres/gate1-close-post-race-'.$runId.'-'.$ordering.'.json';
            $receipt = is_file($receiptPath) ? json_decode((string) file_get_contents($receiptPath), true) : null;
            if (! is_array($receipt) || ($receipt['result'] ?? null) !== 'PASS'
                || ($receipt['ci_run_id'] ?? null) !== $runId || ($receipt['race_order'] ?? null) !== $ordering
                || ($receipt['ci_receipt_verified'] ?? false) !== true || ($receipt['ownership_verified'] ?? false) !== true
                || ($receipt['synthetic_committed_fixture'] ?? false) !== true) {
                throw new RuntimeException("Gate1 CI race [{$ordering}] did not write its current-run passing receipt.");
            }
        } catch (Throwable $error) {
            $failure = $error;
        }

        try {
            $verifyOwnership();
            $runProcess([$php, 'artisan', 'migrate:fresh', '--force', '--no-interaction', '--env=testing'], [], "teardown after {$ordering} CI race", static function (string $stdout) use ($assertTerminalLine, $ordering): void {
                $assertTerminalLine($stdout, '/^.*\bDONE$/', "teardown after {$ordering} CI race");
            });
            $verifyOwnership();
            $raceTeardowns[$ordering] = 'VERIFIED CLEAN AFTER ORDERING';
            $writeSummary('IN_PROGRESS');
        } catch (Throwable $cleanupError) {
            $raceTeardowns[$ordering] = 'FAILED CLEANUP: '.$cleanupError::class;
            $writeSummary('IN_PROGRESS', $cleanupError::class);
            $primaryClass = $failure === null ? 'none' : $failure::class;
            throw new RuntimeException('Gate1 CI race ['.$ordering.'] cleanup failed ('.$cleanupError::class.'); original race failure class: '.$primaryClass.'.', 0, $failure ?? $cleanupError);
        }
        if ($failure !== null) {
            throw new RuntimeException("Gate1 CI race [{$ordering}] failed; its disposable database was reset and reverified.", 0, $failure);
        }
        $recordPass("Gate1 {$ordering} two-process close/post race and verified teardown");
        fwrite(STDOUT, "PASS Gate1 {$ordering} two-process close/post race with verified CI teardown\n");
    };

    $raceRun('close-first');
    $raceRun('collect-first');
    $verifyOwnership();
    $writeSummary('PASS');
    fwrite(STDOUT, "RESULT Gate1 CI PASS; all probes passed and committed race fixtures were removed.\n");
} catch (Throwable $error) {
    try {
        $writeSummary('FAIL', $error::class);
    } catch (Throwable) {
    }
    fwrite(STDERR, "Gate1 CI failed at [{$currentStep}]; sanitized summary: .tmp/ci-postgres/artifacts/gate1-summary.json\n");
    exit(1);
}
