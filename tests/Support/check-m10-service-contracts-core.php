<?php

declare(strict_types=1);

// Gate 2 M10/M01 guarded behavioral acceptance. It mutates only the owned test database inside a rollback.
use App\Models\User;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\EmployeeActions;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Parties\Application\Contracts\CustomerContractException;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use App\Modules\ServiceContracts\Domain\ServiceContract;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceContractsDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 2);
$employeeOnly = in_array('--employee-only', $argv, true);
$mode = $employeeOnly ? 'M01 employee prerequisite' : 'M10 service-contract core plus M01 employee prerequisite';
$stage = 'source preflight';
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate2';
$emitReceipt = static function (array $receipt) use ($evidenceDirectory): string {
    if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
        throw new RuntimeException('Unable to create Gate2 evidence directory.');
    }
    $stem = str_contains(strtolower($receipt['mode'] ?? ''), 'employee') ? 'm01-employee-' : 'm10-core-';
    $path = $evidenceDirectory.'/'.$stem.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);

    return $path;
};
set_exception_handler(static function (Throwable $error) use (&$stage, $employeeOnly, $emitReceipt): void {
    $message = preg_replace('/(password|secret|token)=\\S+/i', '$1=[redacted]', $error->getMessage());
    $receipt = ['all_pass' => false, 'mode' => $employeeOnly ? 'employee-only' : 'm10-core', 'phase' => $stage, 'assertions' => 0,
        'rollback_verified' => false, 'checks' => [], 'failure' => ['class' => $error::class, 'message' => mb_substr((string) $message, 0, 500)]];
    try {
        $path = $emitReceipt($receipt);
        fwrite(STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        fwrite(STDERR, 'RESULT all_pass=0 assertions=0 rollback_verified=0 receipt='.$path.PHP_EOL);
    } catch (Throwable) {
        fwrite(STDERR, 'RESULT all_pass=0 assertions=0 rollback_verified=0 receipt_write_failed=1'.PHP_EOL);
    }
    exit(1);
});
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';

$requiredFiles = [
    'M01 employee actions' => $root.'/app/Modules/Organization/Application/EmployeeActions.php',
    'M01 employee API contract' => $root.'/app/Modules/Organization/Application/Contracts/OrganizationApi.php',
    'M01 employee domain model' => $root.'/app/Modules/Organization/Domain/Employee.php',
    'M01 employee factory' => $root.'/database/factories/EmployeeFactory.php',
];
$requiredClasses = [
    'M01 employee actions' => EmployeeActions::class,
    'M01 employee model' => Employee::class,
    'M01 organization API' => OrganizationApi::class,
];
if (! $employeeOnly) {
    $requiredFiles += [
        'M10 owner actions' => $root.'/app/Modules/ServiceContracts/Application/ServiceContractActions.php',
        'M10 public contract' => $root.'/app/Modules/ServiceContracts/Application/Contracts/ServiceContractsApi.php',
        'M10 private evidence owner' => $root.'/app/Modules/ServiceContracts/Application/ServiceContractDocuments.php',
        'M10 contract domain model' => $root.'/app/Modules/ServiceContracts/Domain/ServiceContract.php',
        'M10 realistic factory' => $root.'/database/factories/ServiceContractFactory.php',
        'M10 idempotent demo seeder' => $root.'/database/seeders/ServiceContractsDemoSeeder.php',
    ];
    $requiredClasses += [
        'M10 actions' => ServiceContractActions::class,
        'M10 API' => ServiceContractsApi::class,
        'M10 documents' => ServiceContractDocuments::class,
        'M10 model' => ServiceContract::class,
        'M10 demo seeder' => ServiceContractsDemoSeeder::class,
    ];
}
$missingFiles = array_keys(array_filter($requiredFiles, fn (string $path): bool => ! is_file($path)));
$missingClasses = array_keys(array_filter($requiredClasses, fn (string $class): bool => ! class_exists($class) && ! interface_exists($class)));
$registryReady = true;
$missingPermissions = [];
if (! $employeeOnly) {
    $registry = require $root.'/config/modules.php';
    $m10 = $registry['ServiceContracts'] ?? null;
    $registryReady = is_array($m10) && ($m10['id'] ?? null) === 'M10'
        && ($m10['permission_prefix'] ?? null) === 'M10' && ($m10['implemented'] ?? false) === true;
}
if (class_exists(PermissionCatalog::class)) {
    $definitions = PermissionCatalog::DEFINITIONS;
    $permissions = ['M01.employee_view', 'M01.employee_manage'];
    if (! $employeeOnly) {
        $permissions = array_merge($permissions, ['M10.contract_view', 'M10.contract_create', 'M10.contract_edit', 'M10.contract_approve',
            'M10.contract_sign', 'M10.contract_activate', 'M10.coverage_use', 'M10.billing_manage', 'M10.billing_approve',
            'M10.document_manage', 'M10.document_view', 'M10.reports_view']);
    }
    foreach ($permissions as $permission) {
        if (($definitions[$permission][1] ?? null) !== 'branch') {
            $missingPermissions[] = $permission;
        }
    }
} else {
    $missingPermissions[] = 'PermissionCatalog';
}

if ($missingFiles !== [] || $missingClasses !== [] || $missingPermissions !== [] || ! $registryReady) {
    $receipt = ['all_pass' => false, 'phase' => $employeeOnly ? 'M01 employee source preflight' : 'Gate2 source preflight before M10 acceptance',
        'scope' => $mode, 'm10_registry_ready' => $registryReady, 'missing_files' => $missingFiles,
        'missing_classes' => $missingClasses, 'missing_or_wrong_scope_permissions' => $missingPermissions];
    $evidenceDirectory = $root.'/docs/release/remediation/evidence/gate2';
    if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
        fwrite(STDERR, "Unable to create Gate2 evidence directory.\n");
        exit(2);
    }
    $evidencePath = $evidenceDirectory.'/m10-core-red.json';
    if (is_file($evidencePath)) {
        $evidencePath = $evidenceDirectory.'/m10-core-red-attempt-'.gmdate('Ymd\\THis\\Z').'.json';
    }
    file_put_contents($evidencePath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    fwrite(STDERR, 'RESULT source_preflight=RED missing_files='.count($missingFiles).' missing_classes='.count($missingClasses)
        .' missing_permissions='.count($missingPermissions).' registry_ready='.($registryReady ? 'yes' : 'no').' evidence='.$evidencePath.PHP_EOL);
    exit(1);
}

$environment = [
    'APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres'),
];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = OptimizationRegressionDatabaseGuard::target($root, $environment);
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$privateRoot = $taskRoot.'/m10-private-storage-'.bin2hex(random_bytes(8));
$cacheRoot = $taskRoot.'/m10-check-cache';
if (! is_dir($privateRoot) && ! mkdir($privateRoot, 0770, true) && ! is_dir($privateRoot)) {
    throw new RuntimeException('Could not create isolated M10 private-file scratch directory.');
}
register_shutdown_function(static function () use ($privateRoot, $taskRoot): void {
    if (is_dir($privateRoot) && realpath(dirname($privateRoot)) === realpath($taskRoot)
        && str_starts_with(basename($privateRoot), 'm10-private-storage-')) {
        (new Filesystem)->deleteDirectory($privateRoot);
    }
});
$cacheRelative = '.tmp/master-remediation-20261101/m10-check-cache';
$viewsRelative = '.tmp/master-remediation-20261101/m10-views';
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'], 'DB_PORT' => (string) $target['port'],
    'DB_DATABASE' => $target['database'], 'DB_USERNAME' => $target['username'], 'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''),
    'DB_URL' => '', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'LARAVEL_STORAGE_PATH' => $taskRoot.'/m10-app-storage', 'APP_CONFIG_CACHE' => $cacheRelative.'/config.php',
    'APP_ROUTES_CACHE' => $cacheRelative.'/routes.php', 'APP_EVENTS_CACHE' => $cacheRelative.'/events.php',
    'APP_SERVICES_CACHE' => $cacheRelative.'/services.php', 'APP_PACKAGES_CACHE' => $cacheRelative.'/packages.php',
    'APP_VIEW_COMPILED_PATH' => $viewsRelative, 'VIEW_COMPILED_PATH' => $viewsRelative,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach ([$cacheRoot, $taskRoot.'/m10-app-storage', $taskRoot.'/m10-views'] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create isolated M10 runtime directory.');
    }
}
$stage = 'Laravel bootstrap and exact PostgreSQL guard';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($taskRoot.'/m10-app-storage');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $target['connection_host'],
    'database.connections.pgsql.port' => $target['port'], 'database.connections.pgsql.database' => $target['database'],
    'database.connections.pgsql.username' => $target['username'], 'database.connections.pgsql.password' => (string) (getenv('DB_PASSWORD') ?: ''),
    'database.connections.pgsql.url' => null, 'filesystems.disks.local.root' => $privateRoot,
    'filesystems.disks.local.visibility' => 'private', 'filesystems.disks.local.throw' => true,
    'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
    throw new RuntimeException('M10 acceptance requires the explicitly owned PostgreSQL 17 test target.');
}
$stage = 'M01/M10 schema preflight';
if (! DB::getSchemaBuilder()->hasTable('organization_employees')) {
    throw new RuntimeException('M01 Employee acceptance requires its forward migration to be applied to this isolated test database.');
}
if (! $employeeOnly && ! DB::getSchemaBuilder()->hasTable('service_contracts')) {
    throw new RuntimeException('M10 acceptance requires its forward migration to be applied to this isolated test database.');
}

$assertions = 0;
$checkResults = [];
$pass = static function (bool $condition, string $message) use (&$assertions, &$checkResults): void {
    $assertions++;
    $checkResults[] = ['name' => $message, 'result' => $condition ? 'PASS' : 'FAIL'];
    if (! $condition) {
        throw new RuntimeException($message);
    }
    fwrite(STDOUT, "PASS {$message}\n");
};
$reject = static function (callable $action, string $message, ?int $status = null) use ($pass): void {
    try {
        $action();
    } catch (Throwable $error) {
        $denied = $error instanceof ValidationException || $error instanceof ContractException || $error instanceof CustomerContractException
            || ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404, 409, 422], true));
        if (! $denied || ($status !== null && (! $error instanceof HttpExceptionInterface || $error->getStatusCode() !== $status))) {
            throw $error;
        }
        $pass(true, $message);

        return;
    }
    $pass(false, $message.' unexpectedly succeeded.');
};
$grant = static function (User $actor, array $permissions, ?int $branchId): void {
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => $permission,
            'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$scope = static function (User $actor, Branch $branch): void {
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $actor->id, 'branch_id' => $branch->id,
        'created_at' => now(), 'updated_at' => now()]);
};
$uuid = static fn (): string => (string) Str::uuid();
$failure = null;
$beforeCounts = m10AcceptanceCounts();
$fixtureCounts = $beforeCounts;
$afterRollbackCounts = [];
$stage = 'M01/M10 transactional behavioral acceptance';
DB::beginTransaction();
try {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create();
    }
    $branch = Branch::factory()->create(['company_id' => 1, 'active' => true]);
    $otherBranch = Branch::factory()->create(['company_id' => 1, 'active' => true]);
    $writer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$writer, $reviewer] as $actor) {
        $scope($actor, $branch);
    }
    $grant($writer, ['M01.employee_manage', 'M01.employee_view'], $branch->id);
    $grant($writer, ['M03.customer_view', 'M02.product_view'], null);
    $grant($reviewer, ['M01.employee_view'], $branch->id);

    $securityBeforeFactory = [
        'scopes' => DB::table('user_branch_scopes')->count(), 'grants' => DB::table('access_grants')->count(),
        'roles' => DB::table('user_roles')->count(),
    ];
    $factoryEmployee = Employee::factory()->create(['branch_id' => $branch->id]);
    $factoryEmployee->refresh();
    $pass($factoryEmployee->exists && (int) $factoryEmployee->branch_id === (int) $branch->id && (int) $factoryEmployee->version === 1
        && $factoryEmployee->user_id === null && $factoryEmployee->active,
        'M01 Employee factory persists an active employment identity without a linked login or authority');
    $pass($securityBeforeFactory === ['scopes' => DB::table('user_branch_scopes')->count(), 'grants' => DB::table('access_grants')->count(),
        'roles' => DB::table('user_roles')->count()],
        'M01 Employee factory creates no authentication, role, permission, or branch-scope history');

    $today = now('Africa/Cairo')->toDateString();
    $employeeCode = 'DEMO-EG-M10-EMP-'.Str::upper(Str::random(8));
    $employeeInput = ['code' => $employeeCode, 'name' => 'فني قبول عقود الصيانة', 'branch_id' => $branch->id, 'user_id' => null,
        'starts_on' => now('Africa/Cairo')->subDay()->toDateString(), 'ends_on' => null, 'active' => true, 'job_title' => 'فني كهرباء'];
    $employeeId = app(EmployeeActions::class)->save((int) $writer->id, null, 0, $employeeInput);
    $employee = DB::table('organization_employees')->find($employeeId);
    $pass($employeeId > 0 && $employee->code === $employeeCode && $employee->version === 1 && $employee->branch_id === $branch->id
        && $employee->user_id === null && $employee->starts_on <= $today && $employee->active,
        'M01 EmployeeActions creates a versioned branch-owned employment record from controlled master fields');
    $employeeInput['job_title'] = 'مهندس خدمة';
    app(EmployeeActions::class)->save((int) $writer->id, $employeeId, 1, $employeeInput);
    $pass(DB::table('organization_employees')->where('id', $employeeId)->where('version', 2)->where('job_title', 'مهندس خدمة')->exists(),
        'M01 EmployeeActions updates permitted fields with an optimistic version increment');
    $employeeInput['code'] = 'DEMO-EG-M10-IMMUTABLE-'.Str::upper(Str::random(5));
    $reject(fn () => app(EmployeeActions::class)->save((int) $writer->id, $employeeId, 2, $employeeInput),
        'M01 employee code is immutable after identity creation', 409);
    $employeeInput['code'] = $employeeCode;
    $employeeInput['branch_id'] = $otherBranch->id;
    $reject(fn () => app(EmployeeActions::class)->save((int) $writer->id, $employeeId, 2, $employeeInput),
        'M01 employee branch is immutable after identity creation', 409);
    $employeeInput['branch_id'] = $branch->id;
    $reject(fn () => app(EmployeeActions::class)->save((int) $outsider->id, null, 0, $employeeInput),
        'M01 employee creation requires explicit branch permission and scope');

    $employeeIdentity = app(OrganizationApi::class)->employee((int) $reviewer->id, $employeeId, (int) $branch->id, $today);
    $pass((int) ($employeeIdentity['id'] ?? 0) === $employeeId && (int) ($employeeIdentity['branch_id'] ?? 0) === (int) $branch->id
        && ($employeeIdentity['active'] ?? false) === true && ! array_key_exists('salary', $employeeIdentity)
        && ! array_key_exists('hourly_cost', $employeeIdentity) && ! array_key_exists('pay_rate', $employeeIdentity)
        && array_diff(array_keys($employeeIdentity), ['id', 'code', 'name', 'branch_id', 'user_id', 'starts_on', 'ends_on', 'active', 'version']) === [],
        'M01 employee contract returns current scoped employment identity without payroll or costing fields');
    $reject(fn () => app(OrganizationApi::class)->employee((int) $outsider->id, $employeeId, (int) $branch->id, $today),
        'M01 employee contract denies unscoped and unpermitted readers');
    $reject(fn () => app(OrganizationApi::class)->employee((int) $reviewer->id, $employeeId, (int) $otherBranch->id, $today),
        'M01 employee contract hides identities outside the requested branch');

    $inactiveId = app(EmployeeActions::class)->save((int) $writer->id, null, 0, [
        'code' => 'DEMO-EG-M10-INACTIVE-'.Str::upper(Str::random(6)), 'name' => 'موظف غير نشط', 'branch_id' => $branch->id,
        'user_id' => null, 'starts_on' => $today, 'ends_on' => null, 'active' => false, 'job_title' => null,
    ]);
    $reject(fn () => app(OrganizationApi::class)->employee((int) $reviewer->id, $inactiveId, (int) $branch->id, $today),
        'M01 employment contract denies inactive employee identities');
    $expiredId = app(EmployeeActions::class)->save((int) $writer->id, null, 0, [
        'code' => 'DEMO-EG-M10-EXPIRED-'.Str::upper(Str::random(6)), 'name' => 'موظف انتهى تعاقده', 'branch_id' => $branch->id,
        'user_id' => null, 'starts_on' => now('Africa/Cairo')->subDays(3)->toDateString(),
        'ends_on' => now('Africa/Cairo')->subDays(2)->toDateString(), 'active' => true, 'job_title' => null,
    ]);
    $reject(fn () => app(OrganizationApi::class)->employee((int) $reviewer->id, $expiredId, (int) $branch->id, $today),
        'M01 employment contract denies identities outside their effective date range');

    if (! $employeeOnly) {
        runM10Acceptance($branch, $otherBranch, $writer, $reviewer, $outsider, $employeeId, $inactiveId, $expiredId, $today, $grant, $scope, $pass, $reject, $uuid);
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try {
        $fixtureCounts = m10AcceptanceCounts();
    } finally {
        DB::rollBack();
        $afterRollbackCounts = m10AcceptanceCounts();
    }
    if (realpath(dirname($privateRoot)) === realpath($taskRoot) && str_starts_with(basename($privateRoot), 'm10-private-storage-')) {
        (new Filesystem)->deleteDirectory($privateRoot);
    }
}
$storageClean = ! is_dir($privateRoot);
$rollbackClean = $beforeCounts === $afterRollbackCounts && $storageClean;
$checkResults[] = ['name' => 'All fixture database rows roll back after acceptance.', 'result' => $rollbackClean ? 'PASS' : 'FAIL'];
$assertions++;
if (! $rollbackClean && $failure === null) {
    $failure = new RuntimeException('M10/M01 acceptance left database rows after rollback.');
}
if ($failure !== null && ! collect($checkResults)->contains(fn (array $check): bool => $check['result'] === 'FAIL')) {
    $checkResults[] = ['name' => 'M01/M10 acceptance finishes without an unexpected exception.', 'result' => 'FAIL'];
    $assertions++;
}
$receipt = ['all_pass' => $failure === null, 'mode' => $employeeOnly ? 'employee-only' : 'm10-core', 'assertions' => $assertions,
    'rollback_verified' => $rollbackClean, 'private_scratch_clean' => $storageClean,
    'database' => ['name' => $identity->database, 'server_address' => $identity->server_address, 'port' => (int) $identity->port,
        'postgres_major' => intdiv((int) $identity->server_version_num, 10000)],
    'counts' => ['before' => $beforeCounts, 'during_fixture' => $fixtureCounts, 'after_rollback' => $afterRollbackCounts],
    'checks' => $checkResults, 'failure' => $failure === null ? null : ['class' => $failure::class,
        'message' => mb_substr((string) preg_replace('/(password|secret|token)=\\S+/i', '$1=[redacted]', $failure->getMessage()), 0, 500)]];
$receiptPath = $emitReceipt($receipt);
fwrite(($failure === null ? STDOUT : STDERR), json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
fwrite(($failure === null ? STDOUT : STDERR), 'RESULT all_pass='.($failure === null ? '1' : '0').' assertions='.$assertions
    .' rollback_verified='.($rollbackClean ? '1' : '0').' receipt='.$receiptPath.PHP_EOL);
if ($failure !== null) {
    exit(1);
}

function m10AcceptanceCounts(): array
{
    $tables = ['companies', 'branches', 'users', 'customers', 'products', 'product_categories', 'brands', 'units_of_measure', 'product_unit_conversions',
        'organization_employees', 'service_contracts', 'service_contract_revisions', 'service_contract_documents', 'service_contract_activities',
        'service_contract_work', 'service_contract_work_parts', 'access_grants', 'user_branch_scopes', 'user_roles', 'audit_entries'];
    $counts = [];
    foreach ($tables as $table) {
        if (DB::getSchemaBuilder()->hasTable($table)) {
            $counts[$table] = (int) DB::table($table)->count();
        }
    }

    return $counts;
}

/** Exercise owner actions and persistence with synthetic branch-scoped records. */
function runM10Acceptance(Branch $branch, Branch $otherBranch, User $writer, User $reviewer, User $outsider, int $employeeId,
    int $inactiveEmployeeId, int $expiredEmployeeId,
    string $today, Closure $grant, Closure $scope, Closure $pass, Closure $reject, Closure $uuid): void
{
    $customer = Customer::factory()->create(['status' => 'active']);
    $inactiveCustomer = Customer::factory()->create(['status' => 'inactive']);
    $serviceProduct = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $partProduct = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $otherPart = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    foreach ([$writer, $reviewer] as $actor) {
        $scope($actor, $branch);
    }
    $grant($writer, ['M10.contract_view', 'M10.contract_create', 'M10.contract_edit', 'M10.contract_sign', 'M10.contract_activate',
        'M10.coverage_use', 'M10.billing_manage', 'M10.document_manage', 'M10.document_view'], $branch->id);
    $grant($writer, ['M03.customer_view', 'M02.product_view'], null);
    $grant($reviewer, ['M10.contract_view', 'M10.contract_approve', 'M10.contract_activate', 'M10.document_view', 'M10.billing_approve'], $branch->id);
    $grant($reviewer, ['M01.employee_view'], $branch->id);
    $grant($reviewer, ['M03.customer_view', 'M02.product_view'], null);
    $grant($outsider, ['M10.contract_view', 'M10.contract_create'], $otherBranch->id);
    $inactiveAccount = User::factory()->create(['active' => false, 'email_verified_at' => now()]);
    $unverifiedAccount = User::factory()->create(['active' => true, 'email_verified_at' => null]);
    foreach ([$inactiveAccount, $unverifiedAccount] as $actor) {
        $scope($actor, $branch);
        $grant($actor, ['M10.contract_create'], $branch->id);
    }

    $from = now('Africa/Cairo')->subDay()->toDateString();
    $through = now('Africa/Cairo')->addMonths(6)->toDateString();
    $data = [
        'branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_employee_id' => $employeeId,
        'title' => 'عقد صيانة سنوي — قبول آلي', 'starts_on' => $from, 'ends_on' => $through, 'value' => '1500.00',
        'service_product_id' => $serviceProduct->id, 'billing_mode' => 'installments', 'terms' => 'خدمات صيانة محددة دون ضريبة مخترعة.',
        'sla_response_minutes' => 240, 'sla_resolution_minutes' => 1440, 'covered_labor_minutes' => 600,
        'coverage' => [['equipment_ref' => 'cb-001', 'site_id' => null, 'preventive_visits' => 3, 'corrective_visits' => 2]],
        'parts' => [['product_id' => $partProduct->id, 'quantity' => '2.500']], 'excluded_parts' => [$otherPart->id], 'boq' => [],
        'schedule' => [
            ['code' => 'INST-01', 'due_date' => now('Africa/Cairo')->addDays(10)->toDateString(), 'amount' => '500.00', 'description' => 'الدفعة الأولى'],
            ['code' => 'INST-02', 'due_date' => now('Africa/Cairo')->addMonths(2)->toDateString(), 'amount' => '500.00', 'description' => 'الدفعة الثانية'],
            ['code' => 'INST-03', 'due_date' => now('Africa/Cairo')->addMonths(4)->toDateString(), 'amount' => '500.00', 'description' => 'الدفعة الثالثة'],
        ],
    ];
    $beforeFactorySecurity = ['grants' => DB::table('access_grants')->count(), 'scopes' => DB::table('user_branch_scopes')->count(),
        'roles' => DB::table('user_roles')->count()];
    $factoryContract = ServiceContract::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id,
        'responsible_employee_id' => $employeeId, 'created_by' => $writer->id]);
    $factoryContract->refresh();
    $factoryRevision = DB::table('service_contract_revisions')->find($factoryContract->current_revision_id);
    $factoryData = is_string($factoryRevision->data ?? null) ? json_decode($factoryRevision->data, true, 512, JSON_THROW_ON_ERROR)
        : (array) ($factoryRevision->data ?? []);
    $factorySchedule = '0.00';
    foreach (($factoryData['schedule'] ?? []) as $entry) {
        $factorySchedule = bcadd($factorySchedule, (string) $entry['amount'], 2);
    }
    $factoryStart = CarbonImmutable::parse($factoryData['starts_on'], 'Africa/Cairo');
    $quarterlyFacts = array_map(static fn (array $entry): array => [
        (string) ($entry['code'] ?? ''), (string) ($entry['due_date'] ?? ''),
        (string) ($entry['amount'] ?? ''), (string) ($entry['description'] ?? ''),
    ], $factoryData['schedule'] ?? []);
    $expectedQuarterlyFacts = array_map(static fn (int $quarter): array => [
        'Q'.$quarter, $factoryStart->addMonths(($quarter - 1) * 3)->toDateString(), '3000.00', 'قسط صيانة تجريبي ربع سنوي',
    ], [1, 2, 3, 4]);
    $quarterlySchedule = count($quarterlyFacts) === 4 && $quarterlyFacts === $expectedQuarterlyFacts
        && count(array_filter($factoryData['schedule'] ?? [], static fn (array $entry): bool => isset($entry['due_date'])
            && $entry['due_date'] >= $factoryData['starts_on'] && $entry['due_date'] <= $factoryData['ends_on'])) === 4;
    $pass($factoryContract->exists && $factoryContract->status === 'draft' && $factoryRevision->status === 'draft'
        && $factoryContract->active_revision_id === null && $factoryRevision->approved_by === null
        && $factoryRevision->signed_by === null && $factoryRevision->signature_document_id === null
        && bccomp($factorySchedule, '12000.00', 2) === 0 && $quarterlySchedule
        && ! DB::table('service_contract_documents')->where('contract_id', $factoryContract->id)->exists(),
        'M10 factory persists a coherent Egyptian draft with four exact quarterly installments inside the term and no approval or signature evidence');
    $pass($beforeFactorySecurity === ['grants' => DB::table('access_grants')->count(), 'scopes' => DB::table('user_branch_scopes')->count(),
        'roles' => DB::table('user_roles')->count()],
        'M10 factory creates no authentication privileges or branch authority');

    $demoKeys = array_map(fn (int $i): string => Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-demo-eg-service-contract-'.$i)->toString(), [1, 2, 3]);
    app(ServiceContractsDemoSeeder::class)->run();
    $firstSeedCounts = m10AcceptanceCounts();
    app(ServiceContractsDemoSeeder::class)->run();
    $secondSeedCounts = m10AcceptanceCounts();
    $demoRows = DB::table('service_contracts')->whereIn('creation_key', $demoKeys)->orderBy('creation_key')->get();
    $demoIds = $demoRows->pluck('id')->all();
    $demoRevisions = DB::table('service_contract_revisions')->whereIn('contract_id', $demoIds)->get();
    $pass($demoRows->count() === 3 && $demoRows->every(fn ($row): bool => $row->status === 'draft' && $row->active_revision_id === null)
        && $demoRevisions->count() === 3 && $demoRevisions->every(fn ($row): bool => $row->status === 'draft'
            && $row->approved_by === null && $row->signed_by === null && $row->signature_document_id === null)
        && ! DB::table('service_contract_documents')->whereIn('contract_id', $demoIds)->exists(),
        'M10 explicit demo seeder creates only identifiable draft contracts without approval, signature, activation, or evidence');
    $demoIdentities = DB::table('audit_entries')->where('source_module', 'ServiceContracts')->where('source_type', 'service_contract')
        ->whereIn('source_id', $demoIds)->where('action', 'demo.created')->get()->map(fn ($row): string => (string) (json_decode($row->safe_after, true, 512, JSON_THROW_ON_ERROR)['identity'] ?? ''))->sort()->values()->all();
    $pass($demoIdentities === ['DEMO-EG-SC-001', 'DEMO-EG-SC-002', 'DEMO-EG-SC-003'],
        'M10 seeded draft identities are stable DEMO-EG records with safe synthetic audit attribution');
    $pass($firstSeedCounts === $secondSeedCounts && $demoRows->count() === 3,
        'M10 explicit demo seeder repeat is idempotent and does not duplicate synthetic contract graphs or audit entries');

    $actions = app(ServiceContractActions::class);
    $key = $uuid();
    $contractId = $actions->save((int) $writer->id, null, 0, $key, $data);
    $pass($contractId > 0 && $actions->save((int) $writer->id, null, 0, $key, $data) === $contractId,
        'M10 creation key safely replays the same draft without duplicating contract or schedule');
    $reject(fn () => $actions->save((int) $writer->id, null, 0, $key, array_replace($data, ['title' => 'محتوى مختلف للمفتاح نفسه'])),
        'M10 rejects reuse of a creation key with changed contract terms', 409);
    $header = DB::table('service_contracts')->find($contractId);
    $pass($header !== null && $header->status === 'draft' && (int) $header->version === 1
        && (int) $header->branch_id === (int) $branch->id && (int) $header->customer_id === (int) $customer->id
        && (int) $header->responsible_employee_id === $employeeId,
        'M10 contract header persists its branch, customer and M01 responsible employee identity');
    $revision = DB::table('service_contract_revisions')->find($header->current_revision_id);
    $revisionData = is_string($revision->data ?? null) ? json_decode($revision->data, true, 512, JSON_THROW_ON_ERROR) : (array) ($revision->data ?? []);
    $scheduleTotal = '0.00';
    foreach (($revisionData['schedule'] ?? []) as $installment) {
        $scheduleTotal = bcadd($scheduleTotal, (string) $installment['amount'], 2);
    }
    $pass($revision !== null && (int) $revision->created_by === (int) $writer->id
        && bccomp($scheduleTotal, '1500.00', 2) === 0 && count($revisionData['schedule'] ?? []) === 3,
        'M10 persists the signed-value snapshot and three exact decimal installments summing to contract value');
    $beforeRejectedSaves = DB::table('service_contracts')->count();
    $reject(fn () => $actions->save((int) $writer->id, null, 0, $uuid(), array_replace($data, ['value' => '1500.01'])),
        'M10 rejects installment schedules whose exact total differs from signed value');
    $reject(fn () => $actions->save((int) $writer->id, null, 0, $uuid(), array_replace($data, ['customer_id' => $inactiveCustomer->id])),
        'M10 rejects an inactive or unavailable customer');
    $reject(fn () => $actions->save((int) $outsider->id, null, 0, $uuid(), array_replace($data, ['branch_id' => $otherBranch->id])),
        'M10 contract create checks explicit branch scope and permission');
    $reject(fn () => $actions->save((int) $inactiveAccount->id, null, 0, $uuid(), $data),
        'M10 contract create rejects inactive accounts with an otherwise valid grant and branch scope');
    $reject(fn () => $actions->save((int) $unverifiedAccount->id, null, 0, $uuid(), $data),
        'M10 contract create rejects unverified accounts with an otherwise valid grant and branch scope');
    $reject(fn () => $actions->save((int) $writer->id, null, 0, $uuid(), array_replace($data, ['service_product_id' => $partProduct->id])),
        'M10 contract requires an active service product, not an inventory part');
    $reject(fn () => $actions->save((int) $writer->id, $contractId, 99, $uuid(), $data),
        'M10 stale contract version cannot overwrite a draft', 409);
    $reject(fn () => $actions->save((int) $writer->id, null, 0, $uuid(), array_replace($data, ['responsible_employee_id' => 0])),
        'M10 requires a real M01 employee identity');
    $reject(fn () => $actions->save((int) $writer->id, null, 0, $uuid(), array_replace($data, ['responsible_employee_id' => $inactiveEmployeeId])),
        'M10 rejects an inactive M01 employee as contract authority');
    $reject(fn () => $actions->save((int) $writer->id, null, 0, $uuid(), array_replace($data, ['responsible_employee_id' => $expiredEmployeeId])),
        'M10 rejects an M01 employee whose employment ended before the contract begins');
    $reject(fn () => $actions->save((int) $writer->id, null, 0, $uuid(), array_replace($data, ['schedule' => [
        ['code' => 'INST-LATE', 'due_date' => now('Africa/Cairo')->addMonths(8)->toDateString(), 'amount' => '1500.00', 'description' => 'موعد خارج المدة'],
    ]])), 'M10 rejects a scheduled installment outside the signed contract term');
    $pass(DB::table('service_contracts')->count() === $beforeRejectedSaves,
        'M10 rejected terms leave no partial contract or revision rows');

    $measuredData = array_replace($data, ['title' => 'عقد أعمال مقاسة للاختبار', 'billing_mode' => 'measured_work', 'schedule' => [],
        'boq' => [['code' => 'BOQ-01', 'description' => 'صيانة مجدولة للوحة كهربائية', 'product_id' => $serviceProduct->id,
            'quantity' => '2.00000000', 'rate' => '750.00']]]);
    $measuredId = $actions->save((int) $writer->id, null, 0, $uuid(), $measuredData);
    $measuredHeader = DB::table('service_contracts')->find($measuredId);
    $measuredRevision = DB::table('service_contract_revisions')->find($measuredHeader->current_revision_id);
    $measuredSnapshot = json_decode($measuredRevision->data, true, 512, JSON_THROW_ON_ERROR);
    $pass($measuredSnapshot['billing_mode'] === 'measured_work' && $measuredSnapshot['schedule'] === []
        && count($measuredSnapshot['boq']) === 1 && $measuredSnapshot['boq'][0]['quantity'] === '2.00000000'
        && $measuredSnapshot['boq'][0]['rate'] === '750.00',
        'M10 measured-work contract persists a distinct exclusive quantity-and-rate BOQ in exact decimal strings');
    $beforeMixedMode = DB::table('service_contracts')->count();
    $reject(fn () => $actions->save((int) $writer->id, null, 0, $uuid(), array_replace($measuredData, ['schedule' => $data['schedule']])),
        'M10 rejects mixed installment and measured-work billing modes');
    $pass(DB::table('service_contracts')->count() === $beforeMixedMode,
        'M10 mixed billing-mode rejection leaves no partial contract');

    $documentActions = app(ServiceContractDocuments::class);
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF";
    $staleSignatureDocument = $documentActions->store((int) $writer->id, $contractId,
        UploadedFile::fake()->createWithContent('stale-signature.pdf', $pdf), 'signature', 'توقيع قبل تعديل الشروط');
    $staleSignature = DB::table('service_contract_documents')->find($staleSignatureDocument);

    $updatedData = array_replace($data, ['title' => 'عقد صيانة — تعديل مسودة مضبوط']);
    $actions->save((int) $writer->id, $contractId, (int) $header->version, $uuid(), $updatedData);
    $header = DB::table('service_contracts')->find($contractId);
    $revision = DB::table('service_contract_revisions')->find($header->current_revision_id);
    $revisionData = is_string($revision->data) ? json_decode($revision->data, true, 512, JSON_THROW_ON_ERROR) : (array) $revision->data;
    $pass((int) $header->version === 2 && $revisionData['title'] === $updatedData['title'],
        'M10 saves edits only to the current draft and advances the contract version');
    $reject(fn () => $actions->save((int) $writer->id, $contractId, 1, $uuid(), $updatedData),
        'M10 rejects a stale draft edit after the version advances', 409);
    $pass(! hash_equals($staleSignature->terms_sha256, hash('sha256', $revision->data)),
        'M10 draft edits invalidate previously uploaded signature evidence for the old terms digest');

    $signatureDocument = $documentActions->store((int) $writer->id, $contractId,
        UploadedFile::fake()->createWithContent('توقيع-عقد.pdf', $pdf), 'signature', 'اختبار توقيع أصلي');
    $signatureRow = DB::table('service_contract_documents')->find($signatureDocument);
    $pass($signatureRow !== null && hash_equals(hash('sha256', $pdf), trim($signatureRow->sha256))
        && (int) $signatureRow->contract_id === $contractId && (int) $signatureRow->revision_id === (int) $header->current_revision_id
        && Storage::disk('local')->exists($signatureRow->path) && ! Storage::disk('public')->exists($signatureRow->path),
        'M10 signature file persists privately with its real SHA-256 and exact current-revision owner link');
    $beforeRejectedDocuments = DB::table('service_contract_documents')->count();
    $beforeRejectedFiles = Storage::disk('local')->allFiles();
    $reject(fn () => $documentActions->store((int) $writer->id, $contractId,
        UploadedFile::fake()->create('unsupported.exe', 1, 'application/octet-stream'), 'signature', 'نوع ملف غير مسموح'),
        'M10 refuses unsupported private evidence types');
    $pass(DB::table('service_contract_documents')->count() === $beforeRejectedDocuments
        && Storage::disk('local')->allFiles() === $beforeRejectedFiles,
        'M10 invalid evidence leaves neither a document metadata row nor a private file');
    $download = $documentActions->download((int) $writer->id, $signatureDocument);
    $pass($signatureDocument > 0 && $download->getStatusCode() === 200
        && str_contains(strtolower((string) $download->headers->get('Cache-Control')), 'private')
        && str_contains(strtolower((string) $download->headers->get('Cache-Control')), 'no-store')
        && strtolower((string) $download->headers->get('X-Content-Type-Options')) === 'nosniff',
        'M10 signature evidence downloads only as a private, non-cacheable, nosniff response');
    Storage::disk('local')->put($signatureRow->path, $pdf.'tampered');
    $reject(fn () => $documentActions->download((int) $writer->id, $signatureDocument),
        'M10 refuses evidence whose private file no longer matches its persisted SHA-256', 409);
    Storage::disk('local')->put($signatureRow->path, $pdf);
    $reject(fn () => $documentActions->download((int) $outsider->id, $signatureDocument),
        'M10 private contract evidence download requires scoped document permission');
    $otherContract = $actions->save((int) $writer->id, null, 0, $uuid(), $data);
    $foreignDocument = $documentActions->store((int) $writer->id, $otherContract,
        UploadedFile::fake()->createWithContent('foreign-signature.pdf', $pdf), 'signature', 'توقيع عقد آخر');
    $actions->submit((int) $writer->id, $contractId, (int) $header->version);
    $header = DB::table('service_contracts')->find($contractId);
    $reject(fn () => $actions->approve((int) $writer->id, $contractId, (int) $header->version),
        'M10 creator cannot approve their own service-contract revision');
    $actions->approve((int) $reviewer->id, $contractId, (int) $header->version);
    $header = DB::table('service_contracts')->find($contractId);
    $pass($header->status === 'approved' && (int) $header->version === 4
        && (int) DB::table('service_contract_revisions')->where('id', $header->current_revision_id)->value('approved_by') === (int) $reviewer->id,
        'M10 approval is independent of the revision creator and versions the approved lifecycle');
    $reject(fn () => $actions->sign((int) $writer->id, $contractId, (int) $header->version, $staleSignatureDocument, $today),
        'M10 refuses a signature document uploaded before the current draft terms digest');
    $reject(fn () => $actions->sign((int) $writer->id, $contractId, (int) $header->version, $foreignDocument, $today),
        'M10 refuses signature evidence owned by another contract');
    $reject(fn () => $actions->sign((int) $writer->id, $contractId, (int) $header->version, $signatureDocument, now('Africa/Cairo')->addDay()->toDateString()),
        'M10 refuses a future signature date');
    $pass(DB::table('service_contracts')->where('id', $contractId)->where('status', 'approved')->where('version', $header->version)->exists()
        && DB::table('service_contract_revisions')->where('id', $header->current_revision_id)->where('status', 'approved')
            ->whereNull('signed_by')->whereNull('signature_document_id')->exists(),
        'M10 rejected signature attempts leave approval state, revision, and contract version unchanged');
    $actions->sign((int) $writer->id, $contractId, (int) $header->version, $signatureDocument, $today);
    $header = DB::table('service_contracts')->find($contractId);
    $signedRevision = DB::table('service_contract_revisions')->find($header->current_revision_id);
    $pass($header->status === 'signed' && (int) $signedRevision->signed_by === (int) $writer->id
        && (int) $signedRevision->signature_document_id === $signatureDocument && $signedRevision->signed_on === $today,
        'M10 signature stores real private evidence against the exact current approved revision');
    $actions->activate((int) $reviewer->id, $contractId, (int) $header->version);
    $header = DB::table('service_contracts')->find($contractId);
    $pass($header->status === 'active', 'M10 activation requires the approved and evidenced signed contract');

    $coverage = app(ServiceContractsApi::class)->coverage((int) $writer->id, $contractId, (int) $customer->id,
        (int) $branch->id, ' CB-001 ', null, $today, 'preventive');
    $pass(($coverage['contract_id'] ?? null) === $contractId && ($coverage['revision_id'] ?? null) === (int) $header->active_revision_id
        && ($coverage['equipment_ref'] ?? null) === 'CB-001' && ($coverage['kind'] ?? null) === 'preventive'
        && ($coverage['remaining_visits'] ?? null) === 3 && ($coverage['remaining_labor_minutes'] ?? null) === 600
        && ($coverage['remaining_parts'][0]['product_id'] ?? null) === $partProduct->id
        && bccomp((string) ($coverage['remaining_parts'][0]['quantity'] ?? '0'), '2.5', 8) === 0
        && in_array($otherPart->id, $coverage['excluded_parts'] ?? [], true)
        && ($coverage['sla_response_minutes'] ?? null) === 240 && ($coverage['sla_resolution_minutes'] ?? null) === 1440,
        'M10 coverage returns normalized equipment, current revision, remaining visit/labor/part limits, exclusions and SLA');
    $reject(fn () => app(ServiceContractsApi::class)->coverage((int) $outsider->id, $contractId, (int) $customer->id,
        (int) $branch->id, 'CB-001', null, $today, 'preventive'), 'M10 coverage lookup requires explicit branch authority');
    $reject(fn () => app(ServiceContractsApi::class)->coverage((int) $writer->id, $contractId, (int) $inactiveCustomer->id,
        (int) $branch->id, 'CB-001', null, $today, 'preventive'), 'M10 coverage lookup rejects a customer mismatch');
    $reject(fn () => app(ServiceContractsApi::class)->coverage((int) $writer->id, $contractId, (int) $customer->id,
        (int) $otherBranch->id, 'CB-001', null, $today, 'preventive'), 'M10 coverage lookup rejects a branch mismatch');
    $reject(fn () => app(ServiceContractsApi::class)->coverage((int) $writer->id, $contractId, (int) $customer->id,
        (int) $branch->id, 'UNKNOWN-EQUIPMENT', null, $today, 'preventive'),
        'M10 coverage lookup rejects equipment outside the signed coverage snapshot');
    $reject(fn () => app(ServiceContractsApi::class)->coverage((int) $writer->id, $contractId, (int) $customer->id,
        (int) $branch->id, 'CB-001', null, now('Africa/Cairo')->addYear()->toDateString(), 'corrective'),
        'M10 coverage lookup rejects service outside contract dates');
    $reject(fn () => app(ServiceContractsApi::class)->coverage((int) $writer->id, $contractId, (int) $customer->id,
        (int) $branch->id, 'CB-001', null, $today, 'emergency'), 'M10 coverage lookup rejects unsupported service entitlement kinds');

    $actions->state((int) $reviewer->id, $contractId, (int) $header->version, 'suspended', 'إيقاف تجريبي مضبوط');
    $header = DB::table('service_contracts')->find($contractId);
    $reject(fn () => app(ServiceContractsApi::class)->coverage((int) $writer->id, $contractId, (int) $customer->id,
        (int) $branch->id, 'CB-001', null, $today, 'preventive'), 'M10 suspended contracts provide no active entitlement');
    $actions->state((int) $reviewer->id, $contractId, (int) $header->version, 'active', 'إعادة التفعيل بعد المراجعة');
    $header = DB::table('service_contracts')->find($contractId);
    $amendedData = array_replace($data, ['title' => 'عقد صيانة معدل — مراجعة جديدة']);
    $amendmentId = $actions->amend((int) $writer->id, $contractId, (int) $header->version, $uuid(), $amendedData);
    $amended = DB::table('service_contract_revisions')->find($amendmentId);
    $afterAmendment = DB::table('service_contracts')->find($contractId);
    $pass($amended !== null && (int) $amended->contract_id === $contractId && $amended->status === 'draft'
        && (int) $afterAmendment->current_revision_id === $amendmentId && $afterAmendment->status === 'active',
        'M10 amendment creates a linked draft revision without rewriting or deactivating the signed current contract');
    $renewalStart = now('Africa/Cairo')->addMonths(6)->addDay();
    $renewalEnd = now('Africa/Cairo')->addMonths(12);
    $renewalData = array_replace($data, ['starts_on' => $renewalStart->toDateString(), 'ends_on' => $renewalEnd->toDateString(),
        'title' => 'تجديد عقد الصيانة', 'schedule' => [
            ['code' => 'RENEW-01', 'due_date' => $renewalStart->copy()->addDays(10)->toDateString(), 'amount' => '500.00', 'description' => 'قسط التجديد الأول'],
            ['code' => 'RENEW-02', 'due_date' => $renewalStart->copy()->addMonths(2)->toDateString(), 'amount' => '500.00', 'description' => 'قسط التجديد الثاني'],
            ['code' => 'RENEW-03', 'due_date' => $renewalStart->copy()->addMonths(4)->toDateString(), 'amount' => '500.00', 'description' => 'قسط التجديد الثالث'],
        ]]);
    $renewalId = $actions->renew((int) $writer->id, $contractId, (int) $afterAmendment->version, $uuid(), $renewalData);
    $renewal = DB::table('service_contracts')->find($renewalId);
    $pass($renewal !== null && $renewalId !== $contractId && $renewal->status === 'draft'
        && (int) ($renewal->renewed_from_id ?? 0) === $contractId,
        'M10 renewal creates a separate linked draft while preserving the source contract and its history');
    $beforeTermination = DB::table('service_contracts')->find($contractId);
    $actions->state((int) $reviewer->id, $contractId, (int) $beforeTermination->version, 'terminated', 'إنهاء بعد إنشاء مسودة التجديد');
    $terminated = DB::table('service_contracts')->find($contractId);
    $pass($terminated->status === 'terminated' && (int) $terminated->active_revision_id === (int) $header->active_revision_id
        && DB::table('service_contract_revisions')->where('id', $signedRevision->id)->where('signed_by', $writer->id)
            ->where('signature_document_id', $signatureDocument)->exists()
        && DB::table('service_contracts')->where('id', $renewalId)->where('status', 'draft')->exists(),
        'M10 termination preserves signed source history and the separately linked renewal draft');

}
