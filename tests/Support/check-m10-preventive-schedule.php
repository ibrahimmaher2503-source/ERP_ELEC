<?php

declare(strict_types=1);

// Gate 2 M10 preventive dispatch behavior on the explicitly owned PostgreSQL target.
use App\Models\User;
use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\FieldMaintenance\Application\MaintenanceService;
use App\Modules\Organization\Application\EmployeeActions;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use App\Modules\ServiceContracts\Application\ServiceContractPreventive;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 2);
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate2';
$writeReceipt = static function (array $receipt) use ($evidenceDirectory): string {
    if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
        throw new RuntimeException('Unable to create Gate2 evidence directory.');
    }
    $base = $evidenceDirectory.'/m10-preventive-schedule-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json';
    $path = $base;
    $contents = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    while (($handle = @fopen($path, 'x')) === false) {
        if (! file_exists($path)) {
            throw new RuntimeException('Could not create preventive-schedule evidence receipt.');
        }
        $path = $evidenceDirectory.'/m10-preventive-schedule-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(8)).'.json';
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new RuntimeException('Could not write complete preventive-schedule evidence receipt.');
        }
    } finally {
        fclose($handle);
    }

    return $path;
};
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';

$requiredFiles = [
    'M10 preventive dispatch action' => $root.'/app/Modules/ServiceContracts/Application/ServiceContractPreventive.php',
    'M10 terms validator' => $root.'/app/Modules/ServiceContracts/Application/ServiceContractTerms.php',
    'M10 contract actions' => $root.'/app/Modules/ServiceContracts/Application/ServiceContractActions.php',
    'M10 document owner' => $root.'/app/Modules/ServiceContracts/Application/ServiceContractDocuments.php',
    'M10 public contract' => $root.'/app/Modules/ServiceContracts/Application/Contracts/ServiceContractsApi.php',
    'M08 public contract' => $root.'/app/Modules/FieldMaintenance/Application/Contracts/FieldMaintenanceApi.php',
    'M08 maintenance service' => $root.'/app/Modules/FieldMaintenance/Application/MaintenanceService.php',
    'M01 employee actions' => $root.'/app/Modules/Organization/Application/EmployeeActions.php',
];
$requiredClasses = [
    'M10 preventive dispatch' => ServiceContractPreventive::class,
    'M10 contract actions' => ServiceContractActions::class,
    'M10 documents' => ServiceContractDocuments::class,
    'M10 API' => ServiceContractsApi::class,
    'M08 API' => FieldMaintenanceApi::class,
    'M08 maintenance service' => MaintenanceService::class,
    'M01 employee actions' => EmployeeActions::class,
];
$missingFiles = array_keys(array_filter($requiredFiles, static fn (string $path): bool => ! is_file($path)));
$missingClasses = array_keys(array_filter($requiredClasses, static fn (string $class): bool => ! class_exists($class) && ! interface_exists($class)));
$missingMethods = [];
foreach ([ServiceContractPreventive::class => ['dispatch'], FieldMaintenanceApi::class => ['createContractPreventiveDraft']] as $class => $methods) {
    if (class_exists($class) || interface_exists($class)) {
        foreach ($methods as $method) {
            if (! method_exists($class, $method)) {
                $missingMethods[] = $class.'::'.$method;
            }
        }
    }
}
$missingPermissions = [];
if (class_exists(PermissionCatalog::class)) {
    foreach (['M10.contract_create', 'M10.contract_edit', 'M10.contract_approve', 'M10.contract_sign', 'M10.contract_activate',
        'M10.coverage_use', 'M10.document_manage', 'M08.view', 'M08.workorder_manage', 'M08.standalone_create'] as $permission) {
        if ((PermissionCatalog::DEFINITIONS[$permission][1] ?? null) !== 'branch') {
            $missingPermissions[] = $permission;
        }
    }
} else {
    $missingPermissions[] = 'PermissionCatalog';
}
if ($missingFiles !== [] || $missingClasses !== [] || $missingMethods !== [] || $missingPermissions !== []) {
    $receipt = ['all_pass' => false, 'phase' => 'Gate2 M10 preventive source preflight', 'missing_files' => $missingFiles,
        'missing_classes' => $missingClasses, 'missing_methods' => $missingMethods, 'missing_permissions' => $missingPermissions];
    $path = $writeReceipt($receipt);
    fwrite(STDERR, 'RESULT gate=2 scope=m10-preventive result=RED receipt='.$path.PHP_EOL);
    fwrite(STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}

$environment = ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = OptimizationRegressionDatabaseGuard::target($root, $environment);
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$privateRoot = $taskRoot.'/m10-preventive-private-'.bin2hex(random_bytes(8));
$cacheRoot = $taskRoot.'/m10-preventive-cache';
$cacheRelative = '.tmp/master-remediation-20261101/m10-preventive-cache';
$viewsRoot = $taskRoot.'/m10-preventive-views';
$storageRoot = $taskRoot.'/m10-preventive-app-storage';
if (! is_dir($privateRoot) && ! mkdir($privateRoot, 0770, true) && ! is_dir($privateRoot)) {
    throw new RuntimeException('Could not create private preventive-schedule fixture storage.');
}
$privateStorageClean = false;
try {
    foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'], 'DB_PORT' => (string) $target['port'],
        'DB_DATABASE' => $target['database'], 'DB_USERNAME' => $target['username'], 'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''),
        'DB_URL' => '', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'LARAVEL_STORAGE_PATH' => $storageRoot, 'APP_CONFIG_CACHE' => $cacheRelative.'/config.php',
        'APP_ROUTES_CACHE' => $cacheRelative.'/routes.php', 'APP_EVENTS_CACHE' => $cacheRelative.'/events.php',
        'APP_SERVICES_CACHE' => $cacheRelative.'/services.php', 'APP_PACKAGES_CACHE' => $cacheRelative.'/packages.php',
        'APP_VIEW_COMPILED_PATH' => $viewsRoot, 'VIEW_COMPILED_PATH' => $viewsRoot,
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    foreach ([$cacheRoot, $viewsRoot, $storageRoot] as $directory) {
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create private M10 runtime directory.');
        }
    }
    $app = require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->useStoragePath($storageRoot);
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
    $ciReceipt = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
    OptimizationRegressionDatabaseGuard::verify($target, $identity, $ciReceipt);
    if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
        throw new RuntimeException('Preventive acceptance requires the explicitly owned PostgreSQL 17 test target.');
    }
    $tables = ['companies', 'branches', 'users', 'customers', 'products', 'organization_employees', 'service_contracts',
        'service_contract_revisions', 'service_contract_documents', 'service_contract_activities', 'service_contract_work',
        'service_contract_preventive_dispatches', 'maintenance_work_orders', 'maintenance_activities', 'maintenance_assignments', 'maintenance_visits', 'access_grants',
        'user_branch_scopes', 'user_roles', 'audit_entries'];
    foreach ($tables as $table) {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            throw new RuntimeException("Preventive acceptance requires migrated {$table}.");
        }
    }
} catch (Throwable $error) {
    (new Filesystem)->deleteDirectory($privateRoot);
    $receipt = ['all_pass' => false, 'phase' => 'Gate2 guarded preventive bootstrap/schema preflight',
        'failure' => ['class' => $error::class, 'message' => $error->getMessage()]];
    $path = $writeReceipt($receipt);
    fwrite(STDERR, 'RESULT gate=2 scope=m10-preventive result=BLOCKED_OR_FAIL receipt='.$path.PHP_EOL);
    fwrite(STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}

$countTables = static function () use ($tables): array {
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) DB::table($table)->count();
    }

    return $counts;
};
$checks = [];
$assertions = 0;
$check = static function (bool $condition, string $message) use (&$checks, &$assertions): void {
    $checks[] = ['name' => $message, 'result' => $condition ? 'PASS' : 'FAIL'];
    if (! $condition) {
        throw new RuntimeException('FAIL '.$message);
    }
    $assertions++;
    fwrite(STDOUT, 'PASS '.$message.PHP_EOL);
};
$reject = static function (callable $operation, string $message) use ($check): void {
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof ValidationException || ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404, 409, 422], true))) {
            $check(true, $message);

            return;
        }
        throw $error;
    }
    $check(false, $message.' unexpectedly succeeded.');
};
$grant = static function (User $actor, array $permissions, ?int $branchId): void {
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id,
            'permission_code' => $permission, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$scope = static function (User $actor, Branch $branch): void {
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
};
$uuid = static fn (): string => (string) Str::uuid();
$before = $countTables();
$during = $before;
$after = [];
$failure = null;
$stage = 'fixture setup';
$today = now('Africa/Cairo')->toDateString();
DB::beginTransaction();
try {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create();
    }
    $branch = Branch::factory()->create(['company_id' => 1, 'active' => true]);
    $otherBranch = Branch::factory()->create(['company_id' => 1, 'active' => true]);
    $dispatcher = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $contractOnly = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$dispatcher, $reviewer, $contractOnly] as $actor) {
        $scope($actor, $branch);
    }
    $scope($outsider, $otherBranch);
    $contractPermissions = ['M10.contract_view', 'M10.contract_create', 'M10.contract_edit', 'M10.contract_approve',
        'M10.contract_sign', 'M10.contract_activate', 'M10.coverage_use', 'M10.document_manage', 'M10.document_view'];
    $grant($dispatcher, array_merge($contractPermissions, ['M01.employee_manage', 'M01.employee_view', 'M08.view', 'M08.workorder_manage', 'M08.standalone_create']), (int) $branch->id);
    $grant($dispatcher, ['M03.customer_view', 'M02.product_view'], null);
    $grant($reviewer, ['M10.contract_view', 'M10.contract_approve', 'M10.contract_activate', 'M10.document_view', 'M01.employee_view'], (int) $branch->id);
    $grant($reviewer, ['M03.customer_view', 'M02.product_view'], null);
    $grant($contractOnly, ['M10.contract_view', 'M10.contract_edit', 'M10.coverage_use'], (int) $branch->id);
    $grant($contractOnly, ['M03.customer_view', 'M02.product_view'], null);
    $grant($outsider, ['M10.contract_view', 'M10.contract_edit', 'M10.coverage_use', 'M08.workorder_manage', 'M08.standalone_create'], (int) $otherBranch->id);

    $employeeId = app(EmployeeActions::class)->save((int) $dispatcher->id, null, 0, [
        'code' => 'M10-PREV-'.Str::upper(Str::random(8)), 'name' => 'فني وقائي للاختبار', 'branch_id' => $branch->id,
        'user_id' => null, 'starts_on' => $today, 'ends_on' => null, 'active' => true, 'job_title' => 'فني صيانة',
    ]);
    $customer = Customer::factory()->create(['status' => 'active']);
    $serviceProduct = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $dataFor = static function (string $endsOn, string $title) use ($branch, $customer, $employeeId, $serviceProduct, $today): array {
        $firstDue = now('Africa/Cairo')->addDay()->toDateString();
        $secondDue = now('Africa/Cairo')->addDays(2)->toDateString();

        return ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_employee_id' => $employeeId,
            'title' => $title, 'starts_on' => $today, 'ends_on' => $endsOn, 'value' => '100.00',
            'service_product_id' => $serviceProduct->id, 'billing_mode' => 'installments', 'terms' => 'خطة صيانة وقائية محددة للاختبار.',
            'sla_response_minutes' => 60, 'sla_resolution_minutes' => 360, 'covered_labor_minutes' => 240,
            'coverage' => [['equipment_ref' => 'PREV-PANEL-01', 'site_id' => null, 'preventive_visits' => 2, 'corrective_visits' => 1]],
            'parts' => [], 'excluded_parts' => [], 'boq' => [],
            'schedule' => [['code' => 'INST-01', 'due_date' => $firstDue, 'amount' => '100.00', 'description' => 'دفعة عقد وقائي']],
            'preventive_schedule' => [
                ['code' => 'PV-01', 'equipment_ref' => ' prev-panel-01 ', 'site_id' => null, 'due_date' => $firstDue, 'description' => 'فحص اللوحة الأولى'],
                ['code' => 'PV-02', 'equipment_ref' => 'PREV-PANEL-01', 'site_id' => null, 'due_date' => $secondDue, 'description' => 'فحص اللوحة الثانية'],
            ]];
    };
    $actions = app(ServiceContractActions::class);
    $docs = app(ServiceContractDocuments::class);
    $activate = static function (array $data, ?int $existingId = null) use ($actions, $docs, $dispatcher, $reviewer, $uuid, $today): array {
        $id = $existingId ?? $actions->save((int) $dispatcher->id, null, 0, $uuid(), $data);
        $header = DB::table('service_contracts')->find($id);
        $actions->submit((int) $dispatcher->id, $id, (int) $header->version);
        $header = DB::table('service_contracts')->find($id);
        $actions->approve((int) $reviewer->id, $id, (int) $header->version);
        $header = DB::table('service_contracts')->find($id);
        $signature = $docs->store((int) $dispatcher->id, $id,
            UploadedFile::fake()->createWithContent('preventive-signature.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF"),
            'signature', 'توقيع حقيقي لاختبار الخطة');
        $actions->sign((int) $dispatcher->id, $id, (int) $header->version, $signature, $today);
        $header = DB::table('service_contracts')->find($id);
        $actions->activate((int) $dispatcher->id, $id, (int) $header->version);

        return [$id, DB::table('service_contracts')->find($id)];
    };

    $valid = $dataFor(now('Africa/Cairo')->addDays(30)->toDateString(), 'عقد خطة الوقاية');
    $stage = 'optional preventive terms persistence and validation';
    $coreTerms = $valid;
    unset($coreTerms['preventive_schedule']);
    $coreKey = $uuid();
    $coreContractId = $actions->save((int) $dispatcher->id, null, 0, $coreKey, $coreTerms);
    $coreHeader = DB::table('service_contracts')->find($coreContractId);
    $coreSnapshot = json_decode(DB::table('service_contract_revisions')->where('id', $coreHeader->current_revision_id)->value('data'), true, 512, JSON_THROW_ON_ERROR);
    $check(! array_key_exists('preventive_schedule', $coreSnapshot)
        && $actions->save((int) $dispatcher->id, null, 0, $coreKey, $coreTerms) === $coreContractId,
        'Omitting optional preventive terms preserves the existing contract snapshot and idempotent core replay');
    $contractId = $actions->save((int) $dispatcher->id, null, 0, $uuid(), $valid);
    $header = DB::table('service_contracts')->find($contractId);
    $revision = DB::table('service_contract_revisions')->find($header->current_revision_id);
    $snapshot = json_decode($revision->data, true, 512, JSON_THROW_ON_ERROR);
    $scheduleFacts = static fn (array $entries): array => array_map(static fn (array $entry): array => [
        'code' => $entry['code'] ?? null,
        'equipment_ref' => mb_strtoupper(trim((string) ($entry['equipment_ref'] ?? ''))),
        'site_id' => empty($entry['site_id']) ? null : (int) $entry['site_id'],
        'due_date' => $entry['due_date'] ?? null,
        'description' => $entry['description'] ?? null,
    ], $entries);
    $check($scheduleFacts($snapshot['preventive_schedule'] ?? []) === $scheduleFacts($valid['preventive_schedule']),
        'Signed-term draft persists every optional schedule code, normalized equipment/site, exact date, and description');

    $beforeInvalid = ['contracts' => DB::table('service_contracts')->count(), 'revisions' => DB::table('service_contract_revisions')->count()];
    $badDate = $valid;
    $badDate['preventive_schedule'][0]['due_date'] = now('Africa/Cairo')->addDays(31)->toDateString();
    $reject(fn () => $actions->save((int) $dispatcher->id, null, 0, $uuid(), $badDate), 'Preventive schedule rejects dates outside signed contract dates');
    $badEquipment = $valid;
    $badEquipment['preventive_schedule'][0]['equipment_ref'] = 'UNSIGNED-EQUIPMENT';
    $reject(fn () => $actions->save((int) $dispatcher->id, null, 0, $uuid(), $badEquipment), 'Preventive schedule rejects equipment absent from signed coverage');
    $badCap = $valid;
    $badCap['preventive_schedule'][] = ['code' => 'PV-03', 'equipment_ref' => 'PREV-PANEL-01', 'site_id' => null,
        'due_date' => now('Africa/Cairo')->addDays(3)->toDateString(), 'description' => 'زيارة تتجاوز السقف'];
    $reject(fn () => $actions->save((int) $dispatcher->id, null, 0, $uuid(), $badCap), 'Preventive schedule rejects entries exceeding the equipment visit cap');
    $check($beforeInvalid === ['contracts' => DB::table('service_contracts')->count(), 'revisions' => DB::table('service_contract_revisions')->count()],
        'Rejected preventive terms create no partial contract or revision');

    [$contractId, $header] = $activate($valid, $contractId);
    $stage = 'signed contract M08 dispatch, replay and reservation';
    $activeRevisionId = (int) $header->active_revision_id;
    $preventive = app(ServiceContractPreventive::class);
    $dispatchKey = $uuid();
    $workId = $preventive->dispatch((int) $dispatcher->id, $contractId, 'PV-01', (int) $dispatcher->id, $dispatchKey);
    $work = DB::table('maintenance_work_orders')->find($workId);
    $dispatch = DB::table('service_contract_preventive_dispatches')->where('contract_id', $contractId)
        ->where('revision_id', $activeRevisionId)->where('schedule_code', 'PV-01')->first();
    $usage = DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $workId)->first();
    $check($work !== null && $work->status === 'draft' && ! $work->billable && (int) $work->contract_id === $contractId
        && $work->contract_equipment_ref === 'PREV-PANEL-01' && $work->contract_service_date === $valid['preventive_schedule'][0]['due_date']
        && $work->contract_service_kind === 'preventive' && (int) $work->responsible_id === (int) $dispatcher->id
        && $dispatch !== null && (int) $dispatch->work_order_id === $workId && (int) $dispatch->responsible_id === (int) $dispatcher->id
        && $dispatch->creation_key === $dispatchKey && $usage !== null && $usage->status === 'reserved'
        && (int) $usage->revision_id === $activeRevisionId && $usage->kind === 'preventive'
        && ! DB::table('maintenance_assignments')->where('work_order_id', $workId)->exists()
        && ! DB::table('maintenance_visits')->where('work_order_id', $workId)->exists(),
        'Dispatch creates a nonbillable M08 draft and reserves signed coverage without assignment or execution');
    $beforeReplay = ['orders' => DB::table('maintenance_work_orders')->count(), 'maps' => DB::table('service_contract_preventive_dispatches')->count(),
        'reservations' => DB::table('service_contract_work')->count()];
    $check($preventive->dispatch((int) $dispatcher->id, $contractId, 'PV-01', (int) $dispatcher->id, $dispatchKey) === $workId
        && $beforeReplay === ['orders' => DB::table('maintenance_work_orders')->count(), 'maps' => DB::table('service_contract_preventive_dispatches')->count(),
            'reservations' => DB::table('service_contract_work')->count()],
        'Exact preventive dispatch retry returns the original work order without another reservation');
    $check($preventive->dispatch((int) $dispatcher->id, $contractId, 'PV-01', (int) $dispatcher->id, $uuid()) === $workId
        && $beforeReplay === ['orders' => DB::table('maintenance_work_orders')->count(), 'maps' => DB::table('service_contract_preventive_dispatches')->count(),
            'reservations' => DB::table('service_contract_work')->count()],
        'Contract-revision-code dispatch map prevents a second work order even with a new request key');
    $deniedBefore = ['orders' => DB::table('maintenance_work_orders')->count(), 'maps' => DB::table('service_contract_preventive_dispatches')->count(),
        'reservations' => DB::table('service_contract_work')->count()];
    $reject(fn () => $preventive->dispatch((int) $contractOnly->id, $contractId, 'PV-02', (int) $contractOnly->id, $uuid()),
        'Preventive dispatch requires M08 standalone-create and work-order authority');
    $reject(fn () => $preventive->dispatch((int) $outsider->id, $contractId, 'PV-02', (int) $outsider->id, $uuid()),
        'Preventive dispatch denies an actor outside contract branch scope');
    $reject(fn () => $preventive->dispatch((int) $dispatcher->id, $contractId, 'PV-UNKNOWN', (int) $dispatcher->id, $uuid()),
        'Preventive dispatch rejects a code absent from signed schedule');
    $check($deniedBefore === ['orders' => DB::table('maintenance_work_orders')->count(), 'maps' => DB::table('service_contract_preventive_dispatches')->count(),
        'reservations' => DB::table('service_contract_work')->count()],
        'Denied dispatches leave M08 work orders, dispatch map and entitlement reservations unchanged');
    $secondWorkId = $preventive->dispatch((int) $dispatcher->id, $contractId, 'PV-02', (int) $dispatcher->id, $uuid());
    $check(DB::table('maintenance_work_orders')->where('id', $secondWorkId)->where('status', 'draft')->where('billable', false)->exists()
        && DB::table('service_contract_work')->where('contract_id', $contractId)->where('revision_id', $activeRevisionId)
            ->where('kind', 'preventive')->where('status', 'reserved')->count() === 2,
        'Second signed entry dispatch creates a separate draft and consumes the second reserved visit');
    $reject(fn () => app(ServiceContractsApi::class)->coverage((int) $dispatcher->id, $contractId, (int) $header->customer_id,
        (int) $header->branch_id, 'PREV-PANEL-01', null, $today, 'preventive'),
        'Coverage cannot reserve more visits after every signed preventive entry reaches the visit cap');

    $expiredTerms = $dataFor(now('Africa/Cairo')->addDays(3)->toDateString(), 'خطة وقاية تنتهي قريباً');
    $stage = 'expired active coverage dispatch rollback';
    $expiredTerms['coverage'][0]['preventive_visits'] = 2;
    $expiredTerms['preventive_schedule'] = [['code' => 'EXP-01', 'equipment_ref' => 'PREV-PANEL-01', 'site_id' => null,
        'due_date' => now('Africa/Cairo')->addDays(2)->toDateString(), 'description' => 'موعد وقائي قبل انتهاء العقد']];
    [$expiredId, $expiredHeader] = $activate($expiredTerms);
    $beforeExpired = ['orders' => DB::table('maintenance_work_orders')->count(), 'maps' => DB::table('service_contract_preventive_dispatches')->count(),
        'reservations' => DB::table('service_contract_work')->count()];
    Carbon::setTestNow(now('Africa/Cairo')->addDays(4));
    try {
        $reject(fn () => $preventive->dispatch((int) $dispatcher->id, $expiredId, 'EXP-01', (int) $dispatcher->id, $uuid()),
            'Expired active coverage rejects preventive dispatch');
    } finally {
        Carbon::setTestNow();
    }
    $check($beforeExpired === ['orders' => DB::table('maintenance_work_orders')->count(), 'maps' => DB::table('service_contract_preventive_dispatches')->count(),
        'reservations' => DB::table('service_contract_work')->count()],
        'Expired-coverage rejection rolls back work-order, dispatch-map and reservation writes');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $during = $countTables();
    DB::rollBack();
    $after = $countTables();
    Carbon::setTestNow();
    (new Filesystem)->deleteDirectory($privateRoot);
    $privateStorageClean = ! is_dir($privateRoot);
}
$rollbackClean = $before === $after && $privateStorageClean;
$checks[] = ['name' => 'All preventive fixture rows and private signature files roll back.', 'result' => $rollbackClean ? 'PASS' : 'FAIL'];
$assertions++;
if (! $rollbackClean && $failure === null) {
    $failure = new RuntimeException('Preventive acceptance left fixture data or private files after rollback.');
}
$receipt = ['all_pass' => $failure === null, 'phase' => 'Gate2 M10 preventive-schedule guarded acceptance', 'assertions' => $assertions,
    'stage' => $stage,
    'database' => ['name' => $identity->database, 'server_address' => $identity->server_address, 'port' => (int) $identity->port,
        'postgres_major' => intdiv((int) $identity->server_version_num, 10000)],
    'counts' => ['before' => $before, 'during_fixture' => $during, 'after_rollback' => $after], 'private_storage_clean' => $privateStorageClean,
    'checks' => $checks, 'failure' => $failure === null ? null : ['class' => $failure::class, 'message' => mb_substr($failure->getMessage(), 0, 500)]];
$path = $writeReceipt($receipt);
fwrite(($failure === null ? STDOUT : STDERR), 'RESULT gate=2 scope=m10-preventive result='.($failure === null ? 'PASS' : 'FAIL')
    .' stage='.$stage.' assertions='.$assertions.' receipt='.$path.PHP_EOL);
fwrite(($failure === null ? STDOUT : STDERR), json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
if ($failure !== null) {
    exit(1);
}
