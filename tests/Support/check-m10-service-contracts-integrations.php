<?php

declare(strict_types=1);

// Guarded source-driven M08/M09 to M10 entitlement acceptance. All fixture writes roll back.
use App\Models\User;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use App\Modules\WorkshopRepairs\Application\RepairOffers;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use App\Modules\WorkshopRepairs\Application\WorkshopExecution;
use App\Modules\WorkshopRepairs\Application\Contracts\WorkshopApi;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$root = dirname(__DIR__, 2);
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate2';
$stage = 'source preflight';
$receiptPath = null;
$emit = static function (array $receipt) use ($evidenceDirectory): string {
    if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
        throw new RuntimeException('Could not create Gate2 evidence directory.');
    }
    $path = $evidenceDirectory.'/m10-integrations-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);

    return $path;
};
set_exception_handler(static function (Throwable $error) use (&$stage, $emit): void {
    $safe = preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $error->getMessage());
    $receipt = ['all_pass' => false, 'mode' => 'm10-source-integrations', 'phase' => $stage, 'assertions' => 0,
        'rollback_verified' => false, 'checks' => [], 'failure' => ['class' => $error::class, 'message' => mb_substr((string) $safe, 0, 500)]];
    try {
        $path = $emit($receipt);
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
$required = [
    $root.'/app/Modules/ServiceContracts/Application/ServiceContractEntitlements.php',
    $root.'/app/Modules/FieldMaintenance/Application/MaintenanceService.php',
    $root.'/app/Modules/WorkshopRepairs/Application/WorkshopService.php',
    $root.'/database/migrations/2026_11_01_203000_link_service_contract_work.php',
    $root.'/database/migrations/2026_11_01_205000_track_contract_work_usage.php',
    $root.'/database/migrations/2026_11_01_207000_separate_contract_source_identity_guards.php',
];
foreach ($required as $path) {
    if (! is_file($path)) {
        throw new RuntimeException('Required M10 source integration file is missing: '.basename($path));
    }
}

$environment = ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $environment);
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$privateRoot = $taskRoot.'/m10-integration-private-'.bin2hex(random_bytes(8));
$cacheRelative = '.tmp/master-remediation-20261101/m10-integration-cache';
$viewsRelative = '.tmp/master-remediation-20261101/m10-integration-views';
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'], 'DB_PORT' => (string) $target['port'],
    'DB_DATABASE' => $target['database'], 'DB_USERNAME' => $target['username'], 'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''),
    'DB_URL' => '', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'LARAVEL_STORAGE_PATH' => $taskRoot.'/m10-integration-storage', 'APP_CONFIG_CACHE' => $cacheRelative.'/config.php',
    'APP_ROUTES_CACHE' => $cacheRelative.'/routes.php', 'APP_EVENTS_CACHE' => $cacheRelative.'/events.php',
    'APP_SERVICES_CACHE' => $cacheRelative.'/services.php', 'APP_PACKAGES_CACHE' => $cacheRelative.'/packages.php',
    'APP_VIEW_COMPILED_PATH' => $viewsRelative, 'VIEW_COMPILED_PATH' => $viewsRelative,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach ([$privateRoot, $taskRoot.'/m10-integration-cache', $taskRoot.'/m10-integration-storage', $taskRoot.'/m10-integration-views'] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0770, true) && ! is_dir($dir)) {
        throw new RuntimeException('Could not create private integration-check runtime directory.');
    }
}
$stage = 'Laravel bootstrap and exact PostgreSQL guard';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($taskRoot.'/m10-integration-storage');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $target['connection_host'],
    'database.connections.pgsql.port' => $target['port'], 'database.connections.pgsql.database' => $target['database'],
    'database.connections.pgsql.username' => $target['username'], 'database.connections.pgsql.password' => (string) (getenv('DB_PASSWORD') ?: ''),
    'database.connections.pgsql.url' => null, 'filesystems.disks.local.root' => $privateRoot,
    'filesystems.disks.local.visibility' => 'private', 'filesystems.disks.local.throw' => true,
    'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
register_shutdown_function(static function () use ($privateRoot, $taskRoot): void {
    if (is_dir($privateRoot) && realpath(dirname($privateRoot)) === realpath($taskRoot)
        && str_starts_with(basename($privateRoot), 'm10-integration-private-')) {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($privateRoot);
    }
});
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
if (DB::connection()->getDriverName() !== 'pgsql' || (int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000
    || DB::table('migrations')->whereIn('migration', ['2026_11_01_203000_link_service_contract_work', '2026_11_01_205000_track_contract_work_usage',
        '2026_11_01_207000_separate_contract_source_identity_guards'])->count() !== 3) {
    throw new RuntimeException('ABORT before writes: exact owned PostgreSQL17 target and M10 integration migrations203/205/207 are required.');
}

$checks = [];
$assertions = 0;
$pass = static function (bool $condition, string $name) use (&$assertions, &$checks): void {
    if (! $condition) {
        $checks[] = ['name' => $name, 'result' => 'FAIL'];
        throw new RuntimeException($name);
    }
    $assertions++;
    $checks[] = ['name' => $name, 'result' => 'PASS'];
    fwrite(STDOUT, 'PASS '.$name.PHP_EOL);
};
$reject = static function (callable $action, string $name, ?int $status = null) use ($pass): void {
    try {
        $action();
    } catch (Throwable $error) {
        if ($status !== null && (! $error instanceof HttpExceptionInterface || $error->getStatusCode() !== $status)) {
            throw $error;
        }
        if ($status === null && ! $error instanceof ValidationException && ! $error instanceof HttpExceptionInterface) {
            throw $error;
        }
        $pass(true, $name);

        return;
    }
    throw new RuntimeException($name.' was unexpectedly accepted.');
};
$counts = static function (): array {
    $tables = ['companies', 'branches', 'users', 'customers', 'products', 'organization_employees', 'service_contracts',
        'service_contract_revisions', 'service_contract_documents', 'service_contract_work', 'service_contract_work_parts',
        'maintenance_work_orders', 'maintenance_visits', 'maintenance_labor_entries', 'workshop_intakes', 'workshop_jobs',
        'workshop_work_records', 'workshop_labor_entries', 'access_grants', 'user_branch_scopes', 'audit_entries'];
    $result = [];
    foreach ($tables as $table) {
        if (DB::getSchemaBuilder()->hasTable($table)) {
            $result[$table] = (int) DB::table($table)->count();
        }
    }

    return $result;
};

$before = $counts();
$fixtureCounts = [];
$failure = null;
DB::beginTransaction();
try {
    $stage = 'M08/M09 source-linked contract fixture';
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create(['id' => 1, 'legal_name' => 'Synthetic M10 integration acceptance entity',
            'address' => 'Rollback-only synthetic acceptance address', 'setup_complete' => true]);
    }
    $tag = 'DEMO-EG-M10I-'.Str::upper(Str::random(6));
    $branch = Branch::factory()->create(['code' => $tag, 'company_id' => 1]);
    $outsideBranch = Branch::factory()->create(['code' => $tag.'X', 'company_id' => 1]);
    $customer = Customer::factory()->create(['code' => $tag, 'name' => 'عميل عقد تكامل قبول']);
    $serviceProduct = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $writer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $technician = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $overrunTechnician = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $workshopTechnician = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$writer, $technician, $overrunTechnician, $workshopTechnician, $reviewer] as $user) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant = static function (User $user, string $module, array $permissions, ?int $scope) use ($branch): void {
        foreach ($permissions as $permission) {
            DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $module.'.'.$permission,
                'branch_id' => $scope ?? $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    };
    $grant($writer, 'M10', ['contract_view', 'contract_create', 'contract_edit', 'contract_sign', 'contract_activate', 'coverage_use', 'document_manage'], $branch->id);
    $grant($reviewer, 'M10', ['contract_view', 'contract_approve', 'contract_activate', 'coverage_use', 'document_view'], $branch->id);
    foreach ([$technician, $overrunTechnician, $workshopTechnician] as $serviceActor) {
        $grant($serviceActor, 'M10', ['coverage_use'], $branch->id);
    }
    foreach ([$writer, $reviewer] as $catalogReader) {
        foreach (['M03.customer_view', 'M02.product_view'] as $permission) {
            DB::table('access_grants')->insert(['user_id' => $catalogReader->id, 'permission_code' => $permission, 'branch_id' => null,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $grant($writer, 'M01', ['employee_view'], $branch->id);
    $grant($writer, 'M08', ['view', 'standalone_create', 'workorder_manage', 'assign', 'checklist_manage', 'close', 'billing_request'], $branch->id);
    $grant($technician, 'M08', ['view', 'workorder_manage', 'visit_record', 'labor_record', 'assign'], $branch->id);
    $grant($overrunTechnician, 'M08', ['view', 'workorder_manage', 'visit_record', 'labor_record', 'assign'], $branch->id);
    $grant($reviewer, 'M08', ['view', 'verify', 'close'], $branch->id);
    $grant($writer, 'M09', ['view', 'manage', 'intake_manage', 'custody', 'job_manage', 'assign', 'estimate_approve', 'verify', 'deliver'], $branch->id);
    $grant($technician, 'M09', ['view', 'execute', 'assign', 'labor_record'], $branch->id);
    $grant($workshopTechnician, 'M09', ['view', 'execute', 'assign', 'labor_record'], $branch->id);
    $grant($reviewer, 'M09', ['view', 'verify', 'estimate_approve', 'deliver'], $branch->id);
    $grant($reviewer, 'M01', ['employee_view'], $branch->id);
    DB::table('access_grants')->insert(['user_id' => $outsider->id, 'permission_code' => 'M10.coverage_use', 'branch_id' => $outsideBranch->id, 'created_at' => now(), 'updated_at' => now()]);
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'active' => true, 'starts_on' => now('Africa/Cairo')->subDay()->toDateString()]);
    $employee->refresh();

    $today = now('Africa/Cairo')->toDateString();
    $starts = now('Africa/Cairo')->subDay()->toDateString();
    $ends = now('Africa/Cairo')->addMonths(3)->toDateString();
    $m08Equipment = 'M08-'.$tag;
    $m08ReleaseEquipment = 'M08-RELEASE-'.$tag;
    $m08OverrunEquipment = 'M08-OVERRUN-'.$tag;
    $m09Equipment = 'M09-'.$tag;
    $m09ReleaseEquipment = 'M09-RELEASE-'.$tag;
    $terms = ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_employee_id' => $employee->id,
        'title' => 'عقد خدمات قبول تكامل', 'starts_on' => $starts, 'ends_on' => $ends, 'value' => '100.00',
        'service_product_id' => $serviceProduct->id, 'billing_mode' => 'installments', 'terms' => 'شروط اختبار تكامل الملاك.',
        'sla_response_minutes' => 120, 'sla_resolution_minutes' => 720, 'covered_labor_minutes' => 120,
        'coverage' => [
            ['equipment_ref' => $m08Equipment, 'site_id' => null, 'preventive_visits' => 1, 'corrective_visits' => 0],
            ['equipment_ref' => $m08ReleaseEquipment, 'site_id' => null, 'preventive_visits' => 1, 'corrective_visits' => 0],
            ['equipment_ref' => $m08OverrunEquipment, 'site_id' => null, 'preventive_visits' => 1, 'corrective_visits' => 0],
            ['equipment_ref' => $m09Equipment, 'site_id' => null, 'preventive_visits' => 0, 'corrective_visits' => 1],
            ['equipment_ref' => $m09ReleaseEquipment, 'site_id' => null, 'preventive_visits' => 0, 'corrective_visits' => 1],
        ], 'parts' => [], 'excluded_parts' => [], 'boq' => [],
        'schedule' => [['code' => 'INT-01', 'due_date' => now('Africa/Cairo')->addDays(10)->toDateString(), 'amount' => '100.00', 'description' => 'دفعة اختبار']]];
    $contracts = app(ServiceContractActions::class);
    $contractId = $contracts->save((int) $writer->id, null, 0, (string) Str::uuid(), $terms);
    $signature = app(ServiceContractDocuments::class)->store((int) $writer->id, $contractId,
        UploadedFile::fake()->createWithContent('integration-signature.pdf', "%PDF-1.4\nM10 integration signature evidence\n%%EOF"), 'signature', 'اختبار توقيع تكامل');
    $contract = DB::table('service_contracts')->find($contractId);
    $contracts->submit((int) $writer->id, $contractId, (int) $contract->version);
    $contract = DB::table('service_contracts')->find($contractId);
    $contracts->approve((int) $reviewer->id, $contractId, (int) $contract->version);
    $contract = DB::table('service_contracts')->find($contractId);
    $contracts->sign((int) $writer->id, $contractId, (int) $contract->version, $signature, $today);
    $contract = DB::table('service_contracts')->find($contractId);
    $contracts->activate((int) $reviewer->id, $contractId, (int) $contract->version);
    $pass(DB::table('service_contracts')->where('id', $contractId)->where('status', 'active')->whereNotNull('active_revision_id')->exists(),
        'M08 and M09 source journeys use a real independently approved and signed active contract revision');

    $api = app(ServiceContractsApi::class);
    $maint = app(MaintenanceActions::class);
    $execution = app(MaintenanceExecution::class);
    $workId = $maint->createWorkOrder((int) $writer->id, null, 0, (string) Str::uuid(), [
        'branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $technician->id,
        'description' => 'فحص لوحة كهربائية ضمن عقد الاختبار.', 'priority' => 'normal', 'instructions' => 'تنفيذ فحص فعلي موثق.',
        'billable' => false, 'acknowledgment_required' => true]);
    $work = DB::table('maintenance_work_orders')->find($workId);
    $reject(fn () => $maint->linkContract((int) $outsider->id, $workId, (int) $work->version, $contractId, $m08Equipment, $today, 'preventive'),
        'M08 linking denies an actor without branch work authority', 403);
    $reject(fn () => $maint->linkContract((int) $writer->id, $workId, (int) $work->version, $contractId, 'WRONG-'.$m08Equipment, $today, 'preventive'),
        'M08 owner projection rejects an equipment reference outside actual contract coverage');
    $reject(fn () => $maint->linkContract((int) $writer->id, $workId, (int) $work->version, $contractId, $m08Equipment, now('Africa/Cairo')->addMonths(4)->toDateString(), 'preventive'),
        'M08 owner projection rejects service dates outside contract term');
    $m08WorkId = $maint->linkContract((int) $writer->id, $workId, (int) $work->version, $contractId, $m08Equipment, $today, 'preventive');
    $pass($m08WorkId > 0 && $maint->linkContract((int) $writer->id, $workId, (int) $work->version, $contractId, $m08Equipment, $today, 'preventive') === $m08WorkId
        && DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $workId)->where('status', 'reserved')->count() === 1,
        'M08 contract link reserves one entitlement from its own draft nonbillable work order and safely retries');

    $template = $execution->saveTemplate((int) $writer->id, null, 0, ['branch_id' => $branch->id, 'code' => $tag.'-CHK', 'title' => 'فحص العقد',
        'items' => [['key' => 'safety_check', 'label' => 'فحص السلامة', 'required' => true]], 'active' => true]);
    $base = CarbonImmutable::now('UTC')->startOfMinute();
    $assignment = $execution->assign((int) $writer->id, $workId, 2, ['technician_id' => $technician->id,
        'starts_at' => $base->subHours(3)->toIso8601String(), 'ends_at' => $base->addHours(2)->toIso8601String()]);
    $work = DB::table('maintenance_work_orders')->find($workId);
    $visit = $execution->schedule((int) $writer->id, $workId, (int) $work->version, (string) Str::uuid(), [
        'assignment_id' => $assignment, 'scheduled_at' => $base->subMinutes(130)->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
    $work = DB::table('maintenance_work_orders')->find($workId);
    $maint->state((int) $technician->id, $workId, (int) $work->version, 'start', 'بدء تنفيذ زيارة العقد.');
    $arrival = $base->subMinutes(120);
    $departure = $base->subMinutes(5);
    $visitRow = DB::table('maintenance_visits')->find($visit);
    $execution->recordVisit((int) $technician->id, $workId, $visit, (int) $visitRow->version, [
        'arrival_at' => $arrival->toIso8601String(), 'departure_at' => $departure->toIso8601String(),
        'work_performed' => 'تم فحص التوصيلات وقياس الجهد.', 'diagnosis' => 'لا توجد أعطال إضافية.',
        'observations' => 'القراءة ضمن المجال المتوقع.', 'acknowledgment' => 'استلم العميل تقرير الزيارة.', 'outcome' => 'completed'],
        ['safety_check' => ['result' => 'complete']]);
    $execution->labor((int) $technician->id, $workId, (string) Str::uuid(), ['visit_id' => $visit,
        'starts_at' => $arrival->toIso8601String(), 'ends_at' => $arrival->addMinutes(110)->toIso8601String(), 'work_type' => 'فحص تعاقدي']);
    $m08ReservedUsage = DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $workId)->first();
    $m08SourceVersion = DB::table('maintenance_work_orders')->find($workId)->version;
    $pass($m08ReservedUsage && $m08ReservedUsage->status === 'reserved' && (int) $m08ReservedUsage->visits === 1
        && (int) $m08ReservedUsage->labor_minutes === 110 && (int) $m08ReservedUsage->source_version === (int) $m08SourceVersion
        && json_decode($m08ReservedUsage->parts_snapshot, true, 512, JSON_THROW_ON_ERROR) === [],
        'M08 source actions immediately synchronize reserved visit/labor totals and exact parts snapshot');
    $work = DB::table('maintenance_work_orders')->find($workId);
    $maint->state((int) $technician->id, $workId, (int) $work->version, 'complete', 'اكتملت الزيارة وسجل الوقت الفعلي.');
    $m08Projection = app(FieldMaintenanceApi::class)->contractWork((int) $reviewer->id, $workId);
    $pass($m08Projection['contract_id'] === $contractId && $m08Projection['equipment_ref'] === $m08Equipment
        && $m08Projection['visits'] === 1 && $m08Projection['labor_minutes'] === 110 && $m08Projection['parts'] === []
        && $m08Projection['complete'] === true,
        'M08 contract-work projection is derived from its recorded visit and labor entries before consumption');
    $work = DB::table('maintenance_work_orders')->find($workId);
    $maint->state((int) $reviewer->id, $workId, (int) $work->version, 'verify', 'مراجعة مستقلة لتقرير الصيانة.');
    $usage = DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $workId)->first();
    $pass($usage && $usage->status === 'consumed' && (int) $usage->visits === 1 && (int) $usage->labor_minutes === 110
        && ! DB::table('service_contract_work_parts')->where('work_id', $usage->id)->exists(),
        'M08 verify consumes its actual one visit and 110 labor minutes exactly once with zero unissued part usage');
    $pass($api->consumeWork((int) $reviewer->id, 'FieldMaintenance', $workId) === (int) $usage->id
        && $maint->linkContract((int) $writer->id, $workId, 2, $contractId, $m08Equipment, $today, 'preventive') === (int) $usage->id,
        'M08 duplicate consume and duplicate link return the same consumed reservation without double counting');
    $cappedM08Work = $maint->createWorkOrder((int) $writer->id, null, 0, (string) Str::uuid(), [
        'branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $technician->id,
        'description' => 'مصدر ثانٍ بعد استهلاك الزيارة المغطاة.', 'priority' => 'low', 'billable' => false, 'acknowledgment_required' => false]);
    $cappedM08Row = DB::table('maintenance_work_orders')->find($cappedM08Work);
    $reject(fn () => $maint->linkContract((int) $writer->id, $cappedM08Work, (int) $cappedM08Row->version, $contractId, $m08Equipment, $today, 'preventive'),
        'M08 cannot reserve another visit after its source-derived visit cap is consumed');
    $pass(! DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $cappedM08Work)->exists(),
        'M08 exhausted visit handoff leaves no entitlement reservation');
    $reject(fn () => $api->consumeWork((int) $reviewer->id, 'FieldMaintenance', $workId + 999999),
        'M10 rejects a consumption request that has no real M08 source reservation');

    $overrunWork = $maint->createWorkOrder((int) $writer->id, null, 0, (string) Str::uuid(), [
        'branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $technician->id,
        'description' => 'عمل ميداني فعلي يتجاوز رصيد دقائق العقد.', 'priority' => 'normal', 'billable' => false, 'acknowledgment_required' => true]);
    $overrunRow = DB::table('maintenance_work_orders')->find($overrunWork);
    $maint->linkContract((int) $writer->id, $overrunWork, (int) $overrunRow->version, $contractId, $m08OverrunEquipment, $today, 'preventive');
    $overrunBase = CarbonImmutable::now('UTC')->startOfMinute();
    $overrunAssignment = $execution->assign((int) $writer->id, $overrunWork, 2, ['technician_id' => $overrunTechnician->id,
        'starts_at' => $overrunBase->subHours(3)->toIso8601String(), 'ends_at' => $overrunBase->addHours(1)->toIso8601String()]);
    $overrunRow = DB::table('maintenance_work_orders')->find($overrunWork);
    $overrunVisit = $execution->schedule((int) $writer->id, $overrunWork, (int) $overrunRow->version, (string) Str::uuid(), [
        'assignment_id' => $overrunAssignment, 'scheduled_at' => $overrunBase->subHours(2)->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
    $overrunRow = DB::table('maintenance_work_orders')->find($overrunWork);
    $maint->state((int) $overrunTechnician->id, $overrunWork, (int) $overrunRow->version, 'start', 'بدء عمل قياس تجاوز الرصيد.');
    $overrunArrival = $overrunBase->subMinutes(20);
    $overrunDeparture = $overrunBase->subMinutes(5);
    $overrunVisitRow = DB::table('maintenance_visits')->find($overrunVisit);
    $execution->recordVisit((int) $overrunTechnician->id, $overrunWork, $overrunVisit, (int) $overrunVisitRow->version, [
        'arrival_at' => $overrunArrival->toIso8601String(), 'departure_at' => $overrunDeparture->toIso8601String(),
        'work_performed' => 'تنفيذ اختبار مدة طويلة للتحقق من رفض الرصيد.', 'diagnosis' => 'تسجيل فعلي لاستهلاك وقت زائد.',
        'observations' => 'الوقت المسجل متصل وموثق.', 'acknowledgment' => 'استلم العميل التقرير.', 'outcome' => 'completed'],
        ['safety_check' => ['result' => 'complete']]);
    $overrunRowBeforeLabor = DB::table('maintenance_work_orders')->find($overrunWork);
    $overrunUsageBeforeLabor = DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $overrunWork)->first();
    $overrunAuditBeforeLabor = DB::table('maintenance_activities')->where('entity_type', 'maintenance_work_order')->where('entity_id', $overrunWork)->count();
    $reject(fn () => $execution->labor((int) $overrunTechnician->id, $overrunWork, (string) Str::uuid(), ['visit_id' => $overrunVisit,
        'starts_at' => $overrunArrival->toIso8601String(), 'ends_at' => $overrunArrival->addMinutes(15)->toIso8601String(), 'work_type' => 'وقت يتجاوز الرصيد']),
        'M10 blocks actual M08 labor above remaining contract minutes at the source action');
    $overrunLedger = DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $overrunWork)->first();
    $overrunRowAfterLabor = DB::table('maintenance_work_orders')->find($overrunWork);
    $pass($overrunLedger && $overrunLedger->status === 'reserved' && (int) $overrunLedger->visits === 1
        && (int) $overrunLedger->labor_minutes === 0 && $overrunLedger->source_version === $overrunUsageBeforeLabor->source_version
        && (int) $overrunRowAfterLabor->version === (int) $overrunRowBeforeLabor->version && $overrunRowAfterLabor->status === 'in_progress'
        && ! DB::table('maintenance_labor_entries')->where('work_order_id', $overrunWork)->exists()
        && DB::table('maintenance_activities')->where('entity_type', 'maintenance_work_order')->where('entity_id', $overrunWork)->count() === $overrunAuditBeforeLabor,
        'M08 failed labor action rolls back the labor row, work version, usage snapshot, and audit while retaining prior visit evidence');

    $releaseWorkId = $maint->createWorkOrder((int) $writer->id, null, 0, (string) Str::uuid(), [
        'branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $technician->id,
        'description' => 'مسودة عمل تعاقدي ألغيت قبل التنفيذ.', 'priority' => 'low', 'billable' => false, 'acknowledgment_required' => false]);
    $releaseWork = DB::table('maintenance_work_orders')->find($releaseWorkId);
    $releaseLedgerId = $maint->linkContract((int) $writer->id, $releaseWorkId, (int) $releaseWork->version, $contractId, $m08ReleaseEquipment, $today, 'preventive');
    $releaseWork = DB::table('maintenance_work_orders')->find($releaseWorkId);
    $maint->state((int) $writer->id, $releaseWorkId, (int) $releaseWork->version, 'cancel', 'إلغاء المسودة قبل بدء أي زيارة أو عمل.');
    $api->releaseWork((int) $writer->id, 'FieldMaintenance', $releaseWorkId);
    $pass(DB::table('service_contract_work')->where('id', $releaseLedgerId)->where('status', 'released')->whereNull('consumed_at')->exists()
        && $api->coverage((int) $writer->id, $contractId, (int) $customer->id, (int) $branch->id, $m08ReleaseEquipment, null, $today, 'preventive')['remaining_visits'] === 1,
        'M08 cancellation before execution releases its reserved visit and restores available coverage');

    $workshop = app(WorkshopActions::class);
    $workshopId = $workshop->saveWorkshop((int) $writer->id, null, 0, ['code' => $tag.'-WS', 'name' => 'ورشة تكامل العقد',
        'specialty' => 'إصلاح قواطع كهربائية', 'branch_id' => $branch->id, 'supervisor_id' => $writer->id, 'warehouse_id' => null,
        'capacity' => 2, 'schedule' => [['day' => 0, 'start' => '08:00', 'end' => '16:00']], 'active' => true]);
    $workshop->member((int) $writer->id, $workshopId, 1, (int) $workshopTechnician->id, true);
    $receivedIntake = static function (string $serial, string $description) use ($workshop, $writer, $branch, $customer, $tag): int {
        $intakeId = $workshop->saveIntake((int) $writer->id, null, 0, (string) Str::uuid(), ['branch_id' => $branch->id,
            'customer_id' => $customer->id, 'equipment_description' => $description, 'model' => 'M10-I-NEG', 'serial_number' => $serial,
            'accessories' => 'غطاء', 'reported_fault' => 'فصل تجريبي.', 'arrival_condition' => 'الجسم سليم ظاهرياً.',
            'customer_owned' => true, 'location' => 'استلام الورشة']);
        $workshop->receive((int) $writer->id, $intakeId, 1, (string) Str::uuid(), CarbonImmutable::now('UTC')->subHours(2)->toIso8601String(), 'استلام مصدر تحقق سلبي.');

        return $intakeId;
    };
    $wrongEquipmentIntake = $receivedIntake('M09-WRONG-'.$tag, 'معدة غير مشمولة بالعقد.');
    $reject(fn () => $workshop->createJob((int) $writer->id, $wrongEquipmentIntake, 2, (string) Str::uuid(), $workshopId, 'normal', 'contract', $contractId, $today),
        'M09 serial-number projection rejects equipment outside the active contract coverage');
    $expiredDateIntake = $receivedIntake($m09Equipment, 'معدة بتاريخ خدمة خارج مدة العقد.');
    $reject(fn () => $workshop->createJob((int) $writer->id, $expiredDateIntake, 2, (string) Str::uuid(), $workshopId, 'normal', 'contract', $contractId,
        now('Africa/Cairo')->addMonths(4)->toDateString()), 'M09 owner source rejects a contract service date beyond the contract term');
    $pass(! DB::table('workshop_jobs')->whereIn('intake_id', [$wrongEquipmentIntake, $expiredDateIntake])->exists()
        && DB::table('workshop_intakes')->whereIn('id', [$wrongEquipmentIntake, $expiredDateIntake])->where('status', 'received')->count() === 2,
        'M09 failed coverage handoffs leave received intake intact and create no ineligible contract jobs');
    // The expired-date handoff rolled back only job creation; reuse its still-received, serial-identified intake.
    $intake = $expiredDateIntake;
    $job = $workshop->createJob((int) $writer->id, $intake, 2, (string) Str::uuid(), $workshopId, 'normal', 'contract', $contractId, $today);
    $pass(DB::table('workshop_jobs')->where('id', $job)->where('billing_mode', 'contract')->where('contract_id', $contractId)->exists()
        && DB::table('service_contract_work')->where('owner', 'WorkshopRepairs')->where('source_id', $job)->where('status', 'reserved')->count() === 1,
        'M09 creates a customer-owned contract job from its serial-number identity and reserves through M10');
    $workshopReservation = DB::table('service_contract_work')->where('owner', 'WorkshopRepairs')->where('source_id', $job)->first();
    $pass($api->reserveWork((int) $writer->id, $contractId, 'WorkshopRepairs', $job) === (int) $workshopReservation->id,
        'M09 repeated reservation of the same real job is idempotent');
    $reject(fn () => $api->reserveWork((int) $outsider->id, $contractId, 'WorkshopRepairs', $job),
        'M10 coverage authority denies an out-of-scope user on a real M09 job', 403);
    $workshop->assign((int) $writer->id, $job, 1, $workshopId, (int) $workshopTechnician->id, 'تعيين فني مؤهل للفحص.');
    $workshop->diagnose((int) $workshopTechnician->id, $job, 2, ['diagnosis' => 'تلامس غير ثابت في نقاط التوصيل.',
        'planned_work' => 'إعادة تثبيت نقاط التوصيل وتنظيفها.', 'required_tests' => [['name' => 'اختبار التشغيل', 'required' => true]], 'estimated_minutes' => 30]);
    $jobRow = DB::table('workshop_jobs')->find($job);
    app(RepairOffers::class)->contract((int) $reviewer->id, $job, (int) $jobRow->version, 'اعتماد عقد مستقل للاختبار.');
    $jobRow = DB::table('workshop_jobs')->find($job);
    app(WorkshopExecution::class)->state((int) $workshopTechnician->id, $job, (int) $jobRow->version, 'start', 'بدء إصلاح تعاقدي مصرح به.');
    $jobRow = DB::table('workshop_jobs')->find($job);
    $started = CarbonImmutable::now('UTC')->startOfMinute()->subMinutes(20);
    app(WorkshopExecution::class)->record((int) $workshopTechnician->id, $job, (int) $jobRow->version, (string) Str::uuid(), [
        'started_at' => $started->toIso8601String(), 'ended_at' => $started->addMinutes(10)->toIso8601String(),
        'work_performed' => 'تم تنظيف وتثبيت نقاط التوصيل.', 'observations' => 'استقرت نقاط التلامس.', 'work_type' => 'إصلاح تعاقدي']);
    $m09ReservedUsage = DB::table('service_contract_work')->where('owner', 'WorkshopRepairs')->where('source_id', $job)->first();
    $m09SourceVersion = DB::table('workshop_jobs')->find($job)->version;
    $pass($m09ReservedUsage && $m09ReservedUsage->status === 'reserved' && (int) $m09ReservedUsage->visits === 1
        && (int) $m09ReservedUsage->labor_minutes === 10 && (int) $m09ReservedUsage->source_version === (int) $m09SourceVersion
        && json_decode($m09ReservedUsage->parts_snapshot, true, 512, JSON_THROW_ON_ERROR) === [],
        'M09 actual work action immediately synchronizes the reservation from the owner projection');
    $jobRow = DB::table('workshop_jobs')->find($job);
    app(WorkshopExecution::class)->state((int) $workshopTechnician->id, $job, (int) $jobRow->version, 'testing', 'بدء اختبارات ما بعد الإصلاح.');
    $jobRow = DB::table('workshop_jobs')->find($job);
    app(WorkshopExecution::class)->test((int) $workshopTechnician->id, $job, (int) $jobRow->version, ['name' => 'اختبار التشغيل', 'outcome' => 'passed', 'results' => 'نجح التشغيل المستمر دون فصل.']);
    $m09Projection = app(WorkshopApi::class)->contractWork((int) $reviewer->id, $job);
    $pass($m09Projection['contract_id'] === $contractId && $m09Projection['equipment_ref'] === $m09Equipment
        && $m09Projection['kind'] === 'corrective' && $m09Projection['visits'] === 1 && $m09Projection['labor_minutes'] === 10
        && $m09Projection['parts'] === [],
        'M09 contract-work projection resolves real serial-number identity and actual workshop labor before consumption');
    $jobRow = DB::table('workshop_jobs')->find($job);
    app(WorkshopExecution::class)->verify((int) $reviewer->id, $job, (int) $jobRow->version, 'تم الإصلاح والتشغيل.', 'لا توجد أعطال متبقية.');
    $workshopUse = DB::table('service_contract_work')->where('owner', 'WorkshopRepairs')->where('source_id', $job)->first();
    $pass($workshopUse && $workshopUse->status === 'consumed' && (int) $workshopUse->visits === 1 && (int) $workshopUse->labor_minutes === 10
        && ! DB::table('service_contract_work_parts')->where('work_id', $workshopUse->id)->exists()
        && DB::table('workshop_work_records')->where('job_id', $job)->count() === 1
        && $api->consumeWork((int) $reviewer->id, 'WorkshopRepairs', $job) === (int) $workshopUse->id,
        'M09 independent repair authorization accepts exactly the remaining ten covered labor minutes and consumes the source once');
    $jobRow = DB::table('workshop_jobs')->find($job);
    app(WorkshopExecution::class)->deliver((int) $writer->id, $job, (int) $jobRow->version, (string) Str::uuid(), [
        'recipient' => 'مندوب العميل', 'accessories' => 'غطاء وحامل', 'final_condition' => 'تم الإصلاح والتحقق الفني.',
        'acknowledgment' => 'استلم العميل المعدة بعد الإصلاح.', 'delivered_at' => CarbonImmutable::now('UTC')->subMinute()->toIso8601String(),
        'unrepaired' => false]);
    $pass(DB::table('workshop_jobs')->where('id', $job)->where('status', 'delivered')->exists()
        && DB::table('workshop_deliveries')->where('job_id', $job)->where('unrepaired', false)->exists(),
        'M09 verified contract repair completes customer custody handoff before a later intake for the same serial');
    $cappedIntake = $receivedIntake($m09Equipment, 'معدة ثانية بعد استهلاك الزيارة المغطاة.');
    $reject(fn () => $workshop->createJob((int) $writer->id, $cappedIntake, 2, (string) Str::uuid(), $workshopId, 'normal', 'contract', $contractId, $today),
        'M09 cannot create another contract repair after its real serial-number visit cap is consumed');
    $pass(DB::table('workshop_intakes')->where('id', $cappedIntake)->where('status', 'received')->exists()
        && ! DB::table('workshop_jobs')->where('intake_id', $cappedIntake)->exists(),
        'M09 exhausted visit handoff preserves the intake and creates no second job');

    $releaseIntake = $workshop->saveIntake((int) $writer->id, null, 0, (string) Str::uuid(), ['branch_id' => $branch->id,
        'customer_id' => $customer->id, 'equipment_description' => 'قاطع ثانٍ لإثبات تحرير الحجز.', 'model' => 'M10-I-R', 'serial_number' => $m09ReleaseEquipment,
        'accessories' => 'غطاء', 'reported_fault' => 'فصل غير متكرر.', 'arrival_condition' => 'الجسم سليم ظاهرياً.',
        'customer_owned' => true, 'location' => 'استلام الورشة']);
    $workshop->receive((int) $writer->id, $releaseIntake, 1, (string) Str::uuid(), CarbonImmutable::now('UTC')->subHours(2)->toIso8601String(), 'استلام جهاز تحرير الحجز.');
    $releaseJob = $workshop->createJob((int) $writer->id, $releaseIntake, 2, (string) Str::uuid(), $workshopId, 'low', 'contract', $contractId, $today);
    app(WorkshopExecution::class)->unrepairable((int) $writer->id, $releaseJob, 1, 'لا حاجة للإصلاح بعد الفحص الأولي المستقل.');
    $releaseJobRow = DB::table('workshop_jobs')->find($releaseJob);
    app(WorkshopExecution::class)->deliver((int) $writer->id, $releaseJob, (int) $releaseJobRow->version, (string) Str::uuid(), [
        'recipient' => 'مندوب العميل', 'accessories' => 'غطاء', 'final_condition' => 'أعيدت دون تنفيذ إصلاح.',
        'acknowledgment' => 'استلم العميل معدته دون إصلاح.', 'delivered_at' => CarbonImmutable::now('UTC')->subMinute()->toIso8601String(), 'unrepaired' => true]);
    $api->releaseWork((int) $writer->id, 'WorkshopRepairs', $releaseJob);
    $releaseWorkshopUse = DB::table('service_contract_work')->where('owner', 'WorkshopRepairs')->where('source_id', $releaseJob)->first();
    $releasedM09Coverage = $api->coverage((int) $writer->id, $contractId, (int) $customer->id, (int) $branch->id, $m09ReleaseEquipment, null, $today, 'corrective');
    $pass($releaseWorkshopUse && $releaseWorkshopUse->status === 'released' && $releaseWorkshopUse->released_at !== null
        && (int) $releaseWorkshopUse->visits === 1 && (int) $releaseWorkshopUse->labor_minutes === 0
        && ! DB::table('service_contract_work_parts')->where('work_id', $releaseWorkshopUse->id)->exists()
        && (int) $releasedM09Coverage['remaining_visits'] === 1,
        'M09 unrepaired return releases the reservation while preserving its immutable visit history and restoring coverage');

    $coverage = $api->coverage((int) $writer->id, $contractId, (int) $customer->id, (int) $branch->id, $m08ReleaseEquipment, null, $today, 'preventive');
    $pass((int) ($coverage['remaining_visits'] ?? -1) === 1 && (int) ($coverage['remaining_labor_minutes'] ?? -1) === 0
        && ($coverage['remaining_parts'] ?? null) === [],
        'M10 coverage reflects exact M08 and M09 source-derived labor totals and released M08 reservations restore visit availability');
    $fixtureCounts = $counts();
} catch (Throwable $error) {
    $failure = $error;
} finally {
    DB::rollBack();
    $after = $counts();
    if (is_dir($privateRoot) && realpath(dirname($privateRoot)) === realpath($taskRoot)
        && str_starts_with(basename($privateRoot), 'm10-integration-private-')) {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($privateRoot);
    }
}

$privateScratchClean = ! is_dir($privateRoot);
$rollbackVerified = $before === $after && $privateScratchClean;
$checks[] = ['name' => 'All source graph fixtures roll back from the exact isolated database.', 'result' => $rollbackVerified ? 'PASS' : 'FAIL'];
$assertions++;
if (! $rollbackVerified && $failure === null) {
    $failure = new RuntimeException('Integration acceptance left database rows after rollback.');
}
$receipt = ['all_pass' => $failure === null, 'mode' => 'm10-source-integrations', 'assertions' => $assertions,
    'rollback_verified' => $rollbackVerified, 'private_scratch_clean' => $privateScratchClean,
    'database' => ['name' => $identity->database, 'server_address' => $identity->server_address,
        'port' => (int) $identity->port, 'postgres_major' => intdiv((int) $identity->server_version_num, 10000)],
    'counts' => ['before' => $before, 'during_fixture' => $fixtureCounts, 'after_rollback' => $after], 'checks' => $checks,
    'failure' => $failure ? ['class' => $failure::class, 'message' => mb_substr((string) preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $failure->getMessage()), 0, 500)] : null];
$receiptPath = $emit($receipt);
fwrite($failure ? STDERR : STDOUT, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
fwrite($failure ? STDERR : STDOUT, 'RESULT all_pass='.($failure ? '0' : '1').' assertions='.$assertions.' rollback_verified='.($rollbackVerified ? '1' : '0').' receipt='.$receiptPath.PHP_EOL);
if ($failure) {
    exit(1);
}
