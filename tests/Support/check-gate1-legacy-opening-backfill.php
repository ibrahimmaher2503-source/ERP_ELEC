<?php

// Controlled source-history reproduction on one newly named, privately marked PostgreSQL database.
// Loads only the hash-verified pre-fix producer from the Gate0 archive; application files are never changed.
use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\ReceivablesPayables\Application\PostOpeningPayables;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$clusterRoot = $root.'/.tmp/master-remediation-20261101';
$clusterData = realpath($clusterRoot.'/pgdata');
$markerPath = $clusterRoot.'/pgdata/.codex-audit-owned.txt';
$expectedMarker = 'master-remediation-20261101 Gate1 private PostgreSQL marker';
$databaseName = 'erp_elec_gate1_legacy';
$expectedSourceHash = '8D3AFDEA9D7802395231ADB2300EB97F4D71BC7C96D1A4A521CB0B20618C4781';
$expectedSourceBytes = 1497;
$sourceZip = $clusterRoot.'/checkpoint/source-before.zip';
$sourceEntry = 'app/Modules/ReceivablesPayables/Application/PostOpeningPayables.php';
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate1/historical';
$evidencePath = $evidenceDirectory.'/legacy-opening-backfill.json';
$privateRoot = $clusterRoot.'/legacy-opening-private-runtime';
$cacheRoot = $privateRoot.'/framework-cache';
$historicalSourceDirectory = $privateRoot.'/historical-source';
$historicalSourcePath = $historicalSourceDirectory.'/PostOpeningPayables.php';
$legacyStage = 'private runtime setup';
set_exception_handler(static function (Throwable $exception) use (&$legacyStage, $evidenceDirectory, $databaseName): void {
    try {
        if (! is_dir($evidenceDirectory)) {
            mkdir($evidenceDirectory, 0770, true);
        }
        $attemptPath = $evidenceDirectory.'/legacy-opening-backfill-attempt-'.gmdate('Ymd\\THis\\Z').'-'.getmypid().'.json';
        $attempt = ['all_pass' => false, 'database' => $databaseName, 'stage' => $legacyStage,
            'error_class' => $exception::class, 'error' => mb_substr($exception->getMessage(), 0, 1200),
            'evidence_path' => $attemptPath];
        file_put_contents($attemptPath, json_encode($attempt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    } catch (Throwable) {
        // Keep the original failure visible even if evidence storage itself is unavailable.
    }
    fwrite(STDERR, 'FAIL stage='.$legacyStage.' '.$exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

foreach ([$privateRoot, $cacheRoot, $privateRoot.'/storage/app', $privateRoot.'/storage/framework/cache',
    $privateRoot.'/storage/framework/sessions', $privateRoot.'/storage/framework/views', $privateRoot.'/storage/logs', $historicalSourceDirectory] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create private legacy-probe runtime directory.');
    }
}

$setEnv = static function (string $key, string $value): void {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
};
$setEnv('APP_ENV', 'testing');
$setEnv('APP_DEBUG', 'false');
$setEnv('APP_KEY', 'base64:'.base64_encode(random_bytes(32)));
$relativeCacheRoot = '.tmp/master-remediation-20261101/legacy-opening-private-runtime/framework-cache';
$setEnv('APP_CONFIG_CACHE', $relativeCacheRoot.'/config.php');
$setEnv('APP_ROUTES_CACHE', $relativeCacheRoot.'/routes.php');
$setEnv('APP_EVENTS_CACHE', $relativeCacheRoot.'/events.php');
$setEnv('APP_SERVICES_CACHE', $relativeCacheRoot.'/services.php');
$setEnv('APP_PACKAGES_CACHE', $relativeCacheRoot.'/packages.php');
$setEnv('APP_VIEW_COMPILED_PATH', $privateRoot.'/storage/framework/views');
$setEnv('DB_CONNECTION', 'pgsql');
$setEnv('DB_HOST', '127.0.0.1');
$setEnv('DB_PORT', '49685');
$setEnv('DB_DATABASE', 'postgres');
$setEnv('DB_USERNAME', 'postgres');
$setEnv('DB_PASSWORD', '');
$setEnv('DB_URL', '');
$setEnv('MAIL_MAILER', 'array');
$setEnv('CACHE_STORE', 'array');
$setEnv('SESSION_DRIVER', 'array');
$setEnv('QUEUE_CONNECTION', 'sync');

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($privateRoot.'/storage');
$app->make(Kernel::class)->bootstrap();
config(['app.debug' => false, 'database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 49685, 'database.connections.pgsql.database' => 'postgres',
    'database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => '',
    'database.connections.pgsql.url' => null, 'mail.default' => 'array', 'cache.default' => 'array',
    'session.driver' => 'array', 'queue.default' => 'sync', 'pulse.enabled' => false]);
DB::purge('pgsql');

$legacyStage = 'marked cluster identity verification';
if (! $clusterData || ! is_file($markerPath) || trim((string) file_get_contents($markerPath)) !== $expectedMarker) {
    throw new RuntimeException('ABORT before any database write: private Gate1 PostgreSQL ownership marker is absent or mismatched.');
}
$clusterIdentity = DB::selectOne("select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port, current_setting('data_directory') as data_directory");
$actualData = realpath((string) $clusterIdentity->data_directory) ?: (string) $clusterIdentity->data_directory;
if ($clusterIdentity->db !== 'postgres' || ! in_array($clusterIdentity->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || (int) $clusterIdentity->port !== 49685 || $actualData !== $clusterData
    || config('database.connections.pgsql.database') !== 'postgres' || config('database.connections.pgsql.url')) {
    throw new RuntimeException('ABORT before writes: expected the marked 127.0.0.1:49685 cluster identity.');
}
$legacyStage = 'verify exact archived pre-fix source';
$archive = new ZipArchive;
if ($archive->open($sourceZip) !== true) {
    throw new RuntimeException('Could not open the preserved Gate0 source archive.');
}
$source = $archive->getFromName($sourceEntry);
$archive->close();
if (! is_string($source) || strlen($source) !== $expectedSourceBytes || strtoupper(hash('sha256', $source)) !== $expectedSourceHash) {
    throw new RuntimeException('ABORT: archived PostOpeningPayables source entry does not match the exact approved pre-fix hash/byte count.');
}
if (is_file($historicalSourcePath)) {
    if (filesize($historicalSourcePath) !== $expectedSourceBytes || strtoupper(hash_file('sha256', $historicalSourcePath)) !== $expectedSourceHash) {
        throw new RuntimeException('ABORT: an existing private historical source file has a different hash; it will not be overwritten.');
    }
} elseif (file_put_contents($historicalSourcePath, $source, LOCK_EX) !== $expectedSourceBytes) {
    throw new RuntimeException('Could not extract the verified historical producer into its private test-only path.');
}
$class = PostOpeningPayables::class;
if (class_exists($class, false)) {
    throw new RuntimeException('ABORT: current producer was loaded before the verified historical source could be installed.');
}
require_once $historicalSourcePath;
$priorFixtureReset = null;
$alreadyExists = DB::selectOne('select 1 from pg_database where datname = ?', [$databaseName]);
if ($alreadyExists) {
    // Narrow retry path for the preserved failed run: reset only this exact
    // synthetic DB after proving its identity and absence of posting history.
    $legacyStage = 'prove prior failed fixture is safe to recreate';
    $failureReceipts = glob($evidenceDirectory.'/legacy-opening-backfill-attempt-*.json') ?: [];
    $hasExpectedFailureReceipt = false;
    foreach ($failureReceipts as $failureReceiptPath) {
        $failureReceipt = json_decode((string) file_get_contents($failureReceiptPath), true);
        if (($failureReceipt['database'] ?? null) === $databaseName
            && ($failureReceipt['old_source_sha256'] ?? null) === $expectedSourceHash
            && ($failureReceipt['migrations_through_199'] ?? false) === true
            && ($failureReceipt['migration_200_applied'] ?? true) === false
            && ($failureReceipt['fixture_state']['opening_batches'] ?? -1) === 0
            && ($failureReceipt['fixture_state']['accounting_journals'] ?? -1) === 0) {
            $hasExpectedFailureReceipt = true;
            break;
        }
    }
    if (! $hasExpectedFailureReceipt) {
        throw new RuntimeException('ABORT: existing legacy target lacks the preserved exact-source failure receipt proving the expected no-posting fixture state.');
    }
    $setEnv('DB_DATABASE', $databaseName);
    config(['database.connections.pgsql.database' => $databaseName]);
    DB::purge('pgsql');
    $existingIdentity = DB::selectOne("select current_database() as db, host(inet_server_addr()) as address, inet_server_port() as port, current_setting('data_directory') as data_directory");
    $existingData = realpath((string) $existingIdentity->data_directory) ?: (string) $existingIdentity->data_directory;
    if ($existingIdentity->db !== $databaseName || ! in_array($existingIdentity->address, ['127.0.0.1', '127.0.0.1/32'], true)
        || (int) $existingIdentity->port !== 49685 || $existingData !== $clusterData
        || DB::table('migrations')->where('migration', '2026_11_01_199000_record_financial_period_closures')->doesntExist()
        || DB::table('migrations')->where('migration', '2026_11_01_200000_link_unmapped_payable_openings')->exists()
        || DB::table('companies')->where('code', 'G1-LEGACY-OPENING')->count() !== 1
        || DB::table('branches')->where('code', 'like', 'G1-LEGACY-%')->count() !== 1
        || DB::table('users')->count() !== 2 || DB::table('suppliers')->count() !== 1
        || DB::table('accounting_accounts')->count() !== 16 || DB::table('accounting_account_mappings')->count() !== 15
        || DB::table('accounting_tax_rules')->count() !== 2 || DB::table('accounting_periods')->count() !== 1) {
        throw new RuntimeException('ABORT: existing target does not match its exact marked, through-199 synthetic fixture identity.');
    }
    $financialTables = ['accounting_opening_batches', 'accounting_journals', 'accounting_journal_lines', 'payable_opening_items',
        'rp_open_items', 'rp_settlement_allocations', 'treasury_movements', 'inventory_movements', 'inventory_fifo_layers',
        'purchasing_receipt_postings', 'customer_invoice_postings', 'supplier_invoice_postings'];
    $financialCounts = [];
    foreach ($financialTables as $table) {
        $exists = DB::selectOne('select to_regclass(?) as name', ['public.'.$table])->name;
        if ($exists !== null) {
            $financialCounts[$table] = DB::table($table)->count();
        }
    }
    if (array_filter($financialCounts, static fn (int $count): bool => $count !== 0) !== []) {
        throw new RuntimeException('ABORT: existing target contains financial or inventory history and will not be reset: '.json_encode($financialCounts));
    }
    $priorFixtureReset = ['performed' => true, 'authorized_by' => 'root task owner', 'database_identity' => $databaseName,
        'marker_verified' => true, 'migrations_through_199' => true, 'migration_200_absent' => true,
        'preserved_failure_receipt' => basename($failureReceiptPath), 'financial_and_inventory_history_counts' => $financialCounts];
    DB::purge('pgsql');
    $setEnv('DB_DATABASE', 'postgres');
    config(['database.connections.pgsql.database' => 'postgres']);
    DB::purge('pgsql');
    DB::statement('DROP DATABASE erp_elec_gate1_legacy');
}
$legacyStage = 'create only dedicated legacy database';
DB::statement('CREATE DATABASE erp_elec_gate1_legacy');

$setEnv('DB_DATABASE', $databaseName);
config(['database.connections.pgsql.database' => $databaseName]);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port, current_setting('data_directory') as data_directory");
$actualData = realpath((string) $identity->data_directory) ?: (string) $identity->data_directory;
if ($identity->db !== $databaseName || ! in_array($identity->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || (int) $identity->port !== 49685 || $actualData !== $clusterData
    || config('database.connections.pgsql.database') !== $databaseName || config('database.connections.pgsql.url')) {
    throw new RuntimeException('ABORT before schema/fixture writes: dedicated DB is not inside the exact marked cluster.');
}

$allMigrationFiles = glob($root.'/database/migrations/*_*.php') ?: [];
$legacyStage = 'migrate isolated database through schema 199 only';
$legacyFiles = array_values(array_filter($allMigrationFiles, static function (string $file): bool {
    $name = basename($file);

    return strcmp($name, '2026_11_01_200000_link_unmapped_payable_openings.php') < 0;
}));
if (! in_array($root.'/database/migrations/2026_11_01_199000_record_financial_period_closures.php', $legacyFiles, true)
    || in_array($root.'/database/migrations/2026_11_01_200000_link_unmapped_payable_openings.php', $legacyFiles, true)) {
    throw new RuntimeException('ABORT: expected schema migration list through 199 only, excluding 200.');
}
Artisan::call('migrate:install', ['--database' => 'pgsql']);
Artisan::call('migrate', ['--database' => 'pgsql', '--path' => $legacyFiles, '--realpath' => true, '--force' => true]);
$migrationOutput = Artisan::output();
$ran199 = DB::table('migrations')->where('migration', '2026_11_01_199000_record_financial_period_closures')->exists();
$ran200 = DB::table('migrations')->where('migration', '2026_11_01_200000_link_unmapped_payable_openings')->exists();
if (! $ran199 || $ran200) {
    throw new RuntimeException('ABORT before fixture writes: isolated legacy schema must be migrated through 199 and exclude 200. '.$migrationOutput);
}

$grant = static function (User $actor, array $codes, ?int $branch = null): void {
    foreach ($codes as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => $code, 'branch_id' => $branch,
            'created_at' => now(), 'updated_at' => now()]);
    }
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$uuid = static fn (): string => (string) Str::uuid();
$legacyStage = 'synthetic owners and valid finance configuration';
$maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
$reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
$grant($maker, ['M01.company_update', 'M01.company_view', 'M03.supplier_view', 'M11.setup_manage', 'M11.account_manage', 'M11.tax_manage',
    'M11.opening_manage', 'M11.financial_activate', 'M11.tax_calculate', 'M11.period_open', 'M13.account_manage']);
$grant($reviewer, ['M01.company_view', 'M03.supplier_view', 'M11.financial_activate']);
app(SaveCompany::class)->execute($maker, ['code' => 'G1-LEGACY-OPENING', 'name' => 'Gate1 isolated legacy acceptance',
    'legal_name' => 'Gate1 isolated legacy acceptance', 'tax_registration' => 'TEST-G1-LEGACY', 'address' => 'Synthetic test-only address'], null);
$branch = Branch::factory()->create(['code' => 'G1-LEGACY-'.strtoupper(Str::random(6))]);
$supplier = Supplier::factory()->create(['status' => 'active']);
foreach ([$maker, $reviewer] as $actor) {
    DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id,
        'created_at' => now(), 'updated_at' => now()]);
}
$openingEntryDefinition = PermissionCatalog::DEFINITIONS['M11.opening_entry'] ?? null;
$assert(is_array($openingEntryDefinition) && ($openingEntryDefinition[1] ?? null) === 'branch',
    'Permission catalog no longer identifies M11.opening_entry as branch-scoped.');
$grant($reviewer, ['M01.branch_view', 'M11.opening_review', 'M11.opening_approve']);
$grant($maker, ['M01.branch_view', 'M11.opening_entry', 'M11.opening_review', 'M12.ap_view', 'M13.payment_prepare', 'M13.payment_execute', 'M11.source_post'], (int) $branch->id);
$grant($reviewer, ['M01.branch_view', 'M11.opening_review', 'M12.ap_view', 'M13.payment_approve', 'M13.payment_execute'], (int) $branch->id);

$accounts = app(AccountingActions::class);
$mappingIds = [];
foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
    $mappingIds[$purpose] = $accounts->account((int) $maker->id, null, 0, ['code' => 'G1L-'.$purpose,
        'name' => 'Gate1 legacy '.$label, 'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]);
}
$setup = app(FinancialActivation::class);
$setupVersion = (int) DB::table('accounting_activation')->where('id', 1)->value('version');
$setup->save((int) $maker->id, $setupVersion, ['go_live_date' => '2024-06-01', 'mappings' => $mappingIds]);
$setupVersion++;
foreach (['sale', 'purchase'] as $type) {
    $setup->addTaxRule((int) $maker->id, $setupVersion, ['code' => 'G1L-'.$type, 'name' => 'Gate1 synthetic '.$type,
        'transaction_type' => $type, 'product_id' => null, 'rate' => '14', 'starts_on' => '2024-06-01', 'ends_on' => null,
        'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Isolated Gate1 financial test fixture',
        'purchase_recoverable' => $type === 'purchase']);
    $setupVersion++;
}
$setup->validateTaxTreatment((int) $maker->id, $setupVersion, 'Isolated Gate1 test rule validation');
$setupVersion++;
$accounts->period((int) $maker->id, ['name' => 'Gate1 legacy test open period', 'starts_on' => '2024-01-01', 'ends_on' => '2024-12-31']);
$equity = $accounts->account((int) $maker->id, null, 0, ['code' => 'G1L-EQUITY', 'name' => 'Gate1 synthetic opening equity',
    'kind' => 'equity', 'parent_id' => null, 'postable' => true, 'active' => true]);

Carbon::setTestNow(CarbonImmutable::parse('2024-06-01 12:00:00', 'Africa/Cairo'));
$openingLines = [
    ['kind' => 'gl', 'reference' => 'G1-CASH', 'branch_id' => (int) $branch->id, 'account_id' => $mappingIds['cash'], 'debit' => '10.00', 'credit' => '0.00'],
    ['kind' => 'gl', 'reference' => 'G1-AP', 'branch_id' => (int) $branch->id, 'account_id' => $mappingIds['ap'], 'debit' => '0.00', 'credit' => '10.00'],
    ['kind' => 'cash', 'reference' => 'G1-CASH-OPEN', 'branch_id' => (int) $branch->id, 'amount' => '10.00'],
    ['kind' => 'ap', 'reference' => 'G1-LEGACY-AP-REFERENCE', 'branch_id' => (int) $branch->id, 'supplier_id' => (int) $supplier->id, 'amount' => '10.00'],
];
$opening = app(OpeningBalances::class);
$legacyStage = 'public opening save, independent approval and activation through historical producer';
$batch = $opening->save((int) $maker->id, $setupVersion, $uuid(), $openingLines, false, 'Genuine controlled pre-fix source-path reproduction');
$opening->advance((int) $maker->id, $batch, 1, 'submit');
$opening->advance((int) $reviewer->id, $batch, 2, 'approve');
app(ActivateFinancialPosting::class)->execute((int) $reviewer->id, $batch, 3);
$payable = DB::table('payable_opening_items')->where('source_key', 'opening:'.$batch.':G1-LEGACY-AP-REFERENCE')->first();
$openingJournal = DB::table('accounting_journals')->where('opening_batch_id', $batch)->where('status', 'posted')->first();
$assert($payable && $payable->amount === '10.00' && $payable->opening_date === '2024-06-01' && $openingJournal,
    'Old opening action did not produce the expected real payable source and posted GL journal.');
$assert(DB::table('rp_open_items')->where('source_type', 'opening_payable')->where('source_id', $payable->id)->doesntExist(),
    'Historical old producer unexpectedly created the M12 open-item link; this is not the source bug reproduction.');
$glLinesBefore = DB::table('accounting_journal_lines')->where('journal_id', $openingJournal->id)->orderBy('id')->get()->map(fn ($row) => [
    'account_id' => (int) $row->account_id, 'debit' => (string) $row->debit, 'credit' => (string) $row->credit])->all();
$glAmountBefore = DB::table('accounting_journal_lines')->where('journal_id', $openingJournal->id)->selectRaw('sum(debit) d, sum(credit) c')->first();
$assert(bccomp((string) $glAmountBefore->d, '10.00', 2) === 0 && bccomp((string) $glAmountBefore->c, '10.00', 2) === 0,
    'Actual opening GL journal was not balanced at 10.00.');
$openingSourceBefore = ['id' => (int) $payable->id, 'branch_id' => (int) $payable->branch_id, 'supplier_id' => (int) $payable->supplier_id,
    'reference' => $payable->reference, 'opening_date' => $payable->opening_date, 'amount' => (string) $payable->amount,
    'journal_id' => (int) $openingJournal->id, 'journal_lines' => $glLinesBefore];
$openingEvidenceBefore = DB::table('accounting_opening_batches')->where('id', $batch)
    ->first(['status', 'configuration_version', 'evidence_reference', 'created_by', 'approved_by', 'posted_at', 'version']);
$assert($openingEvidenceBefore && $openingEvidenceBefore->status === 'posted' && $openingEvidenceBefore->evidence_reference === 'Genuine controlled pre-fix source-path reproduction',
    'Public independent opening approval/activation evidence is missing before migration.');

$migrationPath = $root.'/database/migrations/2026_11_01_200000_link_unmapped_payable_openings.php';
$legacyStage = 'apply migration 200 and verify genuine source link';
Artisan::call('migrate', ['--database' => 'pgsql', '--path' => [$migrationPath], '--realpath' => true, '--force' => true]);
$migrationOutput = Artisan::output();
$assert(DB::table('migrations')->where('migration', '2026_11_01_200000_link_unmapped_payable_openings')->exists(),
    'Laravel did not record normal migration 200 application. '.$migrationOutput);
$mapped = DB::table('rp_open_items')->where('source_type', 'opening_payable')->where('source_id', $payable->id)->get();
$assert($mapped->count() === 1, 'Migration 200 did not create exactly one M12 item for the genuine old opening source.');
$item = $mapped->first();
$assert($item->source_number === $payable->reference && $item->document_date === '2024-06-01' && $item->due_date === '2024-06-01'
    && (int) $item->branch_id === (int) $payable->branch_id && $item->party_type === 'supplier' && (int) $item->party_id === (int) $payable->supplier_id
    && $item->original_amount === '10.00' && $item->settled_amount === '0.00' && $item->remaining_amount === '10.00',
    'Migration 200 link did not preserve exact genuine source identity, amount and opening business date.');
$journalAfterMigration = DB::table('accounting_journals')->where('opening_batch_id', $batch)->where('status', 'posted')->first();
$payableAfterMigration = DB::table('payable_opening_items')->find($payable->id);
$openingEvidenceAfter = DB::table('accounting_opening_batches')->where('id', $batch)
    ->first(['status', 'configuration_version', 'evidence_reference', 'created_by', 'approved_by', 'posted_at', 'version']);
$linesAfterMigration = DB::table('accounting_journal_lines')->where('journal_id', $openingJournal->id)->orderBy('id')->get()->map(fn ($row) => [
    'account_id' => (int) $row->account_id, 'debit' => (string) $row->debit, 'credit' => (string) $row->credit])->all();
$assert($journalAfterMigration && (int) $journalAfterMigration->id === (int) $openingJournal->id
    && $linesAfterMigration === $glLinesBefore && $payableAfterMigration
    && ['branch_id' => (int) $payableAfterMigration->branch_id, 'supplier_id' => (int) $payableAfterMigration->supplier_id,
        'reference' => $payableAfterMigration->reference, 'opening_date' => $payableAfterMigration->opening_date, 'amount' => (string) $payableAfterMigration->amount]
        === ['branch_id' => $openingSourceBefore['branch_id'], 'supplier_id' => $openingSourceBefore['supplier_id'],
            'reference' => $openingSourceBefore['reference'], 'opening_date' => $openingSourceBefore['opening_date'], 'amount' => $openingSourceBefore['amount']]
    && $openingEvidenceAfter && (array) $openingEvidenceAfter === (array) $openingEvidenceBefore,
    'Migration 200 changed the existing posted GL journal, payable source values/dates, or public opening evidence.');

$replay = require $migrationPath;
$replay->up();
$replay->up();
$assert(DB::table('rp_open_items')->where('source_type', 'opening_payable')->where('source_id', $payable->id)->count() === 1
    && DB::table('accounting_journals')->where('opening_batch_id', $batch)->count() === 1,
    'Repeated migration 200 up() created duplicate source items or journals.');

$treasury = app(TreasuryApi::class);
$legacyStage = 'real Treasury partial settlement and migration replays';
$cashAccount = $treasury->saveOperationalAccount((int) $maker->id, ['code' => 'G1L-CASH', 'name' => 'Gate1 isolated test cash',
    'kind' => 'cash', 'gl_account_id' => $mappingIds['cash']]);
$payment = $treasury->prepareSupplierPayment((int) $maker->id, ['creation_key' => $uuid(), 'branch_id' => (int) $branch->id,
    'supplier_id' => (int) $supplier->id, 'account_id' => $cashAccount, 'request_date' => '2024-06-02', 'amount' => '3.00',
    'reference' => 'Gate1 legacy opening partial settlement']);
$treasury->approveSupplierPayment((int) $reviewer->id, $payment, 1);
$settlement = $treasury->executeSupplierPayment((int) $maker->id, $payment, 2, $uuid(), [['open_item_id' => (int) $item->id, 'amount' => '3.00']]);
$settled = DB::table('rp_open_items')->find($item->id);
$assert($settled && $settled->original_amount === '10.00' && $settled->settled_amount === '3.00' && $settled->remaining_amount === '7.00'
    && DB::table('rp_settlement_allocations')->where('movement_id', $settlement['movement_id'])->where('open_item_id', $item->id)->where('amount', '3.00')->exists(),
    'Real owner Treasury payment did not partially settle the exact migrated opening source.');
$movementCount = DB::table('treasury_movements')->count();
$journalCount = DB::table('accounting_journals')->count();
$replay->up();
$settledAfterReplay = DB::table('rp_open_items')->find($item->id);
$assert(DB::table('rp_open_items')->where('source_type', 'opening_payable')->where('source_id', $payable->id)->count() === 1
    && $settledAfterReplay && $settledAfterReplay->settled_amount === '3.00' && $settledAfterReplay->remaining_amount === '7.00'
    && DB::table('rp_settlement_allocations')->where('movement_id', $settlement['movement_id'])->count() === 1
    && DB::table('treasury_movements')->count() === $movementCount && DB::table('accounting_journals')->count() === $journalCount,
    'Migration replay after settlement changed the source balance, duplicated settlement, treasury movement, or journal.');

$receipt = ['all_pass' => true, 'scope' => 'controlled historical producer reproduction in a dedicated isolated DB; not a production upgrade test',
    'cluster' => ['address' => $identity->address, 'port' => (int) $identity->port, 'data_directory' => $actualData, 'marker_verified' => true],
    'database' => $databaseName, 'schema_through_199_only_before_fixture' => true,
    'old_producer' => ['archive' => '.tmp/master-remediation-20261101/checkpoint/source-before.zip', 'inventory_provenance' => '.tmp/master-remediation-20261101/checkpoint/source-inventory.json', 'entry' => $sourceEntry,
        'sha256' => $expectedSourceHash, 'bytes' => $expectedSourceBytes, 'private_test_copy' => '.tmp/master-remediation-20261101/legacy-opening-private-runtime/historical-source/PostOpeningPayables.php',
        'loaded_without_modifying_application_file' => true],
    'legacy_source_before_migration_200' => ['opening_source' => $openingSourceBefore,
        'opening_batch_evidence_reference' => $openingEvidenceBefore->evidence_reference, 'm12_link_count' => 0, 'journal_debit' => (string) $glAmountBefore->d,
        'journal_credit' => (string) $glAmountBefore->c, 'genuine_public_actions' => ['OpeningBalances::save', 'advance submit', 'independent advance approve', 'ActivateFinancialPosting::execute']],
    'migration_200' => ['applied_by_laravel_migrator' => true, 'migration_row_recorded' => true, 'replay_up_calls_before_payment' => 2,
        'replay_up_calls_after_payment' => 1, 'exact_source_link_count' => 1, 'opening_item_id' => (int) $item->id,
        'original' => '10.00', 'settled' => '3.00', 'remaining' => '7.00', 'document_date' => $item->document_date,
        'due_date' => $item->due_date, 'gl_and_opening_source_unchanged' => true, 'treasury_journal_counts_unchanged_on_replays' => true],
    'settlement' => ['actor_action' => 'TreasuryApi::prepareSupplierPayment/approveSupplierPayment/executeSupplierPayment',
        'movement_id' => (int) $settlement['movement_id'], 'journal_id' => (int) $settlement['journal_id']],
    'prior_fixture_reset' => $priorFixtureReset,
    'schema_migration_output_excerpt' => mb_substr(trim($migrationOutput), 0, 800)];
if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
    throw new RuntimeException('Could not create Gate1 historical evidence directory.');
}
file_put_contents($evidencePath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
fwrite(STDOUT, 'RESULT all_pass=true database='.$databaseName.' port=49685 fixture_committed=true evidence='.$evidencePath.PHP_EOL);
