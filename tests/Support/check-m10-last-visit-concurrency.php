<?php

declare(strict_types=1);

// M08 concurrent contract links contend for one final covered visit in a disposable owned database.
use App\Models\User;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
require_once $root.'/vendor/autoload.php';
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$primaryEnv = ['APP_ENV' => 'testing', 'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$primary = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $primaryEnv);
if ($primary['port'] !== 49685 || $primary['database'] !== 'erp_elec_new_test' || $primary['username'] !== 'postgres') {
    throw new RuntimeException('M10 race requires the exact Gate1-owned PostgreSQL 17 primary target.');
}
$nonce = bin2hex(random_bytes(32));
$database = 'erp_elec_new_m10_race_'.substr($nonce, 0, 16);
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$private = $taskRoot.'/m10-race-'.$nonce;
$stage = 'create private disposable database receipt';
if (! mkdir($private, 0700, true)) {
    throw new RuntimeException('Cannot create private M10 race receipt directory.');
}
$receiptPath = $private.'/ownership.json';
$failurePath = $private.'/failure.json';
$cleanupPath = $private.'/cleanup.json';
$dropped = false;
$failed = false;
$cleanupRecorded = false;
$primaryCountsUnchanged = null;
$writeFailure = static function (Throwable $error) use (&$stage, &$dropped, &$failed, &$primaryCountsUnchanged, $database, $primary, $failurePath): array {
    $failed = true;
    $message = preg_replace('/(password|secret|token)=\\S+/i', '$1=[redacted]', $error->getMessage());
    $receipt = ['all_pass' => false, 'mode' => 'm10-last-visit-concurrency', 'phase' => $stage,
        'primary_database' => $primary['database'], 'disposable_database' => $database,
        'disposable_database_dropped' => $dropped, 'primary_counts_unchanged' => $primaryCountsUnchanged,
        'failure' => ['class' => $error::class, 'message' => mb_substr((string) $message, 0, 500)]];
    if (! is_file($failurePath)) {
        file_put_contents($failurePath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
        @chmod($failurePath, 0600);
    }
    return $receipt;
};
$writeCleanup = static function (?Throwable $error = null) use (&$dropped, &$primaryCountsUnchanged, $database, $primary, $cleanupPath): array {
    $message = $error ? preg_replace('/(password|secret|token)=\\S+/i', '$1=[redacted]', $error->getMessage()) : null;
    $receipt = ['mode' => 'm10-last-visit-concurrency-cleanup', 'database' => $database, 'port' => $primary['port'],
        'server_address' => $primary['server_address'], 'data_directory' => $primary['data_directory'],
        'current_user' => $primary['username'], 'database_drop_status' => $dropped ? 'dropped' : 'not_verified_dropped',
        'primary_counts_unchanged' => $primaryCountsUnchanged,
        'cleanup_failure' => $error ? ['class' => $error::class, 'message' => mb_substr((string) $message, 0, 500)] : null,
        'recorded_at_utc' => gmdate(DATE_ATOM)];
    file_put_contents($cleanupPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    @chmod($cleanupPath, 0600);
    return $receipt;
};
set_exception_handler(static function (Throwable $error) use ($writeFailure): void {
    $receipt = $writeFailure($error);
    fwrite(STDERR, json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    fwrite(STDERR, "Private failure receipt preserved.\n");
    exit(1);
});
$runtime = $private.'/runtime';
foreach ([$runtime.'/storage', $runtime.'/cache', $runtime.'/views'] as $directory) {
    if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Cannot create private M10 race runtime directory.');
    }
}
$dsn = static fn (string $db): string => 'pgsql:host='.$primary['connection_host'].';port='.$primary['port'].';dbname='.$db;
$password = (string) (getenv('DB_PASSWORD') ?: '');
$stage = 'verify owned PostgreSQL primary';
$admin = new PDO($dsn('postgres'), $primary['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$actualPrimary = $admin->query("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner")->fetch(PDO::FETCH_OBJ);
if ($actualPrimary->database !== 'postgres' || $actualPrimary->server_address !== $primary['server_address']
    || (int) $actualPrimary->port !== $primary['port'] || $actualPrimary->current_user !== $primary['username']
    || $actualPrimary->database_owner !== $primary['username']
    || (int) $actualPrimary->server_version_num < 170000 || (int) $actualPrimary->server_version_num >= 180000
    || realpath($actualPrimary->data_directory) !== realpath($primary['data_directory'])) {
    throw new RuntimeException('Refusing disposable M10 database creation outside the marker-owned PostgreSQL cluster.');
}
$primaryCheck = new PDO($dsn($primary['database']), $primary['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$actualTest = $primaryCheck->query("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner")->fetch(PDO::FETCH_OBJ);
if ($actualTest->database !== $primary['database'] || $actualTest->server_address !== $primary['server_address']
    || (int) $actualTest->port !== $primary['port'] || $actualTest->current_user !== $primary['username']
    || $actualTest->database_owner !== $primary['username']
    || (int) $actualTest->server_version_num < 170000 || (int) $actualTest->server_version_num >= 180000
    || realpath($actualTest->data_directory) !== realpath($primary['data_directory'])) {
    throw new RuntimeException('Refusing disposable M10 database creation: primary database identity failed live verification.');
}
$readPrimaryCounts = static function (PDO $connection): array {
    $tables = ['migrations', 'companies', 'branches', 'customers', 'products', 'users', 'organization_employees',
        'access_grants', 'user_branch_scopes', 'accounting_activation', 'accounting_journals', 'inventory_warehouses',
        'maintenance_work_orders', 'service_contracts', 'service_contract_work', 'audit_entries'];
    $counts = [];
    foreach ($tables as $table) {
        $exists = $connection->prepare('select to_regclass(?)');
        $exists->execute(['public.'.$table]);
        $resolved = $exists->fetchColumn();
        if ($resolved !== false && $resolved !== null) {
            $counts[$table] = (int) $connection->query('select count(*) from public."'.$table.'"')->fetchColumn();
        }
    }
    return $counts;
};
$primaryCounts = $readPrimaryCounts($primaryCheck);
$primaryCheck = null;
$receipt = ['database' => $database, 'port' => $primary['port'], 'server_address' => $primary['server_address'],
    'data_directory' => realpath($primary['data_directory']) ?: $primary['data_directory'],
    'current_user' => $primary['username'], 'nonce' => $nonce];
file_put_contents($receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
@chmod($receiptPath, 0600);
$stage = 'create nonce-owned disposable database';
$admin->exec('CREATE DATABASE "'.$database.'"');
$temporary = new PDO($dsn($database), $primary['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$temporary->exec('CREATE SCHEMA codex_test_guard');
$temporary->exec('CREATE TABLE codex_test_guard.ownership_receipts (database text PRIMARY KEY, nonce char(64) NOT NULL)');
$mark = $temporary->prepare('INSERT INTO codex_test_guard.ownership_receipts (database, nonce) VALUES (?, ?)');
$mark->execute([$database, $nonce]);
$temporary = null;
$safeDrop = static function () use (&$dropped, &$primaryCountsUnchanged, $root, $primaryEnv, $primary, $database, $nonce, $dsn, $password, $admin, $primaryCounts, $readPrimaryCounts): void {
    if ($dropped || ! preg_match('/^erp_elec_new_m10_race_[a-f0-9]{16}$/', $database)) {
        return;
    }
    $owned = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $primaryEnv);
    if ($owned['data_directory'] !== $primary['data_directory'] || $owned['port'] !== $primary['port']) {
        throw new RuntimeException('Refusing disposable M10 database cleanup after the primary target changed.');
    }
    $check = new PDO($dsn($database), $owned['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $live = $check->query("select current_database() as database, host(inet_server_addr()) as server_address,
        inet_server_port() as port, current_setting('data_directory') as data_directory, current_user as current_user,
        (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner,
        (select nonce from codex_test_guard.ownership_receipts where database=current_database()) as nonce")->fetch(PDO::FETCH_OBJ);
    if (! $live || $live->database !== $database || $live->server_address !== $owned['server_address']
        || (int) $live->port !== $owned['port'] || realpath($live->data_directory) !== realpath($owned['data_directory'])
        || $live->current_user !== $owned['username'] || $live->database_owner !== $owned['username']
        || ! hash_equals($nonce, (string) $live->nonce)) {
        throw new RuntimeException('Refusing disposable M10 database cleanup without exact live identity and nonce.');
    }
    $admin->exec('DROP DATABASE "'.$database.'" WITH (FORCE)');
    $dropped = true;
    $afterCounts = $readPrimaryCounts(new PDO($dsn($primary['database']), $owned['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    $primaryCountsUnchanged = $afterCounts === $primaryCounts;
    if (! $primaryCountsUnchanged) {
        throw new RuntimeException('Primary database counts changed during the disposable M10 race check.');
    }
};
register_shutdown_function(static function () use (&$dropped, &$cleanupRecorded, $safeDrop, $writeFailure, $writeCleanup): void {
    if ($cleanupRecorded) {
        return;
    }
    $cleanupError = null;
    if (! $dropped) {
        try {
            $safeDrop();
        } catch (Throwable $error) {
            $cleanupError = $error;
        }
    }
    $receipt = $writeCleanup($cleanupError);
    $cleanupRecorded = true;
    if ($cleanupError) {
        $writeFailure($cleanupError);
        fwrite(STDERR, json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        fwrite(STDERR, $dropped ? "Disposable database dropped; private failure artifacts preserved.\n" : "Disposable database drop was not verified; private failure artifacts preserved.\n");
    }
});

$baseEnv = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $primary['connection_host'],
    'DB_PORT' => (string) $primary['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $primary['username'],
    'DB_PASSWORD' => $password, 'DB_URL' => '', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'LARAVEL_STORAGE_PATH' => $runtime.'/storage',
    'APP_CONFIG_CACHE' => '.tmp/master-remediation-20261101/m10-race-'.$nonce.'/runtime/cache/config.php',
    'APP_ROUTES_CACHE' => '.tmp/master-remediation-20261101/m10-race-'.$nonce.'/runtime/cache/routes.php',
    'APP_EVENTS_CACHE' => '.tmp/master-remediation-20261101/m10-race-'.$nonce.'/runtime/cache/events.php',
    'APP_SERVICES_CACHE' => '.tmp/master-remediation-20261101/m10-race-'.$nonce.'/runtime/cache/services.php',
    'APP_PACKAGES_CACHE' => '.tmp/master-remediation-20261101/m10-race-'.$nonce.'/runtime/cache/packages.php',
    'APP_VIEW_COMPILED_PATH' => '.tmp/master-remediation-20261101/m10-race-'.$nonce.'/runtime/views',
    'VIEW_COMPILED_PATH' => '.tmp/master-remediation-20261101/m10-race-'.$nonce.'/runtime/views',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
    'M10_RACE_RECEIPT' => $receiptPath];
$stage = 'migrate disposable M10 database';
$migrate = new Process([PHP_BINARY, $root.'/artisan', 'migrate', '--force', '--no-interaction', '--env=testing'], $root, $baseEnv, null, 300);
$migrate->run();
file_put_contents($private.'/migration-output.log', $migrate->getOutput().$migrate->getErrorOutput(), LOCK_EX);
@chmod($private.'/migration-output.log', 0600);
if (! $migrate->isSuccessful() || ! preg_match('/^.*\bDONE\r?$/m', $migrate->getOutput())) {
    throw new RuntimeException('Disposable M10 race database migrations failed or omitted the completion marker.');
}

putenv('DB_CONNECTION=pgsql');
foreach ($baseEnv as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require_once $root.'/vendor/autoload.php';
$stage = 'bootstrap disposable M10 application';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($runtime.'/storage');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $primary['connection_host'],
    'database.connections.pgsql.port' => $primary['port'], 'database.connections.pgsql.database' => $database,
    'database.connections.pgsql.username' => $primary['username'], 'database.connections.pgsql.password' => $password,
    'database.connections.pgsql.url' => null, 'filesystems.disks.local.root' => $runtime.'/private',
    'filesystems.disks.local.visibility' => 'private', 'filesystems.disks.local.throw' => true,
    'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
$stage = 'verify M08 M01 M10 migrations and live disposable identity';
$requiredMigrations = ['2026_10_30_182000_create_field_maintenance', '2026_10_30_183000_link_maintenance_owner_sources',
    '2026_10_30_186000_protect_maintenance_source_history', '2026_10_30_187000_separate_maintenance_identity_guards',
    '2026_11_01_201000_create_organization_employees', '2026_11_01_202000_create_service_contracts',
    '2026_11_01_203000_link_service_contract_work', '2026_11_01_205000_track_contract_work_usage',
    '2026_11_01_207000_separate_contract_source_identity_guards'];
if (DB::table('migrations')->whereIn('migration', $requiredMigrations)->count() !== count($requiredMigrations)
    || ! DB::getSchemaBuilder()->hasTable('service_contract_work') || ! DB::getSchemaBuilder()->hasTable('maintenance_work_orders')
    || ! DB::getSchemaBuilder()->hasTable('service_contract_revisions')) {
    throw new RuntimeException('Disposable M10 race database did not apply its M08, M01, and M10 schema migrations.');
}
$verify = static function (string $expectedDb) use ($primary, $nonce, $dsn, $password): object {
    $connection = new PDO($dsn($expectedDb), $primary['username'], $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $row = $connection->query("select current_database() as database, host(inet_server_addr()) as server_address,
        inet_server_port() as port, current_setting('data_directory') as data_directory, current_user as current_user,
        current_setting('server_version_num') as server_version_num,
        (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner,
        (select nonce from codex_test_guard.ownership_receipts where database=current_database()) as nonce")->fetch(PDO::FETCH_OBJ);
    if (! $row || $row->database !== $expectedDb || $row->server_address !== $primary['server_address']
        || (int) $row->port !== $primary['port'] || $row->current_user !== $primary['username']
        || realpath($row->data_directory) !== realpath($primary['data_directory']) || $row->nonce !== $nonce
        || (int) $row->server_version_num < 170000 || (int) $row->server_version_num >= 180000) {
        throw new RuntimeException('Disposable M10 database live identity or nonce receipt mismatch.');
    }
    return $row;
};
$identity = $verify($database);
$stage = 'prepare owner-action contract and M08 work-order fixtures';
$ids = ['company_created' => false, 'branch' => null, 'customer' => null, 'employee' => null, 'product' => null,
    'users' => [], 'contract' => null, 'work_orders' => []];
$workers = [];
$barrier = null;
$error = null;
try {
    DB::beginTransaction();
    $tag = 'M10-RACE-'.strtoupper(bin2hex(random_bytes(5)));
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create(['id' => 1, 'legal_name' => 'Synthetic M10 concurrency entity',
            'address' => 'Disposable M10 race fixture', 'setup_complete' => true]);
        $ids['company_created'] = true;
    }
    $branch = Branch::factory()->create(['company_id' => 1, 'code' => $tag]);
    $customer = Customer::factory()->create(['status' => 'active']);
    $service = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $ids['users'] = [(int) $maker->id, (int) $reviewer->id];
    $ids['branch'] = (int) $branch->id;
    $ids['customer'] = (int) $customer->id;
    $ids['product'] = (int) $service->id;
    foreach ([$maker, $reviewer] as $user) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant = static function (User $user, array $codes, ?int $branchId): void {
        foreach ($codes as $code) {
            DB::table('access_grants')->insertOrIgnore(['user_id' => $user->id, 'permission_code' => $code, 'branch_id' => $branchId,
                'created_at' => now(), 'updated_at' => now()]);
        }
    };
    $grant($maker, ['M01.employee_view', 'M03.customer_view', 'M02.product_view', 'M10.contract_create', 'M10.contract_edit',
        'M10.contract_sign', 'M10.contract_activate', 'M10.document_manage', 'M10.coverage_use', 'M08.view', 'M08.workorder_manage', 'M08.standalone_create'], (int) $branch->id);
    $grant($maker, ['M03.customer_view', 'M02.product_view'], null);
    $grant($reviewer, ['M01.employee_view', 'M03.customer_view', 'M02.product_view', 'M10.contract_view', 'M10.contract_approve'], (int) $branch->id);
    $grant($reviewer, ['M03.customer_view', 'M02.product_view'], null);
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'active' => true, 'starts_on' => now('Africa/Cairo')->subDay()->toDateString()]);
    $ids['employee'] = (int) $employee->id;
    $today = now('Africa/Cairo')->toDateString();
    $uuid = static fn (): string => (string) Str::uuid();
    $actions = app(ServiceContractActions::class);
    $terms = ['branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'responsible_employee_id' => (int) $employee->id,
        'title' => 'عقد اختبار تنافس الزيارة الأخيرة', 'starts_on' => $today, 'ends_on' => now('Africa/Cairo')->addMonth()->toDateString(),
        'value' => '1.00', 'service_product_id' => (int) $service->id, 'billing_mode' => 'installments', 'terms' => 'بيانات اصطناعية لقبول التزامن.',
        'sla_response_minutes' => 60, 'sla_resolution_minutes' => 120, 'covered_labor_minutes' => 0,
        'coverage' => [['equipment_ref' => $tag.'-EQ', 'site_id' => null, 'preventive_visits' => 1, 'corrective_visits' => 0]],
        'parts' => [], 'excluded_parts' => [], 'boq' => [],
        'schedule' => [['code' => 'RACE-01', 'due_date' => $today, 'amount' => '1.00', 'description' => 'قسط اختبار']]];
    $contract = $actions->save((int) $maker->id, null, 0, $uuid(), $terms);
    $ids['contract'] = $contract;
    $header = DB::table('service_contracts')->find($contract);
    $actions->submit((int) $maker->id, $contract, (int) $header->version);
    $header = DB::table('service_contracts')->find($contract);
    $actions->approve((int) $reviewer->id, $contract, (int) $header->version);
    $signature = app(ServiceContractDocuments::class)->store((int) $maker->id, $contract,
        UploadedFile::fake()->createWithContent('m10-race-signature.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n"), 'signature', 'دليل توقيع اصطناعي');
    $header = DB::table('service_contracts')->find($contract);
    $actions->sign((int) $maker->id, $contract, (int) $header->version, $signature, $today);
    $header = DB::table('service_contracts')->find($contract);
    $actions->activate((int) $maker->id, $contract, (int) $header->version);
    $maintenance = app(MaintenanceActions::class);
    foreach (['A', 'B'] as $suffix) {
        $ids['work_orders'][] = $maintenance->createWorkOrder((int) $maker->id, null, 0, $uuid(), [
            'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'responsible_id' => (int) $maker->id,
            'description' => 'Synthetic competing contract link '.$suffix, 'priority' => 'normal', 'billable' => false,
            'acknowledgment_required' => false]);
    }
    DB::commit();

    $barrier = $runtime.'/barrier';
    mkdir($barrier, 0700, true);
    $gate = $barrier.'/go';
    $stage = 'start independent M10 reservation workers';
    foreach ($ids['work_orders'] as $i => $workId) {
        $ready = $barrier.'/ready-'.$i;
        $process = new Process([PHP_BINARY, $root.'/tests/Support/m10-last-visit-worker.php', $database, $receiptPath,
            (string) $maker->id, (string) $contract, (string) $workId, $tag.'-EQ', $today, $gate, $ready], $root, $baseEnv, null, 60);
        $process->start();
        $workers[] = [$process, $ready];
    }
    $deadline = microtime(true) + 35;
    foreach ($workers as [, $ready]) {
        while (! is_file($ready) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($ready)) {
            throw new RuntimeException('M10 workers did not reach the race barrier.');
        }
    }
    $readyRows = array_map(fn ($path) => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), array_column($workers, 1));
    if (count(array_unique(array_column($readyRows, 'pg_backend_pid'))) !== 2) {
        throw new RuntimeException('M10 race did not use two PostgreSQL worker processes.');
    }
    file_put_contents($gate, 'go', LOCK_EX);
    $stage = 'verify last-visit reservation race';
    $outcomes = [];
    foreach ($workers as [$process]) {
        $process->wait();
        $outcomes[] = ['exit_code' => $process->getExitCode(), 'result' => json_decode(trim($process->getOutput()), true)];
    }
    $onePassOneReject = count(array_filter($outcomes, fn ($row) => $row['exit_code'] === 0 && ($row['result']['status'] ?? null) === 'PASS')) === 1
        && count(array_filter($outcomes, fn ($row) => $row['exit_code'] === 2 && ($row['result']['status'] ?? null) === 'REJECTED')) === 1;
    $usage = DB::table('service_contract_work')->where('contract_id', $contract)->where('owner', 'FieldMaintenance')->get();
    $linked = DB::table('maintenance_work_orders')->whereIn('id', $ids['work_orders'])->where('contract_id', $contract)->count();
    if (! $onePassOneReject || $usage->count() !== 1 || $linked !== 1 || (int) $usage[0]->visits !== 1) {
        throw new RuntimeException('Last-visit race did not reserve the final contract entitlement exactly once.');
    }
    $result = ['all_pass' => true, 'assertions' => 1, 'database' => $database, 'port' => (int) $identity->port,
        'worker_backend_pids' => array_column($readyRows, 'pg_backend_pid'), 'outcomes' => $outcomes,
        'reserved_entitlements' => $usage->count(), 'linked_work_orders' => $linked,
        'reserved_visit_units' => (int) $usage[0]->visits];
} catch (Throwable $exception) {
    $error = $exception;
    $writeFailure($exception);
} finally {
    foreach ($workers as [$process]) {
        if ($process->isRunning()) {
            $process->stop(1);
        }
    }
    DB::disconnect('pgsql');
    $stage = 'drop verified disposable M10 database';
    $cleanupError = null;
    try {
        $safeDrop();
    } catch (Throwable $exception) {
        $cleanupError = $exception;
        $error ??= $exception;
    }
    $writeCleanup($cleanupError);
    $cleanupRecorded = true;
    if ($cleanupError) {
        $writeFailure($error);
    }
}
if ($error) {
    $failure = is_file($failurePath) ? json_decode((string) file_get_contents($failurePath), true) : $writeFailure($error);
    fwrite(STDERR, json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    fwrite(STDERR, 'Private failure artifacts preserved at '.$private.PHP_EOL);
    exit(1);
}
if (! $dropped || $primaryCountsUnchanged !== true) {
    $error = new RuntimeException('Disposable database drop and unchanged-primary checks did not both pass.');
    $failure = $writeFailure($error);
    $writeCleanup($error);
    fwrite(STDERR, json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    fwrite(STDERR, 'Private failure artifacts preserved at '.$private.PHP_EOL);
    exit(1);
}
$result['assertions']++;
$result['checks'] = [
    ['name' => 'Two independent workers contend for one final covered visit', 'result' => 'PASS'],
    ['name' => 'Disposable database dropped and primary counts unchanged', 'result' => 'PASS'],
];
$result['disposable_database_dropped'] = true;
$result['primary_counts_unchanged'] = true;
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate2';
if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
    throw new RuntimeException('Could not create Gate2 race evidence directory.');
}
$evidencePath = $evidenceDirectory.'/m10-last-visit-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json';
file_put_contents($evidencePath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'RESULT all_pass=1 assertions='.$result['assertions'].' receipt='.$evidencePath.PHP_EOL;
echo 'PRIVATE_ARTIFACTS '.$private.PHP_EOL;
