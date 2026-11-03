<?php

declare(strict_types=1);

// Guarded Executive Dashboard acceptance. All synthetic rows are created inside an outer rollback.
use App\Application\ExecutiveDashboard;
use App\Models\User;
use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\WorkshopRepairs\Application\Contracts\WorkshopApi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$root = dirname(__DIR__, 2);
$stage = 'source preflight';
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate2/executive-dashboard';
$writeReceipt = static function (array $receipt) use ($evidenceDirectory): string {
    if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
        throw new RuntimeException('Unable to create executive-dashboard evidence directory.');
    }
    $path = $evidenceDirectory.'/executive-dashboard-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);

    return $path;
};
set_exception_handler(static function (Throwable $error) use (&$stage, $writeReceipt): void {
    $message = preg_replace('/(password|secret|token)=\\S+/i', '$1=[redacted]', $error->getMessage());
    $receipt = ['all_pass' => false, 'phase' => $stage, 'assertions' => 0, 'rollback_verified' => false,
        'checks' => [], 'failure' => ['class' => $error::class, 'message' => mb_substr((string) $message, 0, 500)]];
    try {
        $path = $writeReceipt($receipt);
        fwrite(STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        fwrite(STDERR, 'RESULT all_pass=0 assertions=0 rollback_verified=0 receipt='.$path.PHP_EOL);
    } catch (Throwable) {
        fwrite(STDERR, "RESULT all_pass=0 assertions=0 rollback_verified=0 receipt_write_failed=1\n");
    }
    exit(1);
});
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';

$requiredFiles = [
    'dashboard coordinator' => $root.'/app/Application/ExecutiveDashboard.php',
    'organization identity scope API' => $root.'/app/Modules/Organization/Application/Contracts/OrganizationApi.php',
    'sales summary API' => $root.'/app/Modules/Sales/Application/Contracts/SalesOrdersApi.php',
    'projects and tenders summary API' => $root.'/app/Modules/Projects/Application/Contracts/ProjectsApi.php',
    'field maintenance summary API' => $root.'/app/Modules/FieldMaintenance/Application/Contracts/FieldMaintenanceApi.php',
    'workshop summary API' => $root.'/app/Modules/WorkshopRepairs/Application/Contracts/WorkshopApi.php',
    'posted ledger summary API' => $root.'/app/Modules/Accounting/Application/Contracts/AccountingApi.php',
    'posted invoice summary API' => $root.'/app/Modules/ReceivablesPayables/Application/Contracts/ReceivablesPayablesApi.php',
    'treasury summary API' => $root.'/app/Modules/Treasury/Application/Contracts/TreasuryApi.php',
    'sales-order factory' => $root.'/database/factories/SalesOrderFactory.php',
    'database ownership guard' => $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php',
];
$requiredClasses = [
    ExecutiveDashboard::class, OrganizationApi::class, SalesOrdersApi::class, ProjectsApi::class,
    FieldMaintenanceApi::class, WorkshopApi::class, AccountingApi::class, ReceivablesPayablesApi::class, TreasuryApi::class,
];
$missingFiles = array_keys(array_filter($requiredFiles, static fn (string $path): bool => ! is_file($path)));
$missingClasses = array_values(array_filter($requiredClasses, static fn (string $class): bool => ! class_exists($class) && ! interface_exists($class)));
$missingMethods = [];
foreach ([SalesOrdersApi::class, ProjectsApi::class, FieldMaintenanceApi::class, WorkshopApi::class,
    AccountingApi::class, ReceivablesPayablesApi::class, TreasuryApi::class] as $api) {
    if ((interface_exists($api) || class_exists($api)) && ! method_exists($api, 'executiveSummary')) {
        $missingMethods[] = $api.'::executiveSummary';
    }
}
if ((interface_exists(OrganizationApi::class) || class_exists(OrganizationApi::class))
    && ! method_exists(OrganizationApi::class, 'projectScopedBranches')) {
    $missingMethods[] = OrganizationApi::class.'::projectScopedBranches';
}
if (class_exists(ExecutiveDashboard::class) && ! method_exists(ExecutiveDashboard::class, 'read')) {
    $missingMethods[] = ExecutiveDashboard::class.'::read';
}
if ($missingFiles !== [] || $missingClasses !== [] || $missingMethods !== []) {
    $path = $writeReceipt(['all_pass' => false, 'phase' => 'Executive Dashboard source preflight', 'missing_files' => $missingFiles,
        'missing_classes' => $missingClasses, 'missing_methods' => $missingMethods]);
    fwrite(STDERR, 'RESULT source_preflight=RED missing_files='.count($missingFiles).' missing_classes='.count($missingClasses)
        .' missing_methods='.count($missingMethods).' receipt='.$path.PHP_EOL);
    exit(1);
}

$environment = [
    'APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49686')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres'),
];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $environment);
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$runtimeId = bin2hex(random_bytes(6));
$storagePath = $taskRoot.'/executive-dashboard-storage-'.$runtimeId;
$cacheRelative = '.tmp/master-remediation-20261101/executive-dashboard-cache-'.$runtimeId;
$viewsRelative = '.tmp/master-remediation-20261101/executive-dashboard-views-'.$runtimeId;
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'], 'DB_PORT' => (string) $target['port'],
    'DB_DATABASE' => $target['database'], 'DB_USERNAME' => $target['username'], 'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''),
    'DB_URL' => '', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'LARAVEL_STORAGE_PATH' => $storagePath, 'APP_CONFIG_CACHE' => $cacheRelative.'/config.php',
    'APP_ROUTES_CACHE' => $cacheRelative.'/routes.php', 'APP_EVENTS_CACHE' => $cacheRelative.'/events.php',
    'APP_SERVICES_CACHE' => $cacheRelative.'/services.php', 'APP_PACKAGES_CACHE' => $cacheRelative.'/packages.php',
    'APP_VIEW_COMPILED_PATH' => $viewsRelative, 'VIEW_COMPILED_PATH' => $viewsRelative,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$runtimeDirectories = [$root.'/'.$cacheRelative, $root.'/'.$viewsRelative, $storagePath];
foreach ($runtimeDirectories as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create isolated executive-dashboard runtime directory.');
    }
}
register_shutdown_function(static function () use ($runtimeDirectories, $root, $taskRoot): void {
    foreach ($runtimeDirectories as $directory) {
        $resolved = realpath($directory);
        $allowed = str_starts_with($directory, $root.'/.tmp/master-remediation-20261101/executive-dashboard-')
            || str_starts_with($directory, $taskRoot.'/executive-dashboard-storage-');
        $resolvedTaskRoot = realpath($taskRoot);
        if ($allowed && $resolved !== false && $resolvedTaskRoot !== false
            && str_starts_with($resolved, $resolvedTaskRoot.DIRECTORY_SEPARATOR)) {
            (new Illuminate\Filesystem\Filesystem)->deleteDirectory($resolved);
        }
    }
});
$stage = 'Laravel bootstrap and exact PostgreSQL guard';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storagePath);
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $target['connection_host'],
    'database.connections.pgsql.port' => $target['port'], 'database.connections.pgsql.database' => $target['database'],
    'database.connections.pgsql.username' => $target['username'], 'database.connections.pgsql.password' => (string) (getenv('DB_PASSWORD') ?: ''),
    'database.connections.pgsql.url' => null, 'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
    throw new RuntimeException('Executive Dashboard acceptance requires the explicitly owned PostgreSQL 17 test target.');
}
$requiredTables = ['companies', 'branches', 'users', 'access_grants', 'user_branch_scopes', 'sales_orders', 'customers',
    'projects', 'project_tenders', 'maintenance_work_orders', 'workshop_jobs', 'accounting_journals', 'rp_customer_invoices',
    'rp_supplier_invoices', 'treasury_accounts', 'treasury_opening_balances', 'treasury_movements'];
$missingTables = array_values(array_filter($requiredTables, static fn (string $table): bool => ! DB::getSchemaBuilder()->hasTable($table)));
if ($missingTables !== []) {
    throw new RuntimeException('Executive Dashboard acceptance requires applied owner migrations; missing tables: '.implode(', ', $missingTables));
}

$assertions = 0;
$checks = [];
$pass = static function (bool $condition, string $message) use (&$assertions, &$checks): void {
    $assertions++;
    $checks[] = ['name' => $message, 'result' => $condition ? 'PASS' : 'FAIL'];
    if (! $condition) {
        throw new RuntimeException($message);
    }
    fwrite(STDOUT, "PASS {$message}\n");
};
$denied = static function (callable $action, string $message, Closure $pass): void {
    try {
        $action();
    } catch (Throwable $error) {
        if (! $error instanceof HttpExceptionInterface || $error->getStatusCode() !== 403) {
            throw $error;
        }
        $pass(true, $message);

        return;
    }
    $pass(false, $message.' unexpectedly succeeded.');
};
$grant = static function (User $actor, array $permissions, ?int $branchId): void {
    foreach ($permissions as $permission) {
        $grantBranch = $permission === 'M13.account_view' ? null : $branchId;
        DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => $permission,
            'branch_id' => $grantBranch, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$scope = static function (User $actor, Branch $branch): void {
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $actor->id, 'branch_id' => $branch->id,
        'created_at' => now(), 'updated_at' => now()]);
};
$permissionMap = [
    'sales' => ['M04.sales_order_view'], 'projects' => ['M07.project_view'], 'tenders' => ['M07.tender_view'],
    'maintenance' => ['M08.view'], 'workshops' => ['M09.view'], 'accounting' => ['M11.report_view'],
    'receivables' => ['M12.ar_view', 'M12.ap_view'], 'treasury' => ['M13.account_view', 'M11.report_view'],
];
$allPermissions = array_values(array_unique(array_merge(...array_values($permissionMap))));
$section = static function (array $dashboard, string $key): array {
    $value = $dashboard['sections'][$key] ?? null;
    if (! is_array($value)) {
        throw new RuntimeException("Executive Dashboard section [{$key}] is missing or malformed.");
    }

    return $value;
};
$available = static function (array $dashboard, string $key, Closure $pass) use ($section): array {
    $row = $section($dashboard, $key);
    $pass(($row['status'] ?? null) === 'available' && ($row['scope'] ?? null) === 'authorized_branches'
        && is_array($row['metrics'] ?? null) && is_string($row['basis'] ?? null) && ($row['basis'] ?? '') !== ''
        && is_string($row['route'] ?? null) && ($row['route'] ?? '') !== '',
        "Executive Dashboard {$key} exposes scoped metrics with source basis and route");

    return $row['metrics'] ?? [];
};
$unavailable = static function (array $dashboard, string $key, Closure $pass) use ($section): void {
    $row = $section($dashboard, $key);
    $pass(($row['status'] ?? null) === 'unavailable' && is_string($row['reason'] ?? null)
        && ! array_key_exists('metrics', $row) && ! array_key_exists('amount', $row),
        "Executive Dashboard {$key} denies unavailable data without metric or amount leakage");
};
$failures = [];
$companySnapshotBefore = DB::table('companies')->where('id', 1)->first();
$companySnapshotHashBefore = $companySnapshotBefore === null ? null : hash('sha256', serialize((array) $companySnapshotBefore));
$countTables = ['companies', 'branches', 'users', 'access_grants', 'user_branch_scopes', 'customers', 'sales_orders'];
$beforeCounts = [];
foreach ($countTables as $table) {
    $beforeCounts[$table] = (int) DB::table($table)->count();
}
$afterRollbackCounts = [];
$rollbackVerified = false;
$stage = 'executive dashboard scoped and truthful summary acceptance';
DB::beginTransaction();
try {
    if (! Company::query()->whereKey(1)->exists()) {
        Company::factory()->create(['id' => 1]);
    }
    $branch = Branch::factory()->create(['company_id' => 1, 'active' => true]);
    $emptyBranch = Branch::factory()->create(['company_id' => 1, 'active' => true]);
    $writer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $emptyReader = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $restricted = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $arReader = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $apReader = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $revoked = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $disabled = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$writer, $emptyReader, $restricted, $arReader, $apReader, $revoked, $disabled] as $actor) {
        $scope($actor, $branch);
    }
    $scope($emptyReader, $emptyBranch);
    $grant($writer, $allPermissions, $branch->id);
    $grant($emptyReader, $allPermissions, $emptyBranch->id);
    $grant($restricted, ['M04.sales_order_view', 'M11.report_view'], $branch->id);
    $grant($arReader, ['M12.ar_view'], $branch->id);
    $grant($apReader, ['M12.ap_view'], $branch->id);
    $grant($revoked, $allPermissions, $branch->id);
    $grant($disabled, $allPermissions, $branch->id);

    $dashboardService = app(ExecutiveDashboard::class);
    $from = now('Africa/Cairo')->subDays(14)->toDateString();
    $to = now('Africa/Cairo')->addDays(14)->toDateString();
    $dashboard = $dashboardService->read((int) $writer->id, (int) $branch->id, $from, $to);
    $salesBefore = $available($dashboard, 'sales', $pass);
    $projectSection = $available($dashboard, 'projects', $pass);
    $tenderSection = $available($dashboard, 'tenders', $pass);
    $maintenanceMetrics = $available($dashboard, 'maintenance', $pass);
    $workshopMetrics = $available($dashboard, 'workshops', $pass);
    $accountingMetrics = $available($dashboard, 'accounting', $pass);
    $receivableMetrics = $available($dashboard, 'receivables', $pass);
    $treasuryMetrics = $available($dashboard, 'treasury', $pass);

    $pass(array_keys($accountingMetrics) === ['revenue', 'expenses', 'net']
        && array_keys($receivableMetrics) === ['customer_invoiced', 'supplier_invoiced']
        && array_keys($treasuryMetrics) === ['bank', 'cash'],
        'Dashboard presents posted GL, customer invoices, supplier invoices, and treasury balances as separate measures');
    $pass(str_contains($dashboard['sections']['accounting']['basis'] ?? '', 'الأستاذ')
        && str_contains($dashboard['sections']['receivables']['basis'] ?? '', 'الفواتير')
        && str_contains($dashboard['sections']['treasury']['basis'] ?? '', 'رصيد'),
        'Financial basis labels distinguish posted ledger, posted invoices, and cash/bank balances');
    $pass(($dashboard['sections']['accounting']['metrics']['revenue'] ?? null) === '0.00'
        && ($dashboard['sections']['accounting']['metrics']['expenses'] ?? null) === '0.00'
        && ($dashboard['sections']['receivables']['metrics']['customer_invoiced'] ?? null) === '0.00'
        && ($dashboard['sections']['receivables']['metrics']['supplier_invoiced'] ?? null) === '0.00'
        && ($dashboard['sections']['treasury']['metrics']['bank'] ?? null) === '0.00'
        && ($dashboard['sections']['treasury']['metrics']['cash'] ?? null) === '0.00',
        'A new branch reports measured zero financial values without fabricated GL, invoice, opening-balance, or cash rows');

    $order = \App\Modules\Sales\Domain\SalesOrder::factory()->create([
        'branch_id' => $branch->id,
        'branch_snapshot' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name, 'address' => $branch->address],
        'created_by' => $writer->id,
        'order_date' => now('Africa/Cairo')->toDateString(),
    ]);
    $dashboard = $dashboardService->read((int) $writer->id, (int) $branch->id, $from, $to);
    $salesAfter = $available($dashboard, 'sales', $pass);
    $pass((int) ($salesAfter['orders'] ?? -1) === (int) ($salesBefore['orders'] ?? -2) + 1
        && (int) ($salesAfter['draft'] ?? -1) === (int) ($salesBefore['draft'] ?? -2) + 1
        && $order->status === 'draft',
        'Sales dashboard reflects a real branch-owned draft order only as an order and draft, not revenue');

    $emptyDashboard = $dashboardService->read((int) $emptyReader->id, (int) $emptyBranch->id, $from, $to);
    $emptySales = $available($emptyDashboard, 'sales', $pass);
    $emptyProjects = $available($emptyDashboard, 'projects', $pass);
    $emptyTenders = $available($emptyDashboard, 'tenders', $pass);
    $emptyMaintenance = $available($emptyDashboard, 'maintenance', $pass);
    $emptyWorkshops = $available($emptyDashboard, 'workshops', $pass);
    $pass(($emptySales['orders'] ?? null) === 0 && ($emptySales['draft'] ?? null) === 0
        && ($emptyProjects['total'] ?? null) === 0 && ($emptyTenders['total'] ?? null) === 0
        && ($emptyMaintenance['total'] ?? null) === 0 && ($emptyWorkshops['total'] ?? null) === 0,
        'A separately authorized empty branch reports real zero counts instead of another branch data');

    $restrictedDashboard = $dashboardService->read((int) $restricted->id, (int) $branch->id, $from, $to);
    $unavailable($restrictedDashboard, 'projects', $pass);
    $unavailable($restrictedDashboard, 'tenders', $pass);
    $unavailable($restrictedDashboard, 'maintenance', $pass);
    $unavailable($restrictedDashboard, 'workshops', $pass);
    $unavailable($restrictedDashboard, 'receivables', $pass);
    $unavailable($restrictedDashboard, 'treasury', $pass);
    $arDashboard = $dashboardService->read((int) $arReader->id, (int) $branch->id, $from, $to);
    $arMetrics = $available($arDashboard, 'receivables', $pass);
    $pass(array_keys($arMetrics) === ['customer_invoiced'],
        'AR-only authority returns customer invoices and omits supplier amounts');
    $apDashboard = $dashboardService->read((int) $apReader->id, (int) $branch->id, $from, $to);
    $apMetrics = $available($apDashboard, 'receivables', $pass);
    $pass(array_keys($apMetrics) === ['supplier_invoiced'],
        'AP-only authority returns supplier invoices and omits customer amounts');
    $denied(fn () => $dashboardService->read((int) $writer->id, (int) $emptyBranch->id, $from, $to),
        'Dashboard rejects a selected branch outside the actor scope', $pass);

    $cachedFirst = $dashboardService->read((int) $revoked->id, (int) $branch->id, $from, $to);
    $available($cachedFirst, 'sales', $pass);
    DB::table('access_grants')->where('user_id', $revoked->id)->where('permission_code', 'M04.sales_order_view')->delete();
    $cachedAfterRevocation = $dashboardService->read((int) $revoked->id, (int) $branch->id, $from, $to);
    $unavailable($cachedAfterRevocation, 'sales', $pass);
    $disabled->forceFill(['active' => false, 'email_verified_at' => null])->save();
    $denied(fn () => $dashboardService->read((int) $disabled->id, (int) $branch->id, $from, $to),
        'Dashboard revalidates a disabled or unverified actor on each read', $pass);

    $pass(($dashboard['from'] ?? null) === $from && ($dashboard['to'] ?? null) === $to
        && ($dashboard['branch_id'] ?? null) === (int) $branch->id,
        'Dashboard response preserves the requested period and selected authorized branch');
} catch (Throwable $error) {
    $failures[] = ['class' => $error::class, 'message' => mb_substr($error->getMessage(), 0, 500)];
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

foreach ($countTables as $table) {
    $afterRollbackCounts[$table] = (int) DB::table($table)->count();
}
$companySnapshotAfter = DB::table('companies')->where('id', 1)->first();
$companySnapshotHashAfter = $companySnapshotAfter === null ? null : hash('sha256', serialize((array) $companySnapshotAfter));
$rollbackVerified = $beforeCounts === $afterRollbackCounts && $companySnapshotHashBefore === $companySnapshotHashAfter;
$assertions++;
$checks[] = ['name' => 'Dashboard fixtures and grant revocation leave owner counts and company snapshot unchanged after rollback',
    'result' => $rollbackVerified ? 'PASS' : 'FAIL'];
if (! $rollbackVerified) {
    $failures[] = ['class' => RuntimeException::class, 'message' => 'Executive Dashboard outer rollback changed a snapshotted owner count or the company record.'];
}

$receipt = ['all_pass' => $failures === [] && $rollbackVerified, 'phase' => $stage, 'assertions' => $assertions,
    'rollback_verified' => $rollbackVerified, 'checks' => $checks, 'failures' => $failures,
    'target' => ['database' => $identity->database, 'server_address' => $identity->server_address, 'port' => (int) $identity->port,
        'data_directory' => $identity->data_directory, 'current_user' => $identity->current_user],
];
$path = $writeReceipt($receipt);
fwrite($failures === [] && $rollbackVerified ? STDOUT : STDERR, 'RESULT all_pass='.($failures === [] && $rollbackVerified ? '1' : '0').' assertions='.$assertions
    .' rollback_verified='.($rollbackVerified ? '1' : '0').' receipt='.$path.PHP_EOL);
if ($failures !== [] || ! $rollbackVerified) {
    exit(1);
}
