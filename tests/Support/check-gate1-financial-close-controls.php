<?php

declare(strict_types=1);

// Gate 1 close/control acceptance. Run only on the marked Gate1 PostgreSQL clone.
use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\AccountingService;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\FinancialReports;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\ErrorCode;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\OptimizationRegressionDatabaseGuard;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$root = dirname(__DIR__, 2);
$environment = ['APP_ENV' => (string) (getenv('APP_ENV') ?: 'testing'), 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: ''), 'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: ''), 'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: '')];
$ciTarget = (int) $environment['DB_PORT'] === 5432;
$ciRunId = (string) (getenv('GATE1_CI_RUN_ID') ?: '');
if ($ciTarget && ! preg_match('/^[a-f0-9]{32}$/', $ciRunId)) {
    throw new RuntimeException('CI Gate1 close controls require the current 32-hex GATE1_CI_RUN_ID.');
}
if ($ciTarget) {
    require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
    $target = OptimizationRegressionDatabaseGuard::target($root, $environment);
    $database = $target['database'];
    $dbUsername = $target['username'];
    $dbPassword = (string) (getenv('DB_PASSWORD') ?: '');
    $targetPath = $root.'/.tmp/ci-postgres/ownership.json';
} else {
    $database = getenv('GATE1_DATABASE') ?: 'erp_elec_new_test';
    $targetPath = getenv('GATE1_TARGET_PATH') ?: $root.'/.tmp/master-remediation-20261101/target.json';
    $target = is_file($targetPath) ? json_decode((string) file_get_contents($targetPath), true) : null;
    $dbUsername = 'postgres';
    $dbPassword = '';
}
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$cachePath = '.tmp/master-remediation-20261101/close-control-cache';
$storagePath = $taskRoot.'/close-control-storage';
$viewPath = $taskRoot.'/close-control-views';
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) ($target['port'] ?? ''),
    'DB_DATABASE' => $database, 'DB_USERNAME' => $dbUsername, 'DB_PASSWORD' => $dbPassword, 'DB_URL' => '', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'LARAVEL_STORAGE_PATH' => $storagePath,
    'APP_CONFIG_CACHE' => $cachePath.'/config.php', 'APP_ROUTES_CACHE' => $cachePath.'/routes.php',
    'APP_EVENTS_CACHE' => $cachePath.'/events.php', 'APP_SERVICES_CACHE' => $cachePath.'/services.php',
    'APP_PACKAGES_CACHE' => $cachePath.'/packages.php', 'APP_VIEW_COMPILED_PATH' => '.tmp/master-remediation-20261101/close-control-views',
    'VIEW_COMPILED_PATH' => $viewPath, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach ([$root.'/'.$cachePath, $storagePath, $viewPath] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storagePath);
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $ciTarget ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
if ($ciTarget) {
    OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
} else {
    $expectedData = realpath($taskRoot.'/pgdata');
    $markerPath = $taskRoot.'/pgdata/.codex-audit-owned.txt';
    if (! is_array($target) || ($target['database'] ?? null) !== $database || (int) ($target['port'] ?? 0) !== 49685
        || realpath((string) ($target['pgdata'] ?? '')) !== $expectedData || $identity->database !== $database || $identity->server_address !== '127.0.0.1'
        || (int) $identity->port !== 49685 || realpath((string) $identity->data_directory) !== $expectedData || ! is_file($markerPath)
        || trim((string) file_get_contents($markerPath)) !== 'master-remediation-20261101 Gate1 private PostgreSQL marker') {
        throw new RuntimeException('ABORT before writes: Gate1 test requires its marked PostgreSQL clone at 127.0.0.1:49685.');
    }
}

$passes = 0;
$results = [];
$check = static function (bool $condition, string $name) use (&$passes, &$results): void {
    $results[] = ['name' => $name, 'result' => $condition ? 'PASS' : 'FAIL'];
    if (! $condition) {
        throw new RuntimeException('FAIL '.$name);
    }
    $passes++;
    fwrite(STDOUT, 'PASS '.$name.PHP_EOL);
};
$denied = static function (callable $action, string $name) use ($check): void {
    try {
        $action();
    } catch (Throwable $error) {
        if ($error instanceof ValidationException || ($error instanceof ContractException && $error->errorCode === ErrorCode::Forbidden)
            || ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 409, 422], true))) {
            $check(true, $name);

            return;
        }
        throw $error;
    }
    $check(false, $name.' (operation unexpectedly succeeded)');
};
$grant = static function (User $actor, array $permissions, ?int $branch = null): void {
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => $permission,
            'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$uuid = static fn (): string => (string) Str::uuid();
$counts = static function (): array {
    return [
        'journals' => (int) DB::table('accounting_journals')->count(),
        'lines' => (int) DB::table('accounting_journal_lines')->count(),
        'closures' => (int) DB::table('accounting_period_closures')->count(),
        'treasury' => (int) DB::table('treasury_movements')->count(),
        'advances' => (int) DB::table('rp_customer_advances')->count(),
        'audit_entries' => (int) DB::table('audit_entries')->count(),
    ];
};
$attempt = static function (callable $operation): array {
    DB::beginTransaction();
    try {
        $operation();

        return ['denied' => false];
    } catch (Throwable $error) {
        return ['denied' => $error instanceof ValidationException || ($error instanceof ContractException && $error->errorCode === ErrorCode::Forbidden)
            || ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 409, 422], true)),
            'class' => $error::class, 'status' => $error instanceof HttpExceptionInterface ? $error->getStatusCode()
                : ($error instanceof ContractException ? $error->getCode() : null)];
    } finally {
        DB::rollBack();
    }
};

DB::beginTransaction();
$companyBefore = DB::table('companies')->where('id', 1)->first();
$before = $counts();
try {
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $checker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $noReport = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $partial = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $full = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $grant($maker, ['M01.company_update', 'M01.branch_view', 'M11.account_manage', 'M11.setup_manage', 'M11.tax_manage', 'M11.period_open',
        'M11.opening_manage', 'M11.opening_entry', 'M11.opening_review', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_create',
        'M11.journal_submit', 'M11.journal_view', 'M03.customer_view', 'M13.account_manage']);
    $grant($checker, ['M01.branch_view', 'M11.opening_review', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_approve', 'M11.journal_post',
        'M11.journal_view', 'M11.period_close', 'M11.report_view', 'M01.company_update']);
    $grant($noReport, ['M11.period_close']);
    $grant($partial, ['M11.period_close', 'M11.report_view']);
    $grant($full, ['M11.period_close', 'M11.period_reopen', 'M11.report_view', 'M01.branch_view', 'M01.company_update']);

    app(SaveCompany::class)->execute($maker, ['code' => $companyBefore->code ?? 'G1-SYNTHETIC', 'name' => $companyBefore->name ?? 'Gate 1 synthetic company',
        'legal_name' => 'Gate 1 Synthetic Test Company', 'tax_registration' => 'G1-TEST-ONLY', 'address' => 'Synthetic test address'],
        $companyBefore ? (int) $companyBefore->version : null);
    $branchA = Branch::factory()->create(['code' => 'G1-A-'.strtoupper(Str::random(6))]);
    $branchB = Branch::factory()->create(['code' => 'G1-B-'.strtoupper(Str::random(6))]);
    foreach ([[$maker, $branchA], [$checker, $branchA], [$checker, $branchB], [$partial, $branchA], [$full, $branchA], [$full, $branchB]] as [$actor, $branch]) {
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant($maker, ['M11.journal_create', 'M11.journal_submit', 'M11.source_post', 'M03.customer_view', 'M12.ar_view', 'M13.collection_execute'], (int) $branchA->id);
    $grant($checker, ['M11.journal_approve', 'M11.journal_post'], (int) $branchA->id);
    $grant($partial, ['M01.branch_view', 'M11.report_view'], (int) $branchA->id);
    $grant($full, ['M11.report_view'], (int) $branchA->id);
    $grant($full, ['M11.report_view'], (int) $branchB->id);
    $grant($checker, ['M11.report_view'], (int) $branchA->id);
    $grant($checker, ['M11.report_view'], (int) $branchB->id);

    $actions = app(AccountingActions::class);
    $maps = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $maps[$purpose] = $actions->account((int) $maker->id, null, 0, ['code' => 'G1-'.strtoupper($purpose).'-'.strtoupper(Str::random(4)),
            'name' => 'Gate 1 '.$label, 'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]);
    }
    $periods = [];
    foreach ([['no-report', '2024-01-01', '2024-03-31'], ['partial', '2024-04-01', '2024-06-30'],
        ['mismatch', '2024-07-01', '2024-09-30'], ['full', '2024-10-01', '2024-12-31'], ['journals', '2025-01-01', '2025-12-31']] as [$key, $start, $end]) {
        $periods[$key] = $actions->period((int) $maker->id, ['name' => 'G1 '.$key, 'starts_on' => $start, 'ends_on' => $end]);
    }
    $activation = DB::table('accounting_activation')->where('id', 1)->first();
    if (! $activation || $activation->activated_at !== null) {
        throw new RuntimeException('ABORT: refusing to replace activation state in Gate1 database.');
    }
    $setup = app(FinancialActivation::class);
    $version = (int) $activation->version;
    $setup->save((int) $maker->id, $version, ['go_live_date' => '2024-01-01', 'mappings' => $maps]);
    $version++;
    foreach (['sale', 'purchase'] as $type) {
        $tax = ['code' => 'G1-TAX-'.strtoupper($type).'-'.strtoupper(Str::random(4)), 'name' => 'Gate 1 '.$type.' tax',
            'transaction_type' => $type, 'product_id' => null, 'rate' => '14', 'starts_on' => '2024-01-01', 'ends_on' => null,
            'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Synthetic test fixture only'];
        if ($type === 'purchase') {
            $tax['purchase_recoverable'] = true;
        }
        $setup->addTaxRule((int) $maker->id, $version++, $tax);
    }
    $setup->validateTaxTreatment((int) $maker->id, $version++, 'Gate 1 test-only tax validation');
    $opening = app(OpeningBalances::class);
    $batch = $opening->save((int) $maker->id, $version, $uuid(), [], true, 'No opening balances declared for the fresh disposable Gate1 test database.');
    $opening->advance((int) $maker->id, $batch, 1, 'submit');
    $opening->advance((int) $checker->id, $batch, 2, 'approve');
    app(ActivateFinancialPosting::class)->execute((int) $checker->id, $batch, 3);

    $reports = app(FinancialReports::class);
    $noScopeCountsBefore = $counts();
    $noScopeAttempt = $attempt(fn () => $reports->closePeriod((int) $noReport->id, (int) $periods['no-report']));
    $noScopeCountsAfter = $counts();
    $check($noScopeAttempt['denied'] && DB::table('accounting_periods')->where('id', $periods['no-report'])->where('status', 'open')->exists()
        && ! DB::table('accounting_period_closures')->where('period_id', $periods['no-report'])->exists() && $noScopeCountsBefore === $noScopeCountsAfter,
        'No report authority cannot close a company period and leaves no closure or audit effects');
    $check($noScopeAttempt['status'] === 403 && is_a($noScopeAttempt['class'], HttpExceptionInterface::class, true),
        'No-scope company close is surfaced as HTTP 403 instead of an unhandled contract exception');

    $partialCountsBefore = $counts();
    $partialAttempt = $attempt(fn () => $reports->closePeriod((int) $partial->id, (int) $periods['partial']));
    $partialCountsAfter = $counts();
    $check($partialAttempt['denied'] && DB::table('accounting_periods')->where('id', $periods['partial'])->where('status', 'open')->exists()
        && ! DB::table('accounting_period_closures')->where('period_id', $periods['partial'])->exists() && $partialCountsBefore === $partialCountsAfter,
        'Partial branch report scope cannot certify a company-wide close without closure or audit effects');
    $check($partialAttempt['status'] === 403 && is_a($partialAttempt['class'], HttpExceptionInterface::class, true),
        'Partial-scope company close is surfaced as HTTP 403');

    // Exercise the branch-completeness assertion with an owner-contract test double only;
    // no synthetic journal, inventory layer, or subledger posting is inserted.
    $inventory = Mockery::mock(InventoryApi::class);
    $inventory->shouldReceive('financialValue')->andReturnUsing(fn (int $actor, array $branches, ?string $to = null): string => in_array((int) $branchB->id, $branches, true) ? '10.00' : '0.00');
    $inventory->shouldReceive('transitValue')->andReturn('0.00');
    $purchasing = Mockery::mock(PurchasingApi::class);
    $purchasing->shouldReceive('grniValue')->andReturn('0.00');
    $rp = Mockery::mock(ReceivablesPayablesApi::class);
    $rp->shouldReceive('reconciliationValues')->andReturn(['ap' => '0.00', 'ar' => '0.00', 'grni_cleared' => '0.00', 'customer_advances' => '0.00']);
    $treasury = Mockery::mock(TreasuryApi::class);
    $treasury->shouldReceive('reconciliationValues')->andReturn(['cash' => '0.00', 'bank' => '0.00']);
    $treasury->shouldReceive('reconciliationMovements')->andReturn([]);
    app()->instance(InventoryApi::class, $inventory);
    app()->instance(PurchasingApi::class, $purchasing);
    app()->instance(ReceivablesPayablesApi::class, $rp);
    app()->instance(TreasuryApi::class, $treasury);
    $mismatchCountsBefore = $counts();
    $mismatchAttempt = $attempt(fn () => $reports->closePeriod((int) $full->id, (int) $periods['mismatch']));
    $mismatchCountsAfter = $counts();
    $check($mismatchAttempt['denied'] && DB::table('accounting_periods')->where('id', $periods['mismatch'])->where('status', 'open')->exists()
        && ! DB::table('accounting_period_closures')->where('period_id', $periods['mismatch'])->exists() && $mismatchCountsBefore === $mismatchCountsAfter,
        'A second-branch reconciliation mismatch denies close without closure/audit effects (owner-contract test double)');
    foreach ([InventoryApi::class, PurchasingApi::class, ReceivablesPayablesApi::class, TreasuryApi::class] as $contract) {
        app()->forgetInstance($contract);
    }

    $reports->closePeriod((int) $full->id, (int) $periods['full']);
    $closure = DB::table('accounting_period_closures')->where('period_id', $periods['full'])->sole();
    $check(DB::table('accounting_periods')->where('id', $periods['full'])->where('status', 'closed')->exists()
        && (int) $closure->period_version === 1 && $closure->cutoff === '2024-12-31' && (int) $closure->closed_by === (int) $full->id
        && hash_equals($closure->sha256, hash('sha256', $closure->evidence)),
        'Fully scoped clean company close succeeds once and stores immutable, digest-verified cutoff evidence');
    $denied(fn () => $reports->closePeriod((int) $full->id, (int) $periods['full']), 'A second close cannot create another closure evidence row');
    $check(DB::table('accounting_period_closures')->where('period_id', $periods['full'])->count() === 1,
        'Duplicate close leaves exactly one immutable closure evidence row');

    $originalEvidence = (string) $closure->evidence;
    $originalEvidenceHash = (string) $closure->sha256;
    $immutableCountsBefore = $counts();
    $immutableAttempt = static function (callable $operation): bool {
        $outerLevel = DB::transactionLevel();
        DB::beginTransaction();
        $blocked = false;
        try {
            $operation();
        } catch (QueryException) {
            $blocked = true;
        } finally {
            if (DB::transactionLevel() > $outerLevel) {
                DB::rollBack();
            }
        }

        return $blocked;
    };
    $updateBlocked = $immutableAttempt(fn () => DB::table('accounting_period_closures')->where('id', $closure->id)->update(['evidence' => $originalEvidence]));
    $deleteBlocked = $immutableAttempt(fn () => DB::table('accounting_period_closures')->where('id', $closure->id)->delete());
    $closureAfterMutationAttempts = DB::table('accounting_period_closures')->where('id', $closure->id)->first();
    $check($updateBlocked && $deleteBlocked && $closureAfterMutationAttempts
        && $closureAfterMutationAttempts->evidence === $originalEvidence && hash_equals($originalEvidenceHash, $closureAfterMutationAttempts->sha256)
        && $counts() === $immutableCountsBefore,
        'Database immutability trigger rejects UPDATE and DELETE without changing evidence or audit state');

    $actions->reopen((int) $full->id, (int) $periods['full'], 2, 'Synthetic test-only reopening verifies closure history is retained.');
    $reopenedClosure = DB::table('accounting_period_closures')->where('id', $closure->id)->first();
    $check(DB::table('accounting_periods')->where('id', $periods['full'])->where('status', 'open')->where('version', 3)->exists()
        && $reopenedClosure && $reopenedClosure->evidence === $originalEvidence && hash_equals($originalEvidenceHash, $reopenedClosure->sha256),
        'Authorized reopen preserves original immutable closure evidence and advances period version');
    $reports->closePeriod((int) $full->id, (int) $periods['full']);
    $reclosedRows = DB::table('accounting_period_closures')->where('period_id', $periods['full'])->orderBy('period_version')->get();
    $check($reclosedRows->count() === 2 && (int) $reclosedRows[0]->period_version === 1 && (int) $reclosedRows[1]->period_version === 3
        && $reclosedRows[0]->evidence === $originalEvidence && hash_equals($originalEvidenceHash, $reclosedRows[0]->sha256)
        && hash_equals($reclosedRows[1]->sha256, hash('sha256', $reclosedRows[1]->evidence)),
        'Reclose records a second versioned digest while preserving the first closure evidence bytes');

    $control = $actions->account((int) $maker->id, null, 0, ['code' => 'G1-ADV-'.strtoupper(Str::random(5)), 'name' => 'Gate 1 customer advance liability',
        'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $contra = $actions->account((int) $maker->id, null, 0, ['code' => 'G1-EXP-'.strtoupper(Str::random(5)), 'name' => 'Gate 1 ordinary expense',
        'kind' => 'expense', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $candidate = $actions->account((int) $maker->id, null, 0, ['code' => 'G1-ACCRUAL-'.strtoupper(Str::random(5)), 'name' => 'Gate 1 candidate liability',
        'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $makeJournal = static function (int $actor, int $controlAccount, int $contraAccount, string $description) use ($actions, $branchA, $uuid): int {
        return $actions->journal($actor, null, 0, $uuid(), ['branch_id' => (int) $branchA->id, 'journal_date' => '2025-01-15', 'description' => $description,
            'lines' => [['account_id' => $contraAccount, 'debit' => '1.00', 'credit' => '0.00', 'description' => $description],
                ['account_id' => $controlAccount, 'debit' => '0.00', 'credit' => '1.00', 'description' => $description]]]);
    };
    // These are valid public-action journals created before a legacy optional-control mapping existed.
    // Seed only the pre-existing mapping configuration below; no accounting history is fabricated.
    $draftSubmit = $makeJournal((int) $maker->id, $control, $contra, 'legacy draft submit guard fixture');
    $draftApprove = $makeJournal((int) $maker->id, $control, $contra, 'legacy submitted approval guard fixture');
    $draftPost = $makeJournal((int) $maker->id, $control, $contra, 'legacy approved post guard fixture');
    $usedLiability = $actions->account((int) $maker->id, null, 0, ['code' => 'G1-USED-'.strtoupper(Str::random(4)), 'name' => 'Gate 1 pre-existing manual liability',
        'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $usedJournal = $actions->journal((int) $maker->id, null, 0, $uuid(), ['branch_id' => (int) $branchA->id, 'journal_date' => '2025-01-15',
        'description' => 'Synthetic pre-mapping ordinary liability use', 'lines' => [['account_id' => $contra, 'debit' => '1.00', 'credit' => '0.00'],
            ['account_id' => $usedLiability, 'debit' => '0.00', 'credit' => '1.00']]]);
    $actions->advance((int) $maker->id, $usedJournal, 1, 'submit');
    $actions->advance((int) $checker->id, $usedJournal, 2, 'approve');
    $actions->advance((int) $checker->id, $usedJournal, 3, 'post');
    $denied(fn () => $setup->saveOptionalMapping((int) $maker->id, 'maintenance_labor_accrual', $usedLiability),
        'An account used by a posted manual journal cannot become a new maintenance accrual control mapping');
    $check(! DB::table('accounting_account_mappings')->where('purpose', 'maintenance_labor_accrual')->exists(),
        'Rejected control mapping addition leaves the optional purpose unmapped');
    $submittedCandidate = $actions->account((int) $maker->id, null, 0, ['code' => 'G1-SUBMIT-MAP-'.strtoupper(Str::random(4)),
        'name' => 'Gate 1 submitted mapping candidate', 'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $submittedJournal = $makeJournal((int) $maker->id, $submittedCandidate, $contra, 'submitted mapping candidate lifecycle');
    $actions->advance((int) $maker->id, $submittedJournal, 1, 'submit');
    $denied(fn () => $setup->saveOptionalMapping((int) $maker->id, 'maintenance_labor_accrual', $submittedCandidate),
        'A submitted manual account cannot be added as a new source-owned control mapping');
    $approvedCandidate = $actions->account((int) $maker->id, null, 0, ['code' => 'G1-APPROVE-MAP-'.strtoupper(Str::random(4)),
        'name' => 'Gate 1 approved mapping candidate', 'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $approvedJournal = $makeJournal((int) $maker->id, $approvedCandidate, $contra, 'approved mapping candidate lifecycle');
    $actions->advance((int) $maker->id, $approvedJournal, 1, 'submit');
    $actions->advance((int) $checker->id, $approvedJournal, 2, 'approve');
    $denied(fn () => $setup->saveOptionalMapping((int) $maker->id, 'maintenance_labor_accrual', $approvedCandidate),
        'An approved manual account cannot be added as a new source-owned control mapping');
    $check(! DB::table('accounting_account_mappings')->where('purpose', 'maintenance_labor_accrual')->exists()
        && DB::table('accounting_journals')->where('id', $submittedJournal)->where('status', 'submitted')->exists()
        && DB::table('accounting_journals')->where('id', $approvedJournal)->where('status', 'approved')->exists(),
        'Rejected submitted/approved mapping additions preserve both ordinary journal lifecycles and leave the purpose unmapped');
    $actions->advance((int) $maker->id, $draftApprove, 1, 'submit');
    $actions->advance((int) $maker->id, $draftPost, 1, 'submit');
    $actions->advance((int) $checker->id, $draftPost, 2, 'approve');
    DB::table('accounting_account_mappings')->insert(['purpose' => 'customer_advance', 'account_id' => $control, 'updated_at' => now()]);
    $check((int) DB::table('accounting_account_mappings')->where('purpose', 'customer_advance')->value('account_id') === $control,
        'Legacy control mapping fixture is isolated configuration only; journal fixtures were created through public Actions');
    $denied(fn () => $actions->advance((int) $maker->id, $draftSubmit, 1, 'submit'), 'Protected control account is rejected at manual journal submission');
    $denied(fn () => $actions->advance((int) $checker->id, $draftApprove, 2, 'approve'), 'Protected control account is rejected at independent approval');
    $denied(fn () => $actions->advance((int) $checker->id, $draftPost, 3, 'post'), 'Protected control account is rejected at manual posting');
    $beforeCreate = $counts();
    $denied(fn () => $makeJournal((int) $maker->id, $control, $contra, 'mapped control create denied'), 'Protected control account is rejected at manual journal creation');
    $check($counts() === $beforeCreate, 'Rejected manual control attempts leave no journal, lines, source, or audit state');
    $otherLiability = $actions->account((int) $maker->id, null, 0, ['code' => 'G1-ADV-ALT-'.strtoupper(Str::random(4)), 'name' => 'Gate 1 alternate liability',
        'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $denied(fn () => $setup->saveOptionalMapping((int) $maker->id, 'customer_advance', $otherLiability), 'Customer advance mapping cannot be remapped after it is used as a source-owned control');
    $check((int) DB::table('accounting_account_mappings')->where('purpose', 'customer_advance')->value('account_id') === $control,
        'Rejected optional mapping change preserves the established account mapping');

    // Post an ordinary manual journal outside all control accounts, then prove ordinary reversal remains available.
    $income = $actions->account((int) $maker->id, null, 0, ['code' => 'G1-INCOME-'.strtoupper(Str::random(4)), 'name' => 'Gate 1 ordinary income',
        'kind' => 'income', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $ordinary = $actions->journal((int) $maker->id, null, 0, $uuid(), ['branch_id' => (int) $branchA->id, 'journal_date' => '2025-02-01',
        'description' => 'Gate 1 ordinary manual journal', 'lines' => [['account_id' => $contra, 'debit' => '2.00', 'credit' => '0.00'],
            ['account_id' => $income, 'debit' => '0.00', 'credit' => '2.00']]]);
    $actions->advance((int) $maker->id, $ordinary, 1, 'submit');
    $actions->advance((int) $checker->id, $ordinary, 2, 'approve');
    $actions->advance((int) $checker->id, $ordinary, 3, 'post');
    $reverse = app(AccountingService::class)->reversePostedJournal((int) $checker->id, $ordinary, '2025-02-02', 'Synthetic test-only ordinary journal reversal');
    $check(DB::table('accounting_journals')->where('id', $ordinary)->where('status', 'posted')->exists()
        && DB::table('accounting_journals')->where('id', $reverse)->where('source_key', 'journal-reversal:'.$ordinary)->where('status', 'posted')->exists(),
        'Ordinary non-control manual posting and reversal remain functional');

    // A real M12 customer advance remains source-owned; manual reversal is forbidden.
    $customer = Customer::factory()->create(['status' => 'active']);
    $treasuryAccount = app(TreasuryApi::class)->saveOperationalAccount((int) $maker->id, ['code' => 'G1-CASH-'.strtoupper(Str::random(5)),
        'name' => 'Gate 1 synthetic cash', 'kind' => 'cash', 'gl_account_id' => (int) $maps['cash']]);
    $beforeJournalId = (int) DB::table('accounting_journals')->max('id');
    $collection = app(TreasuryApi::class)->executeCustomerCollection((int) $maker->id, ['idempotency_key' => $uuid(),
        'branch_id' => (int) $branchA->id, 'customer_id' => (int) $customer->id, 'account_id' => $treasuryAccount, 'date' => '2025-03-01',
        'amount' => '1.00', 'reference' => 'Gate 1 genuine synthetic customer advance', 'allow_advance' => true], []);
    $advanceJournal = DB::table('accounting_journals')->where('id', '>', $beforeJournalId)->where('status', 'posted')->orderByDesc('id')->first();
    $check($advanceJournal && (int) DB::table('rp_customer_advances')->where('branch_id', $branchA->id)->where('customer_id', $customer->id)->count() === 1,
        'Public Treasury customer advance creates its genuine source journal and source record');
    $beforeReverseCounts = $counts();
    $denied(fn () => app(AccountingService::class)->reversePostedJournal((int) $checker->id, (int) $advanceJournal->id, '2025-03-02', 'Attempted manual source-control reversal'),
        'Manual reversal of a source-owned customer-advance control journal is rejected');
    $check($counts() === $beforeReverseCounts && DB::table('accounting_journals')->where('id', $advanceJournal->id)->where('status', 'posted')->exists(),
        'Denied control-account reversal leaves original posting/source unchanged');

    $after = $counts();
    DB::rollBack();
    $afterRollback = $counts();
    $companyAfterRollback = DB::table('companies')->where('id', 1)->first();
    $companyRestored = ($companyBefore === null && $companyAfterRollback === null)
        || (is_object($companyBefore) && is_object($companyAfterRollback) && (array) $companyBefore === (array) $companyAfterRollback);
    if (! $companyRestored) {
        throw new RuntimeException('Gate1 close-controls changed the exact company-row baseline after rollback.');
    }
    $receipt = ['ci_run_id' => getenv('GATE1_CI_RUN_ID') ?: null, 'database' => $database.'@'.$identity->server_address.':'.(int) $identity->port,
        'assertions' => $passes, 'results' => $results,
        'rolled_back' => true, 'before_counts' => $before, 'after_counts' => $afterRollback,
        'test_fixture_counts_before_outer_rollback' => $after,
        'company_profile' => ['synthetic_legal_identity_inside_outer_transaction' => true, 'exact_baseline_restored' => $companyRestored]];
    echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    echo 'RESULT all_pass=true assertions='.$passes.PHP_EOL;
    $passPath = $ciTarget ? $root.'/.tmp/ci-postgres/gate1-close-controls-'.$ciRunId.'.json'
        : $root.'/docs/release/remediation/evidence/gate1/'.($database === 'erp_elec_new_test' ? 'gate1-close-controls-pass.json' : 'gate1-close-controls-race-clone-pass.json');
    if (! is_dir(dirname($passPath))) {
        mkdir(dirname($passPath), 0770, true);
    }
    file_put_contents($passPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
} catch (Throwable $error) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $receipt = ['ci_run_id' => getenv('GATE1_CI_RUN_ID') ?: null, 'database' => $database.'@'.$identity->server_address.':'.(int) $identity->port,
        'result' => 'FAIL', 'assertions' => $passes, 'results' => $results,
        'error_class' => $error::class, 'error' => mb_substr($error->getMessage(), 0, 1200),
        'company_profile' => ['synthetic_legal_identity_inside_outer_transaction' => true,
            'exact_baseline_restored' => isset($companyRestored) ? $companyRestored : null]];
    $failPath = $ciTarget ? $root.'/.tmp/ci-postgres/gate1-close-controls-'.$ciRunId.'-fail.json'
        : $root.'/docs/release/remediation/evidence/gate1/'.($database === 'erp_elec_new_test' ? 'gate1-close-controls-fail.json' : 'gate1-close-controls-race-clone-fail.json');
    if (! is_dir(dirname($failPath))) {
        mkdir(dirname($failPath), 0770, true);
    }
    file_put_contents($failPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    fwrite(STDERR, 'FAIL '.$error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
}
