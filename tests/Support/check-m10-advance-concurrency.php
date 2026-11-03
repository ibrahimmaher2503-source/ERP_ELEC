<?php

declare(strict_types=1);

// M12 advance application and M13 refund contend for the same last 30.00 in an owned disposable PostgreSQL database.
use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\{AccountingActions, FinancialActivation, OpeningBalances};
use App\Modules\Organization\Domain\{Branch, Company, Employee};
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ServiceContracts\Application\{ServiceContractActions, ServiceContractBilling, ServiceContractDocuments};
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
putenv('APP_ENV=testing'); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';
$primaryEnv = ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'), 'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'), 'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$primary = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $primaryEnv);
if ($primary['port'] !== 49685 || $primary['database'] !== 'erp_elec_new_test' || $primary['username'] !== 'postgres') {
    throw new RuntimeException('Advance race requires the exact marker-owned PostgreSQL 17 test target.');
}
$nonce = bin2hex(random_bytes(32));
$database = 'erp_elec_new_m10_advance_'.substr($nonce, 0, 16);
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$private = $taskRoot.'/m10-advance-'.$nonce;
if (! mkdir($private, 0700, true)) {
    throw new RuntimeException('Cannot create private advance race artifact directory.');
}
$receiptPath = $private.'/ownership.json';
$failurePath = $private.'/failure.json';
$cleanupPath = $private.'/cleanup.json';
$runtime = $private.'/runtime';
foreach ([$runtime.'/storage', $runtime.'/cache', $runtime.'/views', $runtime.'/private', $private.'/barrier'] as $directory) {
    if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Cannot create private advance race runtime directory.');
    }
}
$dsn = static fn (string $db): string => 'pgsql:host='.$primary['connection_host'].';port='.$primary['port'].';dbname='.$db;
$password = (string) (getenv('DB_PASSWORD') ?: '');
$stage = 'verify owned PostgreSQL primary';
$dropped = false;
$failed = false;
$cleanupRecorded = false;
$primaryCountsUnchanged = null;
$admin = new PDO($dsn('postgres'), $primary['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$actualPrimary = $admin->query("select current_database() as database,host(inet_server_addr()) as server_address,inet_server_port() as port,
    current_setting('data_directory') as data_directory,current_user as current_user,current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner")->fetch(PDO::FETCH_OBJ);
if ($actualPrimary->database !== 'postgres' || $actualPrimary->server_address !== $primary['server_address'] || (int) $actualPrimary->port !== $primary['port']
    || $actualPrimary->current_user !== $primary['username'] || $actualPrimary->database_owner !== $primary['username']
    || (int) $actualPrimary->server_version_num < 170000 || (int) $actualPrimary->server_version_num >= 180000
    || realpath($actualPrimary->data_directory) !== realpath($primary['data_directory'])) {
    throw new RuntimeException('Refusing disposable advance database creation outside the marker-owned PostgreSQL cluster.');
}
$primaryConnection = new PDO($dsn($primary['database']), $primary['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$primaryIdentity = $primaryConnection->query("select current_database() as database,host(inet_server_addr()) as server_address,inet_server_port() as port,
    current_setting('data_directory') as data_directory,current_user as current_user,current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner")->fetch(PDO::FETCH_OBJ);
if ($primaryIdentity->database !== $primary['database'] || $primaryIdentity->server_address !== $primary['server_address']
    || (int) $primaryIdentity->port !== $primary['port'] || $primaryIdentity->current_user !== $primary['username']
    || $primaryIdentity->database_owner !== $primary['username'] || (int) $primaryIdentity->server_version_num < 170000
    || (int) $primaryIdentity->server_version_num >= 180000 || realpath($primaryIdentity->data_directory) !== realpath($primary['data_directory'])) {
    throw new RuntimeException('Refusing disposable advance database creation: primary live identity mismatch.');
}
$readPrimaryCounts = static function (PDO $connection): array {
    $tables = ['migrations', 'companies', 'branches', 'users', 'customers', 'products', 'organization_employees', 'access_grants',
        'user_branch_scopes', 'accounting_activation', 'accounting_journals', 'rp_customer_invoices', 'rp_customer_advances',
        'rp_customer_advance_applications', 'rp_customer_advance_refunds', 'treasury_movements', 'audit_entries'];
    $counts = [];
    foreach ($tables as $table) {
        $check = $connection->prepare('select to_regclass(?)');
        $check->execute(['public.'.$table]);
        if ($check->fetchColumn()) {
            $counts[$table] = (int) $connection->query('select count(*) from public."'.$table.'"')->fetchColumn();
        }
    }
    return $counts;
};
$primaryCounts = $readPrimaryCounts($primaryConnection);
$primaryConnection = null;
$receipt = ['database' => $database, 'port' => $primary['port'], 'server_address' => $primary['server_address'],
    'data_directory' => realpath($primary['data_directory']) ?: $primary['data_directory'], 'connection_host' => $primary['connection_host'],
    'current_user' => $primary['username'], 'nonce' => $nonce];
file_put_contents($receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
chmod($receiptPath, 0600);
$stage = 'create nonce-owned disposable database';
$admin->exec('CREATE DATABASE "'.$database.'"');
$markerDb = new PDO($dsn($database), $primary['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$markerDb->exec('CREATE SCHEMA codex_test_guard');
$markerDb->exec('CREATE TABLE codex_test_guard.ownership_receipts (database text PRIMARY KEY, nonce char(64) NOT NULL)');
$marker = $markerDb->prepare('INSERT INTO codex_test_guard.ownership_receipts(database,nonce) VALUES (?,?)');
$marker->execute([$database, $nonce]);
$markerDb = null;
$safeDrop = static function () use (&$dropped, &$primaryCountsUnchanged, $root, $primaryEnv, $primary, $database, $nonce, $dsn, $password, $admin, $primaryCounts, $readPrimaryCounts): void {
    if ($dropped || ! preg_match('/^erp_elec_new_m10_advance_[a-f0-9]{16}$/', $database)) {
        return;
    }
    $owned = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $primaryEnv);
    if ($owned['data_directory'] !== $primary['data_directory'] || $owned['port'] !== $primary['port']) {
        throw new RuntimeException('Refusing disposable advance database cleanup after primary target changed.');
    }
    $check = new PDO($dsn($database), $owned['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $live = $check->query("select current_database() as database,host(inet_server_addr()) as server_address,inet_server_port() as port,
        current_setting('data_directory') as data_directory,current_user as current_user,
        (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner,
        (select nonce from codex_test_guard.ownership_receipts where database=current_database()) as nonce")->fetch(PDO::FETCH_OBJ);
    if (! $live || $live->database !== $database || $live->server_address !== $owned['server_address'] || (int) $live->port !== $owned['port']
        || realpath($live->data_directory) !== realpath($owned['data_directory']) || $live->current_user !== $owned['username']
        || $live->database_owner !== $owned['username'] || ! hash_equals($nonce, (string) $live->nonce)) {
        throw new RuntimeException('Refusing disposable advance database drop without exact live identity and nonce.');
    }
    $admin->exec('DROP DATABASE "'.$database.'" WITH (FORCE)');
    $dropped = true;
    $after = $readPrimaryCounts(new PDO($dsn($primary['database']), $owned['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    $primaryCountsUnchanged = $after === $primaryCounts;
    if (! $primaryCountsUnchanged) {
        throw new RuntimeException('Primary database counts changed during disposable advance race acceptance.');
    }
};
$writeFailure = static function (Throwable $error) use (&$failed, &$stage, &$dropped, &$primaryCountsUnchanged, $database, $primary, $failurePath): array {
    $failed = true;
    $message = preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $error->getMessage());
    $failure = ['all_pass' => false, 'mode' => 'm10-advance-concurrency', 'phase' => $stage, 'primary_database' => $primary['database'],
        'disposable_database' => $database, 'disposable_database_dropped' => $dropped, 'primary_counts_unchanged' => $primaryCountsUnchanged,
        'failure' => ['class' => $error::class, 'message' => mb_substr((string) $message, 0, 500)]];
    if (! is_file($failurePath)) {
        file_put_contents($failurePath, json_encode($failure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
        chmod($failurePath, 0600);
    }
    return $failure;
};
$writeCleanup = static function (?Throwable $error = null) use (&$dropped, &$primaryCountsUnchanged, $database, $primary, $cleanupPath): array {
    $message = $error ? preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $error->getMessage()) : null;
    $cleanup = ['mode' => 'm10-advance-concurrency-cleanup', 'database' => $database, 'port' => $primary['port'],
        'server_address' => $primary['server_address'], 'data_directory' => $primary['data_directory'], 'current_user' => $primary['username'],
        'database_drop_status' => $dropped ? 'dropped' : 'not_verified_dropped', 'primary_counts_unchanged' => $primaryCountsUnchanged,
        'cleanup_failure' => $error ? ['class' => $error::class, 'message' => mb_substr((string) $message, 0, 500)] : null,
        'recorded_at_utc' => gmdate(DATE_ATOM)];
    file_put_contents($cleanupPath, json_encode($cleanup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    chmod($cleanupPath, 0600);
    return $cleanup;
};
set_exception_handler(static function (Throwable $error) use ($writeFailure): void {
    $failure = $writeFailure($error);
    fwrite(STDERR, json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    fwrite(STDERR, "Private advance race failure receipt preserved.\n");
    exit(1);
});
$cleanupRecorded = false;
register_shutdown_function(static function () use (&$dropped, &$cleanupRecorded, $safeDrop, $writeFailure, $writeCleanup): void {
    if ($cleanupRecorded) {
        return;
    }
    $error = null;
    if (! $dropped) {
        try { $safeDrop(); } catch (Throwable $failure) { $error = $failure; }
    }
    $cleanup = $writeCleanup($error);
    $cleanupRecorded = true;
    if ($error) {
        $writeFailure($error);
        fwrite(STDERR, json_encode($cleanup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        fwrite(STDERR, $dropped ? "Disposable advance database dropped; private artifacts preserved.\n" : "Drop not verified; private artifacts preserved.\n");
    }
});
$cacheRelative = '.tmp/master-remediation-20261101/m10-advance-'.$nonce.'/runtime/cache';
$viewsRelative = '.tmp/master-remediation-20261101/m10-advance-'.$nonce.'/runtime/views';
$baseEnv = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $primary['connection_host'], 'DB_PORT' => (string) $primary['port'],
    'DB_DATABASE' => $database, 'DB_USERNAME' => $primary['username'], 'DB_PASSWORD' => $password, 'DB_URL' => '', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'LARAVEL_STORAGE_PATH' => $runtime.'/storage',
    'APP_CONFIG_CACHE' => $cacheRelative.'/config.php', 'APP_ROUTES_CACHE' => $cacheRelative.'/routes.php', 'APP_EVENTS_CACHE' => $cacheRelative.'/events.php',
    'APP_SERVICES_CACHE' => $cacheRelative.'/services.php', 'APP_PACKAGES_CACHE' => $cacheRelative.'/packages.php',
    'APP_VIEW_COMPILED_PATH' => $viewsRelative, 'VIEW_COMPILED_PATH' => $viewsRelative, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'];
$stage = 'migrate disposable advance database';
$migrate = new Process([PHP_BINARY, $root.'/artisan', 'migrate', '--force', '--no-interaction', '--env=testing'], $root, $baseEnv, null, 300);
$migrate->run();
file_put_contents($private.'/migration-output.log', $migrate->getOutput().$migrate->getErrorOutput(), LOCK_EX);
chmod($private.'/migration-output.log', 0600);
if (! $migrate->isSuccessful() || ! preg_match('/^.*\bDONE\r?$/m', $migrate->getOutput())) {
    throw new RuntimeException('Disposable advance migrations failed or omitted the completion marker.');
}
foreach ($baseEnv as $key => $value) { putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value; }
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing'); $app->useStoragePath($runtime.'/storage'); $app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $primary['connection_host'], 'database.connections.pgsql.port' => $primary['port'],
    'database.connections.pgsql.database' => $database, 'database.connections.pgsql.username' => $primary['username'], 'database.connections.pgsql.password' => $password,
    'database.connections.pgsql.url' => null, 'filesystems.disks.local.root' => $runtime.'/private', 'filesystems.disks.local.visibility' => 'private',
    'filesystems.disks.local.throw' => true, 'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database,host(inet_server_addr()) as server_address,inet_server_port() as port,
    current_setting('data_directory') as data_directory,current_user as current_user,current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner,
    (select nonce from codex_test_guard.ownership_receipts where database=current_database()) as nonce");
if ($identity->database !== $database || $identity->server_address !== $primary['server_address'] || (int) $identity->port !== $primary['port']
    || $identity->current_user !== $primary['username'] || $identity->database_owner !== $primary['username']
    || realpath($identity->data_directory) !== realpath($primary['data_directory']) || $identity->nonce !== $nonce
    || (int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
    throw new RuntimeException('Disposable advance database identity or ownership nonce mismatch.');
}
$required = ['2026_11_01_201000_create_organization_employees', '2026_11_01_202000_create_service_contracts',
    '2026_11_01_203000_link_service_contract_work', '2026_11_01_207000_separate_contract_source_identity_guards',
    '2026_11_01_208000_create_service_contract_billing', '2026_11_01_209000_allow_contract_invoice_source_lines',
    '2026_11_01_210000_create_contract_preventive_dispatches', '2026_11_01_211000_create_customer_advance_lifecycle'];
if (DB::table('migrations')->whereIn('migration', $required)->count() !== count($required)
    || ! DB::getSchemaBuilder()->hasTable('rp_customer_advance_applications') || ! DB::getSchemaBuilder()->hasTable('rp_customer_advance_refunds')) {
    throw new RuntimeException('Disposable advance schema did not apply M10 billing and M12/M13 advance lifecycle migrations.');
}
$stage = 'create genuine M11/M10/M12/Treasury race sources';
$actors = [];
$workers = [];
$error = null;
$result = null;
try {
    DB::beginTransaction();
    $tag = 'M10-ADV-RACE-'.strtoupper(bin2hex(random_bytes(4)));
    Company::factory()->create(['id' => 1, 'name' => 'Synthetic advance race entity', 'legal_name' => 'Synthetic advance race entity Ltd.',
        'address' => 'Disposable rollback-only race fixture', 'setup_complete' => true]);
    $branch = Branch::factory()->create(['company_id' => 1, 'code' => $tag]);
    $customer = Customer::factory()->create(['status' => 'active']);
    $service = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $approver = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $actorA = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $actorB = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $actors = [(int) $actorA->id, (int) $actorB->id];
    foreach ([$maker, $approver, $actorA, $actorB] as $user) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant = static function (User $user, array $permissions, ?int $branchId): void {
        foreach ($permissions as $permission) { DB::table('access_grants')->insertOrIgnore(['user_id' => $user->id, 'permission_code' => $permission,
            'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]); }
    };
    $makerPermissions = ['M01.employee_manage', 'M01.employee_view', 'M02.product_view', 'M03.customer_view', 'M10.contract_create', 'M10.contract_edit',
        'M10.contract_sign', 'M10.contract_activate', 'M10.contract_view', 'M10.document_manage', 'M10.billing_manage', 'M12.customer_invoice_manage',
        'M11.account_manage', 'M11.setup_manage', 'M11.tax_manage', 'M11.period_open', 'M11.opening_manage', 'M11.tax_calculate', 'M11.source_post',
        'M11.journal_create', 'M11.journal_submit', 'M11.journal_approve', 'M11.journal_post', 'M13.account_manage'];
    $reviewerPermissions = ['M01.employee_view', 'M02.product_view', 'M03.customer_view', 'M10.contract_view', 'M10.contract_approve', 'M10.contract_activate',
        'M10.billing_approve', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ar_view', 'M11.report_view', 'M11.tax_calculate',
        'M11.source_post', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_approve', 'M11.journal_post', 'M11.journal_view', 'M13.collection_execute'];
    $racePermissions = ['M03.customer_view', 'M12.ar_view', 'M12.advance_apply', 'M13.collection_execute', 'M13.advance_refund', 'M11.report_view',
        'M11.source_post', 'M11.journal_post'];
    $grant($maker, $makerPermissions, (int) $branch->id); $grant($approver, $reviewerPermissions, (int) $branch->id);
    $grant($actorA, $racePermissions, (int) $branch->id); $grant($actorB, $racePermissions, (int) $branch->id);
    foreach ([$maker, $approver] as $reader) { $grant($reader, ['M02.product_view', 'M03.customer_view'], null); }
    foreach (['M11.account_manage', 'M11.setup_manage', 'M11.tax_manage', 'M11.period_open', 'M11.opening_manage', 'M11.tax_calculate', 'M11.source_post',
        'M11.journal_create', 'M11.journal_submit', 'M11.journal_approve', 'M11.journal_post', 'M13.account_manage'] as $permission) { $grant($maker, [$permission], null); }
    foreach (['M11.report_view', 'M11.tax_calculate', 'M11.source_post', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_approve',
        'M11.journal_post', 'M13.collection_execute'] as $permission) { $grant($approver, [$permission], null); }
    foreach (['M03.customer_view', 'M11.report_view', 'M11.source_post', 'M11.journal_post', 'M13.collection_execute'] as $permission) {
        $grant($actorA, [$permission], null); $grant($actorB, [$permission], null);
    }
    $today = now('Africa/Cairo')->toDateString();
    \Carbon\Carbon::setTestNow(\Carbon\CarbonImmutable::parse($today, 'Africa/Cairo')->startOfDay()->addHours(12));
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'active' => true, 'starts_on' => $today]);
    $uuid = static fn (): string => (string) Str::uuid();
    $accountCode = static fn (string $purpose): string => 'ADV-'.substr(hash('sha256', $tag.':'.$purpose), 0, 24);
    $setup = app(FinancialActivation::class); $accounts = app(AccountingActions::class); $mappings = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $mappings[$purpose] = $accounts->account((int) $maker->id, null, 0, ['code' => $accountCode($purpose), 'name' => 'Advance race '.$label,
            'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]);
    }
    $version = (int) DB::table('accounting_activation')->where('id', 1)->value('version');
    $setup->save((int) $maker->id, $version, ['go_live_date' => $today, 'mappings' => $mappings]); $version++;
    foreach ([['sale', null, 'SALE', 'percentage'], ['purchase', null, 'PURCHASE', 'percentage'], ['sale', $service->id, 'SERVICE', 'exempt']] as [$type, $productId, $suffix, $treatment]) {
        $setup->addTaxRule((int) $maker->id, $version, ['code' => $tag.'-'.$suffix, 'name' => 'Synthetic race '.$suffix.' rule', 'transaction_type' => $type,
            'product_id' => $productId, 'rate' => '0', 'starts_on' => $today, 'ends_on' => null, 'treatment' => $treatment, 'tax_scale' => 2,
            'legal_reference' => 'Rollback-only synthetic concurrency fixture', 'purchase_recoverable' => $type === 'purchase']); $version++;
    }
    $setup->validateTaxTreatment((int) $maker->id, $version, 'Synthetic advance race has explicit zero-rate/exempt tax coverage.'); $version++;
    $accounts->period((int) $maker->id, ['name' => 'Advance race '.$tag, 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
    $opening = app(OpeningBalances::class); $batch = $opening->save((int) $maker->id, $version, $uuid(), [], true,
        'Explicit rollback-only synthetic no-opening declaration for advance concurrency.');
    $opening->advance((int) $maker->id, $batch, 1, 'submit'); $opening->advance((int) $approver->id, $batch, 2, 'approve');
    app(ActivateFinancialPosting::class)->execute((int) $approver->id, $batch, 3);
    foreach (['customer_advance' => 'Advance race customer-advance control', 'service_contract_deferred' => 'Advance race deferred-revenue control'] as $purpose => $name) {
        $id = $accounts->account((int) $maker->id, null, 0, ['code' => $accountCode($purpose), 'name' => $name, 'kind' => 'liability',
            'parent_id' => null, 'postable' => true, 'active' => true]);
        $setup->saveOptionalMapping((int) $maker->id, $purpose, $id);
    }
    $cashGl = (int) DB::table('accounting_account_mappings')->where('purpose', 'cash')->value('account_id');
    $cashAccount = app(TreasuryApi::class)->saveOperationalAccount((int) $maker->id, ['code' => substr($tag, 0, 25).'-CASH',
        'name' => 'Synthetic advance race cash', 'kind' => 'cash', 'gl_account_id' => $cashGl]);
    $terms = ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_employee_id' => $employee->id,
        'title' => 'عقد اختبار سباق رصيد المقدم', 'starts_on' => $today, 'ends_on' => now('Africa/Cairo')->addMonth()->toDateString(), 'value' => '100.00',
        'service_product_id' => $service->id, 'billing_mode' => 'installments', 'pricing_tax_mode' => 'exclusive', 'terms' => 'شروط اختبار سباق قابلة للإزالة.',
        'sla_response_minutes' => 60, 'sla_resolution_minutes' => 120, 'covered_labor_minutes' => 0,
        'coverage' => [['equipment_ref' => $tag.'-EQ', 'site_id' => null, 'preventive_visits' => 0, 'corrective_visits' => 1]],
        'parts' => [], 'excluded_parts' => [], 'boq' => [], 'schedule' => [['code' => 'ADV-RACE-01', 'due_date' => $today, 'amount' => '100.00', 'description' => 'فاتورة اختبار']]];
    $contracts = app(ServiceContractActions::class); $contract = $contracts->save((int) $maker->id, null, 0, $uuid(), $terms);
    $signature = app(ServiceContractDocuments::class)->store((int) $maker->id, $contract,
        UploadedFile::fake()->createWithContent('advance-race-signature.pdf', "%PDF-1.4\nSynthetic race signature\n%%EOF"), 'signature', 'Disposable advance race');
    $row = DB::table('service_contracts')->find($contract); $contracts->submit((int) $maker->id, $contract, (int) $row->version);
    $row = DB::table('service_contracts')->find($contract); $contracts->approve((int) $approver->id, $contract, (int) $row->version);
    $row = DB::table('service_contracts')->find($contract); $contracts->sign((int) $maker->id, $contract, (int) $row->version, $signature, $today);
    $row = DB::table('service_contracts')->find($contract); $contracts->activate((int) $approver->id, $contract, (int) $row->version);
    $billing = app(ServiceContractBilling::class); $source = $billing->proposeInstallment((int) $maker->id, $contract, 'ADV-RACE-01', $uuid(),
        ['invoice_date' => $today, 'due_date' => $today, 'tax_mode' => 'exclusive', 'reference' => 'Synthetic race AR source', 'amount' => '100.00']);
    $billing->approveSource((int) $approver->id, $source, 1); $invoiceId = $billing->invoice((int) $maker->id, $source, $uuid());
    $rp = app(ReceivablesPayablesApi::class); $invoice = DB::table('rp_customer_invoices')->find($invoiceId);
    $rp->validateCustomerInvoice((int) $approver->id, $invoiceId, (int) $invoice->version); $invoice = DB::table('rp_customer_invoices')->find($invoiceId);
    $invoicePost = $rp->postCustomerInvoice((int) $approver->id, $invoiceId, (int) $invoice->version);
    $openId = (int) DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoiceId)->value('id');
    if (! $invoicePost['journal_id'] || ! DB::table('rp_open_items')->where('id', $openId)->where('remaining_amount', '100.00')->exists()) {
        throw new RuntimeException('A genuine posted M10/M12 100.00 invoice did not produce its AR open item.');
    }
    $collectionKey = $uuid(); $collection = app(TreasuryApi::class)->executeCustomerCollection((int) $actorA->id,
        ['idempotency_key' => $collectionKey, 'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'account_id' => $cashAccount,
            'date' => $today, 'amount' => '50.00', 'reference' => 'Synthetic advance concurrency source', 'allow_advance' => true], []);
    $advanceId = (int) DB::table('rp_customer_advances')->where('movement_id', $collection['movement_id'])->value('id');
    if ($advanceId < 1 || ! DB::table('rp_customer_advances')->where('id', $advanceId)->where('original_amount', '50.00')->exists()) {
        throw new RuntimeException('A real Treasury collection did not create a 50.00 customer advance.');
    }
    DB::commit();
    \Carbon\Carbon::setTestNow();

    $receipt['actor_ids'] = $actors; $receipt['branch_id'] = (int) $branch->id; $receipt['advance_id'] = $advanceId;
    file_put_contents($receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX); chmod($receiptPath, 0600);
    $gate = $private.'/barrier/go'; $tasks = [
        ['application', (int) $actorA->id, $uuid(), $cashAccount, 'Advance application race worker'],
        ['refund', (int) $actorB->id, $uuid(), $cashAccount, 'Advance refund race worker']];
    foreach ($tasks as $i => [$mode, $actorId, $key, $accountId, $reference]) {
        $ready = $private.'/barrier/ready-'.$i;
        $process = new Process([PHP_BINARY, $root.'/tests/Support/m10-advance-concurrency-worker.php', $database, $receiptPath,
            (string) $actorId, (string) $advanceId, (string) $openId, '30.00', $today, $mode, $key, (string) $accountId, $reference, $gate, $ready],
            $root, $baseEnv, null, 90);
        $process->start(); $workers[] = [$process, $ready];
    }
    $deadline = microtime(true) + 45;
    foreach ($workers as [, $ready]) {
        while (! is_file($ready) && microtime(true) < $deadline) { usleep(10000); }
        if (! is_file($ready)) { throw new RuntimeException('Both advance workers did not reach the PostgreSQL race barrier.'); }
    }
    $readyRows = array_map(fn ($path) => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), array_column($workers, 1));
    if (count(array_unique(array_column($readyRows, 'pg_backend_pid'))) !== 2) {
        throw new RuntimeException('Advance lifecycle race did not use two independent PostgreSQL backends.');
    }
    file_put_contents($gate, 'go', LOCK_EX);
    $outcomes = [];
    foreach ($workers as [$process]) {
        $process->wait(); $outcomes[] = ['exit_code' => $process->getExitCode(), 'result' => json_decode(trim($process->getOutput()), true)];
    }
    file_put_contents($private.'/workers.json', json_encode(['ready' => $readyRows, 'outcomes' => $outcomes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($private.'/workers.json', 0600);
    $passCount = count(array_filter($outcomes, fn ($row) => $row['exit_code'] === 0 && ($row['result']['status'] ?? null) === 'PASS'));
    $rejectCount = count(array_filter($outcomes, fn ($row) => $row['exit_code'] === 2 && ($row['result']['status'] ?? null) === 'REJECTED' && ($row['result']['code'] ?? null) === 422));
    if ($passCount !== 1 || $rejectCount !== 1) {
        throw new RuntimeException('Advance application/refund race did not produce exactly one success and one 422 balance-cap rejection.');
    }
    $history = app(ReceivablesPayablesApi::class)->reconciliationValues((int) $actorA->id, [(int) $branch->id], $today);
    $applicationCount = (int) DB::table('rp_customer_advance_applications')->where('advance_id', $advanceId)->count();
    $refundCount = (int) DB::table('rp_customer_advance_refunds')->where('advance_id', $advanceId)->count();
    $applicationAmount = (string) DB::table('rp_customer_advance_applications')->where('advance_id', $advanceId)->sum('amount');
    $refundAmount = (string) DB::table('rp_customer_advance_refunds')->where('advance_id', $advanceId)->sum('amount');
    $advanceGl = (int) DB::table('accounting_account_mappings')->where('purpose', 'customer_advance')->value('account_id');
    $glRemaining = (string) DB::table('accounting_journal_lines as l')->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
        ->where('j.status', 'posted')->where('j.branch_id', $branch->id)->where('l.account_id', $advanceGl)
        ->selectRaw('coalesce(sum(l.credit-l.debit),0)::numeric(20,2) amount')->value('amount');
    $used = bcadd($applicationAmount, $refundAmount, 2);
    if ($applicationCount + $refundCount !== 1 || bccomp($used, '30.00', 2) !== 0
        || $history['customer_advances'] !== '20.00' || bccomp($glRemaining, '20.00', 2) !== 0
        || bccomp($history['customer_advances'], $glRemaining, 2) !== 0) {
        throw new RuntimeException('Advance race over-allocated the source or failed M12 subledger/M11 control reconciliation at 20.00.');
    }
    $result = ['all_pass' => true, 'mode' => 'm10-advance-concurrency', 'assertions' => 3, 'database' => $database,
        'server_address' => $identity->server_address, 'port' => (int) $identity->port, 'postgres_major' => intdiv((int) $identity->server_version_num, 10000),
        'worker_backend_pids' => array_column($readyRows, 'pg_backend_pid'), 'worker_success_count' => $passCount, 'worker_422_count' => $rejectCount,
        'outcomes' => $outcomes, 'advance_original' => '50.00', 'application_rows' => $applicationCount, 'refund_rows' => $refundCount,
        'application_amount' => $applicationAmount, 'refund_amount' => $refundAmount, 'advance_subledger_remaining' => $history['customer_advances'],
        'advance_gl_remaining' => $glRemaining, 'invoice_id' => $invoiceId, 'open_item_id' => $openId];
} catch (Throwable $exception) {
    $error = $exception;
    $message = preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $exception->getMessage());
    file_put_contents($private.'/fixture-failure.json', json_encode(['phase' => $stage, 'class' => $exception::class,
        'message' => mb_substr((string) $message, 0, 500)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($private.'/fixture-failure.json', 0600);
} finally {
    $workerLogs = [];
    foreach ($workers as [$process]) {
        if ($process->isRunning()) { $process->stop(1); }
        $workerLogs[] = ['exit_code' => $process->getExitCode(), 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }
    if ($workerLogs !== []) {
        file_put_contents($private.'/worker-processes.json', json_encode($workerLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($private.'/worker-processes.json', 0600);
    }
    if (DB::transactionLevel() > 0) { DB::rollBack(); }
    \Carbon\Carbon::setTestNow();
    DB::disconnect('pgsql');
    $stage = 'drop verified disposable advance database';
    $cleanupError = null;
    try { $safeDrop(); } catch (Throwable $exception) { $cleanupError = $exception; $error ??= $exception; }
    $cleanup = $writeCleanup($cleanupError); $cleanupRecorded = true;
    if ($cleanupError) { $writeFailure($cleanupError); }
}
if ($error) {
    $failure = $writeFailure($error);
    fwrite(STDERR, json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    fwrite(STDERR, 'Private race artifacts preserved at '.$private.PHP_EOL);
    exit(1);
}
if (! $dropped || $primaryCountsUnchanged !== true) {
    $error = new RuntimeException('Disposable advance database drop and unchanged-primary checks did not both pass.');
    $failure = $writeFailure($error); $writeCleanup($error);
    fwrite(STDERR, json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    fwrite(STDERR, 'Private race artifacts preserved at '.$private.PHP_EOL);
    exit(1);
}
$result['checks'] = [
    ['name' => 'Independent application and refund workers yield one success and one 422 cap rejection', 'result' => 'PASS'],
    ['name' => 'Advance remaining equals 20.00 in M12 history and posted M11 customer-advance control', 'result' => 'PASS'],
    ['name' => 'Disposable database dropped after exact marker/nonce/PGDATA checks; primary counts unchanged', 'result' => 'PASS']];
$result['disposable_database_dropped'] = true; $result['primary_counts_unchanged'] = true;
$evidence = $private.'/result.json';
file_put_contents($evidence, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
chmod($evidence, 0600);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
echo 'RESULT all_pass=1 assertions='.$result['assertions'].' disposable_database_dropped=1 primary_counts_unchanged=1 private_artifacts='.$private.PHP_EOL;
