<?php

declare(strict_types=1);

// Gate 3 M15 source preflight, then guarded PostgreSQL behavior acceptance.
use App\Models\User;
use App\Modules\Attendance\Application\AttendanceAccess;
use App\Modules\Attendance\Application\AttendanceActions;
use App\Modules\Attendance\Application\AttendanceImport;
use App\Modules\Attendance\Application\AttendanceReports;
use App\Modules\Attendance\Application\Contracts\AttendanceApi;
use App\Modules\Attendance\Domain\AttendanceAdjustment;
use App\Modules\Attendance\Domain\AttendancePunch;
use App\Modules\Attendance\Domain\AttendanceSchedule;
use App\Modules\Attendance\Domain\AttendanceShift;
use App\Modules\Attendance\Domain\AttendanceTimesheet;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\EmployeeActions;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Employee;
use Database\Seeders\AttendanceDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$root = dirname(__DIR__, 2);
$writeReceipt = static function (string $preferredPath, array $receipt): string {
    $contents = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    $path = $preferredPath;
    while (($handle = @fopen($path, 'x')) === false) {
        if (! file_exists($path)) {
            throw new RuntimeException('Could not create M15 evidence receipt.');
        }
        $path = dirname($preferredPath).'/'.pathinfo($preferredPath, PATHINFO_FILENAME).'-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.'.pathinfo($preferredPath, PATHINFO_EXTENSION);
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new RuntimeException('Could not write complete M15 evidence receipt.');
        }
    } finally {
        fclose($handle);
    }

    return $path;
};
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';

// Fail before touching PostgreSQL until the owner module and its delivery fixtures exist.
$requiredFiles = [
    'M01 employee actions' => $root.'/app/Modules/Organization/Application/EmployeeActions.php',
    'M01 organization API' => $root.'/app/Modules/Organization/Application/Contracts/OrganizationApi.php',
    'M01 employee model' => $root.'/app/Modules/Organization/Domain/Employee.php',
    'M01 employee factory' => $root.'/database/factories/EmployeeFactory.php',
    'M15 attendance actions' => $root.'/app/Modules/Attendance/Application/AttendanceActions.php',
    'M15 attendance import' => $root.'/app/Modules/Attendance/Application/AttendanceImport.php',
    'M15 attendance access guard' => $root.'/app/Modules/Attendance/Application/AttendanceAccess.php',
    'M15 attendance reports' => $root.'/app/Modules/Attendance/Application/AttendanceReports.php',
    'M15 attendance API' => $root.'/app/Modules/Attendance/Application/Contracts/AttendanceApi.php',
    'M15 attendance adjustment model' => $root.'/app/Modules/Attendance/Domain/AttendanceAdjustment.php',
    'M15 punch model' => $root.'/app/Modules/Attendance/Domain/AttendancePunch.php',
    'M15 schedule model' => $root.'/app/Modules/Attendance/Domain/AttendanceSchedule.php',
    'M15 shift model' => $root.'/app/Modules/Attendance/Domain/AttendanceShift.php',
    'M15 timesheet model' => $root.'/app/Modules/Attendance/Domain/AttendanceTimesheet.php',
    'M15 shift factory' => $root.'/database/factories/AttendanceShiftFactory.php',
    'M15 schedule factory' => $root.'/database/factories/AttendanceScheduleFactory.php',
    'M15 punch factory' => $root.'/database/factories/AttendancePunchFactory.php',
    'M15 adjustment factory' => $root.'/database/factories/AttendanceAdjustmentFactory.php',
    'M15 timesheet factory' => $root.'/database/factories/AttendanceTimesheetFactory.php',
    'M15 demo seeder' => $root.'/database/seeders/AttendanceDemoSeeder.php',
];
$requiredClasses = [
    'M01 employee actions' => EmployeeActions::class,
    'M01 organization API' => OrganizationApi::class,
    'M01 employee model' => Employee::class,
    'M15 attendance actions' => AttendanceActions::class,
    'M15 attendance import' => AttendanceImport::class,
    'M15 attendance access guard' => AttendanceAccess::class,
    'M15 attendance reports' => AttendanceReports::class,
    'M15 attendance API' => AttendanceApi::class,
    'M15 attendance adjustment model' => AttendanceAdjustment::class,
    'M15 punch model' => AttendancePunch::class,
    'M15 schedule model' => AttendanceSchedule::class,
    'M15 shift model' => AttendanceShift::class,
    'M15 timesheet model' => AttendanceTimesheet::class,
    'M15 demo seeder' => AttendanceDemoSeeder::class,
];
$missingFiles = array_keys(array_filter($requiredFiles, static fn (string $path): bool => ! is_file($path)));
$attendanceMigrations = array_values(array_filter(glob($root.'/database/migrations/*.php') ?: [], static fn (string $path): bool =>
    str_contains(strtolower(basename($path)), 'attendance') || str_contains(strtolower(basename($path)), 'm15')));
if ($attendanceMigrations === []) $missingFiles[] = 'M15 forward migration';
$missingClasses = array_keys(array_filter($requiredClasses, static fn (string $class): bool => ! class_exists($class) && ! interface_exists($class)));
$missingMethods = [];
foreach ([AttendanceActions::class => ['saveShift', 'schedule', 'punch', 'requestAdjustment', 'approveAdjustment', 'timesheet', 'lockTimesheet', 'export'],
    AttendanceImport::class => ['import'],
    AttendanceApi::class => ['timesheet', 'export', 'workedIntervals'], OrganizationApi::class => ['employeeIdentity', 'employee']] as $class => $methods) {
    if (class_exists($class) || interface_exists($class)) {
        foreach ($methods as $method) if (! method_exists($class, $method)) $missingMethods[] = $class.'::'.$method;
    }
}
$moduleRegistry = require $root.'/config/modules.php';
$module = $moduleRegistry['Attendance'] ?? null;
$registryReady = is_array($module) && ($module['id'] ?? null) === 'M15'
    && ($module['permission_prefix'] ?? null) === 'M15' && ($module['implemented'] ?? false) === true;
$missingPermissions = [];
if (class_exists(App\Modules\Organization\Application\PermissionCatalog::class)) {
    foreach (['M15.shift_manage', 'M15.schedule_manage', 'M15.punch_manage', 'M15.adjustment_request',
        'M15.adjustment_approve', 'M15.view', 'M15.timesheet_lock', 'M15.export'] as $permission) {
        if ((App\Modules\Organization\Application\PermissionCatalog::DEFINITIONS[$permission][1] ?? null) !== 'branch') {
            $missingPermissions[] = $permission;
        }
    }
} else {
    $missingPermissions[] = 'PermissionCatalog';
}

if ($missingFiles !== [] || $missingClasses !== [] || $missingMethods !== [] || $missingPermissions !== [] || ! $registryReady) {
    $receipt = ['all_pass' => false, 'phase' => 'Gate3 source preflight before M15 acceptance',
        'scope' => 'M15 Attendance plus M01 Employee authority', 'm15_registry_ready' => $registryReady,
        'missing_files' => $missingFiles, 'missing_classes' => $missingClasses,
        'missing_methods' => $missingMethods, 'missing_permissions' => $missingPermissions];
    $evidenceDirectory = $root.'/docs/release/remediation/evidence/gate3';
    if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
        fwrite(STDERR, "Unable to create Gate3 evidence directory.\n");
        exit(2);
    }
    $evidencePath = $writeReceipt($evidenceDirectory.'/m15-attendance-red.json', $receipt);
    fwrite(STDERR, 'RESULT source_preflight=RED missing_files='.count($missingFiles).' missing_classes='.count($missingClasses)
        .' missing_methods='.count($missingMethods).' missing_permissions='.count($missingPermissions)
        .' registry_ready='.($registryReady ? 'yes' : 'no').' evidence='.$evidencePath.PHP_EOL);
    exit(1);
}

$environment = ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $environment);
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$privateStorage = $taskRoot.'/m15-private-storage-'.bin2hex(random_bytes(8));
$privateCache = '.tmp/master-remediation-20261101/m15-check-cache';
$privateViews = $taskRoot.'/m15-views';
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'], 'DB_PORT' => (string) $target['port'],
    'DB_DATABASE' => $target['database'], 'DB_USERNAME' => $target['username'], 'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''),
    'DB_URL' => '', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'LARAVEL_STORAGE_PATH' => $privateStorage, 'APP_CONFIG_CACHE' => $privateCache.'/config.php',
    'APP_ROUTES_CACHE' => $privateCache.'/routes.php', 'APP_EVENTS_CACHE' => $privateCache.'/events.php',
    'APP_SERVICES_CACHE' => $privateCache.'/services.php', 'APP_PACKAGES_CACHE' => $privateCache.'/packages.php',
    'APP_VIEW_COMPILED_PATH' => $privateViews, 'VIEW_COMPILED_PATH' => $privateViews,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach ([$privateCache, $privateStorage, $privateViews] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create private M15 check runtime directory.');
    }
}
$identity = null;
$targetVerified = false;
$tables = ['attendance_shifts', 'attendance_schedules', 'attendance_punches', 'attendance_adjustments', 'attendance_timesheets',
    'attendance_requests', 'organization_employees'];
try {
    $app = require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $app->useStoragePath($privateStorage);
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
    $targetVerified = true;
    if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
        throw new RuntimeException('M15 acceptance requires the explicitly owned PostgreSQL 17 test target.');
    }
    foreach ($tables as $table) {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            throw new RuntimeException("M15 acceptance requires migrated {$table} in the owned PostgreSQL test database.");
        }
    }
} catch (Throwable $error) {
    $evidenceDirectory = $root.'/docs/release/remediation/evidence/gate3';
    if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
        fwrite(STDERR, "Unable to create Gate3 bootstrap evidence directory.\n");
        exit(2);
    }
    $evidencePath = $evidenceDirectory.'/m15-attendance-bootstrap-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(6)).'.json';
    $receipt = ['all_pass' => false, 'phase' => 'Gate3 guarded bootstrap/schema preflight',
        'database_target_verified' => $targetVerified, 'database_identity' => $identity,
        'failure' => ['class' => $error::class, 'message' => $error->getMessage()]];
    $evidencePath = $writeReceipt($evidencePath, $receipt);
    if (is_dir($privateStorage) && realpath(dirname($privateStorage)) === realpath($taskRoot)
        && str_starts_with(basename($privateStorage), 'm15-private-storage-')) {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($privateStorage);
    }
    fwrite(STDERR, 'RESULT gate=3 scope=m15-attendance result=BLOCKED_OR_FAIL phase=bootstrap evidence='.$evidencePath.PHP_EOL);
    fwrite(STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}

$checks = [];
$assertions = 0;
$check = static function (bool $condition, string $name) use (&$checks, &$assertions): void {
    $checks[] = ['name' => $name, 'result' => $condition ? 'PASS' : 'FAIL'];
    if (! $condition) {
        throw new RuntimeException('FAIL '.$name);
    }
    $assertions++;
    fwrite(STDOUT, 'PASS '.$name.PHP_EOL);
};
$reject = static function (callable $operation, string $name) use ($check): void {
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof ValidationException || $error instanceof ContractException
            || ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404, 409, 422], true))) {
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
$scope = static function (User $actor, Branch $branch): void {
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $actor->id, 'branch_id' => $branch->id,
        'created_at' => now(), 'updated_at' => now()]);
};
$countTables = static function () use ($tables): array {
    $counts = [];
    foreach (array_merge($tables, ['companies', 'branches', 'users', 'access_grants', 'user_branch_scopes', 'user_roles', 'audit_entries',
        'accounting_journals', 'treasury_movements', 'rp_customer_advances']) as $table) {
        if (DB::getSchemaBuilder()->hasTable($table)) {
            $counts[$table] = (int) DB::table($table)->count();
        }
    }
    return $counts;
};
$cairoIso = static fn (string $date, string $time): string => Illuminate\Support\Carbon::parse($date.' '.$time, 'Africa/Cairo')->toIso8601String();
$sameInstant = static fn (mixed $actual, string $expected): bool => is_string($actual)
    && Illuminate\Support\Carbon::parse($actual)->utc()->equalTo(Illuminate\Support\Carbon::parse($expected)->utc());
$monthRows = static function (array $sheet): array { return $sheet['days'] ?? []; };
$rowOn = static function (array $sheet, string $date) use ($monthRows): array {
    foreach ($monthRows($sheet) as $row) {
        if (($row['work_date'] ?? null) === $date) return $row;
    }
    throw new RuntimeException('Expected M15 work date missing from timesheet: '.$date);
};

$beforeCounts = $countTables();
$fixtureCounts = $beforeCounts;
$afterRollbackCounts = [];
$failure = null;
DB::beginTransaction();
try {
    $writer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $branchPermissions = ['M15.shift_manage', 'M15.schedule_manage', 'M15.punch_manage', 'M15.adjustment_request',
        'M15.adjustment_approve', 'M15.view', 'M15.timesheet_lock', 'M15.export', 'M01.employee_view'];
    $grant($writer, ['M01.company_update', 'M01.branch_view'], null);
    if (! DB::table('companies')->where('id', 1)->exists()) {
        app(SaveCompany::class)->execute($writer, ['code' => 'M15-SYNTHETIC-'.Str::upper(Str::random(6)),
            'name' => 'Gate 3 synthetic attendance company', 'legal_name' => 'Gate 3 Synthetic Attendance Company',
            'tax_registration' => 'M15-TEST-ONLY', 'address' => 'Synthetic rollback fixture'], null);
    }
    $branch = Branch::factory()->create(['company_id' => 1, 'active' => true]);
    $otherBranch = Branch::factory()->create(['company_id' => 1, 'active' => true]);
    foreach ([$writer, $reviewer] as $actor) $scope($actor, $branch);
    $scope($outsider, $otherBranch);
    $grant($writer, $branchPermissions, (int) $branch->id);
    $grant($reviewer, ['M15.adjustment_approve', 'M15.view', 'M15.timesheet_lock', 'M15.export', 'M01.employee_view'], (int) $branch->id);
    $grant($outsider, ['M15.schedule_manage', 'M15.punch_manage', 'M15.view', 'M15.export'], (int) $otherBranch->id);

    $securityBeforeFactory = ['grants' => DB::table('access_grants')->count(), 'scopes' => DB::table('user_branch_scopes')->count(),
        'roles' => DB::table('user_roles')->count()];
    $factoryShift = AttendanceShift::factory()->create(['branch_id' => $branch->id]);
    $factoryShift->refresh();
    $check($factoryShift->exists && $factoryShift->branch_id === $branch->id && (int) $factoryShift->version === 1,
        'M15 shift factory persists a branch-owned, versioned shift');
    $check($securityBeforeFactory === ['grants' => DB::table('access_grants')->count(), 'scopes' => DB::table('user_branch_scopes')->count(),
        'roles' => DB::table('user_roles')->count()], 'M15 factories create no login, privilege, role, or branch-scope history');

    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'starts_on' => '2024-01-01', 'ends_on' => null,
        'active' => true, 'user_id' => null]);
    $formerEmployee = Employee::factory()->create(['branch_id' => $branch->id, 'starts_on' => '2024-01-01',
        'ends_on' => '2025-09-30', 'active' => false, 'user_id' => null]);
    $formerIdentity = app(OrganizationApi::class)->employeeIdentity((int) $reviewer->id, (int) $formerEmployee->id);
    $check(($formerIdentity['id'] ?? null) === (int) $formerEmployee->id && ($formerIdentity['branch_id'] ?? null) === (int) $branch->id
        && ! array_key_exists('salary', $formerIdentity) && ! array_key_exists('hourly_cost', $formerIdentity)
        && ! array_key_exists('pay_rate', $formerIdentity) && ! array_key_exists('bank_details', $formerIdentity),
        'Historical employee identity remains safely readable after employment ends');
    $reject(fn () => app(OrganizationApi::class)->employeeIdentity((int) $outsider->id, (int) $formerEmployee->id),
        'Historical employee identity remains branch-scoped');
    $shiftData = ['branch_id' => $branch->id, 'code' => 'M15-DAY-'.Str::upper(Str::random(6)), 'name' => 'وردية نهارية اختبارية',
        'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 30, 'grace_minutes' => 10];
    $actions = app(AttendanceActions::class);
    $dayShiftId = $actions->saveShift((int) $writer->id, null, 0, $shiftData);
    $actions->saveShift((int) $writer->id, $dayShiftId, 1, array_replace($shiftData, ['name' => 'وردية نهارية محدثة']));
    $check(DB::table('attendance_shifts')->where('id', $dayShiftId)->where('version', 2)->where('name', 'وردية نهارية محدثة')->exists(),
        'Shift edits use optimistic versioning and persist only approved fields');
    $reject(fn () => $actions->saveShift((int) $writer->id, $dayShiftId, 1, $shiftData), 'Stale shift version cannot overwrite current shift');
    $reject(fn () => $actions->saveShift((int) $writer->id, null, 0, array_replace($shiftData, ['code' => 'ZERO-'.Str::random(5), 'start_time' => '09:00', 'end_time' => '09:00'])),
        'Shift with equal start and end is rejected as ambiguous zero or 24-hour duration');
    $reject(fn () => $actions->saveShift((int) $writer->id, null, 0, array_replace($shiftData, ['code' => 'BAD-'.Str::random(5), 'start_time' => '25:00'])),
        'Shift rejects invalid local clock values');

    $formerSchedule = AttendanceSchedule::factory()->create(['employee_id' => $formerEmployee->id, 'branch_id' => $branch->id,
        'shift_id' => $dayShiftId, 'work_date' => '2025-09-29', 'starts_at' => $cairoIso('2025-09-29', '09:00'),
        'ends_at' => $cairoIso('2025-09-29', '17:00'), 'shift_snapshot' => ['start_time' => '09:00', 'end_time' => '17:00',
            'break_minutes' => 30, 'grace_minutes' => 10], 'creation_key' => (string) Str::uuid(), 'created_by' => $writer->id]);
    foreach ([['09:00', 'in'], ['12:00', 'break_start'], ['12:30', 'break_end'], ['17:00', 'out']] as [$time, $kind]) {
        AttendancePunch::factory()->create(['employee_id' => $formerEmployee->id, 'branch_id' => $branch->id,
            'schedule_id' => $formerSchedule->id, 'occurred_at' => $cairoIso('2025-09-29', $time), 'kind' => $kind,
            'creation_key' => (string) Str::uuid(), 'created_by' => $writer->id]);
    }
    $formerHistory = app(AttendanceApi::class)->timesheet((int) $reviewer->id, (int) $formerEmployee->id, '2025-09');
    $check(($rowOn($formerHistory, '2025-09-29')['worked_minutes'] ?? null) === 450,
        'Historical timesheet remains readable after employee deactivation and end date');

    $daySchedule = $actions->schedule((int) $writer->id, (int) $employee->id, $dayShiftId, '2025-10-30', (string) Str::uuid());
    $scheduleKey = (string) Str::uuid();
    $octoberOvernightShift = $actions->saveShift((int) $writer->id, null, 0, array_replace($shiftData, ['code' => 'M15-NIGHT-'.Str::upper(Str::random(6)),
        'name' => 'وردية ليلية اختبارية', 'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 0, 'grace_minutes' => 0]));
    $dstSchedule = $actions->schedule((int) $writer->id, (int) $employee->id, $octoberOvernightShift, '2025-04-24', (string) Str::uuid());
    $dstIn = $actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-04-24', '22:00'), 'in', (string) Str::uuid());
    $dstOut = $actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-04-25', '06:00'), 'out', (string) Str::uuid());
    $dstDay = $rowOn($actions->timesheet((int) $reviewer->id, (int) $employee->id, '2025-04'), '2025-04-24');
    $check(DB::table('attendance_schedules')->find($dstSchedule) !== null
        && DB::table('attendance_punches')->whereIn('id', [$dstIn, $dstOut])->count() === 2
        && ($dstDay['worked_minutes'] ?? null) === 420,
        'Cairo spring DST transition keeps the overnight shift on its local start date and counts elapsed UTC minutes');
    $factorySchedule = AttendanceSchedule::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
        'shift_id' => $octoberOvernightShift, 'work_date' => '2025-04-20', 'created_by' => $writer->id]);
    $factoryAdjustment = AttendanceAdjustment::factory()->create(['schedule_id' => $factorySchedule->id, 'created_by' => $writer->id]);
    $check($factorySchedule->exists && (int) $factorySchedule->employee_id === (int) $employee->id
        && (int) $factorySchedule->shift_id === $octoberOvernightShift && $factoryAdjustment->exists
        && (int) $factoryAdjustment->schedule_id === (int) $factorySchedule->id
        && $factoryAdjustment->status === 'pending' && $factoryAdjustment->reviewed_by === null,
        'M15 schedule and adjustment factories persist a consistent pending graph without fabricating a review');
    $overnightSchedule = $actions->schedule((int) $writer->id, (int) $employee->id, $octoberOvernightShift, '2025-10-31', $scheduleKey);
    $check($actions->schedule((int) $writer->id, (int) $employee->id, $octoberOvernightShift, '2025-10-31', $scheduleKey) === $overnightSchedule,
        'Exact schedule creation-key replay returns the original schedule');
    $reject(fn () => $actions->schedule((int) $writer->id, (int) $employee->id, $dayShiftId, '2025-10-31', $scheduleKey),
        'Changed schedule payload cannot reuse an existing creation key');
    $reject(fn () => $actions->schedule((int) $outsider->id, (int) $employee->id, $dayShiftId, '2025-10-31', (string) Str::uuid()),
        'Schedule creation requires the employee branch permission and scope');

    $ids = [];
    $dayPunchKeys = [];
    foreach ([['09:12', 'in'], ['12:00', 'break_start'], ['12:30', 'break_end'], ['16:45', 'out']] as [$time, $kind]) {
        $dayPunchKeys[$kind] = (string) Str::uuid();
        $ids[$kind] = $actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-10-30', $time), $kind, $dayPunchKeys[$kind]);
    }
    $punchKey = $dayPunchKeys['in'];
    $check($actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-10-30', '09:12'), 'in', $punchKey) === $ids['in'],
        'Exact punch creation-key replay does not duplicate an event');
    $check($actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-10-30', '09:12'), 'in', (string) Str::uuid()) === $ids['in'],
        'Duplicate biometric event identity is deduplicated even when a replay uses another import key');
    $dayPunchCount = DB::table('attendance_punches')->count();
    $reject(fn () => $actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-10-30', '09:13'), 'in', $punchKey),
        'Changed punch payload cannot reuse an existing creation key');
    $reject(fn () => $actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-10-30', '10:00'), 'in', (string) Str::uuid()),
        'Conflicting repeated check-in is rejected without a second attendance event');
    $reject(fn () => $actions->punch((int) $outsider->id, (int) $employee->id, $cairoIso('2025-10-30', '09:00'), 'in', (string) Str::uuid()),
        'Punch recording denies an actor without the employee branch scope');
    $reject(fn () => $actions->punch((int) $writer->id, (int) $employee->id, now('Africa/Cairo')->addDay()->toIso8601String(), 'in', (string) Str::uuid()),
        'Future attendance punches are rejected');
    $check(DB::table('attendance_punches')->count() === $dayPunchCount,
        'Idempotency collisions and conflicting or future punches leave no extra attendance rows');

    $csvPath = $privateStorage.'/attendance.csv';
    file_put_contents($csvPath, "employee_id,occurred_at,kind,source_key\n"
        .$employee->id.','.$cairoIso('2025-10-31', '22:00').',in,'.Str::uuid()."\n", LOCK_EX);
    $imported = app(AttendanceImport::class)->import((int) $writer->id, new UploadedFile($csvPath, 'attendance.csv', 'text/csv', null, true));
    $check(($imported['rows'] ?? null) === 1 && count($imported['punch_ids'] ?? []) === 1
        && DB::table('attendance_punches')->where('id', $imported['punch_ids'][0])->where('schedule_id', $overnightSchedule)->where('kind', 'in')->exists(),
        'CSV attendance import writes one authorized event through the real punch action');
    $atomicCounts = ['punches' => DB::table('attendance_punches')->count(), 'requests' => DB::table('attendance_requests')->count(),
        'audit' => DB::table('audit_entries')->count()];
    $invalidCsvPath = $privateStorage.'/attendance-atomic-denial.csv';
    file_put_contents($invalidCsvPath, "employee_id,occurred_at,kind,source_key\n"
        .$employee->id.','.$cairoIso('2025-10-31', '23:00').',out,'.Str::uuid()."\n"
        .$employee->id.','.$cairoIso('2025-10-31', '23:01').',out,'.Str::uuid()."\n", LOCK_EX);
    $reject(fn () => app(AttendanceImport::class)->import((int) $writer->id,
        new UploadedFile($invalidCsvPath, 'attendance-atomic-denial.csv', 'text/csv', null, true)),
        'CSV import denies a conflicting row after an earlier valid row');
    $check($atomicCounts === ['punches' => DB::table('attendance_punches')->count(), 'requests' => DB::table('attendance_requests')->count(),
        'audit' => DB::table('audit_entries')->count()],
        'Denied CSV import rolls back every punch, idempotency receipt, and audit event from the same file');

    $correctionData = ['check_in' => $cairoIso('2025-10-30', '09:00'), 'check_out' => $cairoIso('2025-10-30', '17:00'),
        'break_minutes' => 30, 'reason' => 'تصحيح موثق للاختبار'];
    $correctionId = $actions->requestAdjustment((int) $writer->id, $daySchedule, 'correction', $correctionData, (string) Str::uuid());
    $pendingSheet = $actions->timesheet((int) $writer->id, (int) $employee->id, '2025-10');
    $pendingDay = $rowOn($pendingSheet, '2025-10-30');
    $check(($pendingDay['late_minutes'] ?? null) === 2 && ($pendingDay['early_minutes'] ?? null) === 15
        && ($pendingDay['worked_minutes'] ?? null) === 423 && ($pendingDay['approved_overtime_minutes'] ?? null) === 0,
        'Pending correction is excluded; Cairo-local punches calculate late, early, and break-adjusted time from originals');
    $contractSheet = app(AttendanceApi::class)->timesheet((int) $writer->id, (int) $employee->id, '2025-10');
    $check(($rowOn($contractSheet, '2025-10-30')['worked_minutes'] ?? null) === 423,
        'Attendance owner read contract returns the same factual, branch-authorized timesheet');
    $laborIntervals = app(AttendanceApi::class)->workedIntervals((int) $reviewer->id, (int) $employee->id, '2025-10-01', '2025-11-01');
    $check(is_array($laborIntervals) && ! array_key_exists('salary', $laborIntervals) && ! array_key_exists('hourly_cost', $laborIntervals)
        && ! array_key_exists('wage', $laborIntervals),
        'Attendance exposes only factual intervals for authorized labor reconciliation, without pay or cost data');
    $reject(fn () => $actions->approveAdjustment((int) $writer->id, $correctionId, 1, true, 'self approval'),
        'Adjustment requester cannot approve their own correction');
    $actions->approveAdjustment((int) $reviewer->id, $correctionId, 1, true, 'مراجعة مستقلة');
    $latestCorrectionId = $actions->requestAdjustment((int) $writer->id, $daySchedule, 'correction', array_replace($correctionData,
        ['check_in' => $cairoIso('2025-10-30', '09:05'), 'reason' => 'تصحيح لاحق للاختبار']), (string) Str::uuid());
    $actions->approveAdjustment((int) $reviewer->id, $latestCorrectionId, 1, true, 'اعتماد التصحيح الأحدث');
    $correctedSheet = $actions->timesheet((int) $reviewer->id, (int) $employee->id, '2025-10');
    $correctedDay = $rowOn($correctedSheet, '2025-10-30');
    $check(($correctedDay['late_minutes'] ?? null) === 0 && ($correctedDay['early_minutes'] ?? null) === 0
        && ($correctedDay['correction_id'] ?? null) === $latestCorrectionId
        && DB::table('attendance_adjustments')->where('schedule_id', $daySchedule)->where('kind', 'correction')->where('status', 'approved')->count() === 2,
        'Latest of multiple independently approved corrections controls facts while both immutable requests remain');
    $reject(fn () => $actions->approveAdjustment((int) $reviewer->id, $correctionId, 1, false, 'stale'),
        'Resolved adjustment cannot be changed or re-approved');

    $overtimeSchedule = $actions->schedule((int) $writer->id, (int) $employee->id, $dayShiftId, '2025-10-27', (string) Str::uuid());
    foreach ([['09:00', 'in'], ['12:00', 'break_start'], ['12:30', 'break_end'], ['17:45', 'out']] as [$time, $kind]) {
        $actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-10-27', $time), $kind, (string) Str::uuid());
    }
    $overtimeId = $actions->requestAdjustment((int) $writer->id, $overtimeSchedule, 'overtime', ['minutes' => 30, 'reason' => 'عمل موثق بعد الوردية'], (string) Str::uuid());
    $actions->approveAdjustment((int) $reviewer->id, $overtimeId, 1, true, 'ساعات إضافية مثبتة');
    $afterApprovalSheet = $actions->timesheet((int) $reviewer->id, (int) $employee->id, '2025-10');
    $afterApprovalDay = $rowOn($afterApprovalSheet, '2025-10-27');
    $check(($afterApprovalDay['worked_minutes'] ?? null) === 495 && ($afterApprovalDay['approved_overtime_minutes'] ?? null) === 30,
        'Approved overtime is a factual label bounded by recorded elapsed work and does not invent worked time or wages');
    $reject(fn () => $actions->requestAdjustment((int) $writer->id, $daySchedule, 'overtime', ['minutes' => 46, 'reason' => 'exceeds factual extra'], (string) Str::uuid()),
        'Overtime request cannot exceed actual time beyond the scheduled shift');

    $leaveSchedule = $actions->schedule((int) $writer->id, (int) $employee->id, $dayShiftId, '2025-10-29', (string) Str::uuid());
    $leaveId = $actions->requestAdjustment((int) $writer->id, $leaveSchedule, 'leave', ['reason' => 'إجازة اختبارية'], (string) Str::uuid());
    $actions->approveAdjustment((int) $reviewer->id, $leaveId, 1, true, 'اعتماد مستقل');
    $missingSchedule = $actions->schedule((int) $writer->id, (int) $employee->id, $dayShiftId, '2025-10-28', (string) Str::uuid());
    $actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-10-28', '09:00'), 'in', (string) Str::uuid());
    $absenceSchedule = $actions->schedule((int) $writer->id, (int) $employee->id, $dayShiftId, '2025-10-26', (string) Str::uuid());
    $missingSheet = $actions->timesheet((int) $reviewer->id, (int) $employee->id, '2025-10');
    $check(($rowOn($missingSheet, '2025-10-29')['leave'] ?? false) === true && ($rowOn($missingSheet, '2025-10-29')['absent'] ?? true) === false,
        'Approved leave is visible and is not classified as an absence');
    $missingRow = $rowOn($missingSheet, '2025-10-28');
    $check(($missingRow['missing_punch'] ?? false) === true && ($missingRow['absent'] ?? true) === false,
        'One-sided punch is exposed as a missing-punch exception rather than inferred absence');
    $correctionForMissing = $actions->requestAdjustment((int) $writer->id, $missingSchedule, 'correction', [
        'check_in' => $cairoIso('2025-10-28', '09:00'), 'check_out' => $cairoIso('2025-10-28', '17:00'),
        'break_minutes' => 30, 'reason' => 'استكمال واقعة ناقصة للاختبار'], (string) Str::uuid());
    $actions->approveAdjustment((int) $reviewer->id, $correctionForMissing, 1, true, 'اعتماد مستقل للواقعة');
    $resolvedSheet = $actions->timesheet((int) $reviewer->id, (int) $employee->id, '2025-10');
    $check(($rowOn($resolvedSheet, '2025-10-28')['missing_punch'] ?? true) === false,
        'Approved correction resolves the missing-punch exception without changing the original punch');
    $check(($rowOn($resolvedSheet, '2025-10-26')['absent'] ?? false) === true,
        'Scheduled day without punches or approved leave is exposed as absence');

    $nightIn = $actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-10-31', '22:00'), 'in', (string) Str::uuid());
    $nightOut = $actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-11-01', '06:00'), 'out', (string) Str::uuid());
    $octoberSheet = $actions->timesheet((int) $reviewer->id, (int) $employee->id, '2025-10');
    $novemberSheet = $actions->timesheet((int) $reviewer->id, (int) $employee->id, '2025-11');
    $nightRow = $rowOn($octoberSheet, '2025-10-31');
    $check($sameInstant($nightRow['check_in'] ?? null, $cairoIso('2025-10-31', '22:00'))
        && $sameInstant($nightRow['check_out'] ?? null, $cairoIso('2025-11-01', '06:00'))
        && ($nightRow['worked_minutes'] ?? null) === 480
        && ! in_array('2025-10-31', array_column($monthRows($novemberSheet), 'work_date'), true),
        'Overnight shift across Cairo month boundary is assigned to its schedule start date exactly once');

    $lockedId = $actions->lockTimesheet((int) $reviewer->id, (int) $employee->id, '2025-10', 'إقفال اختبار موثق');
    $lockedRow = DB::table('attendance_timesheets')->find($lockedId);
    $lockedSnapshot = json_decode((string) $lockedRow->snapshot, true, 512, JSON_THROW_ON_ERROR);
    $check(hash_equals((string) $lockedRow->sha256, app(AttendanceReports::class)->digest($lockedSnapshot)),
        'Locked timesheet persists a verifiable canonical SHA-256 snapshot digest');
    $export = $actions->export((int) $reviewer->id, (int) $employee->id, '2025-10');
    $contractExport = app(AttendanceApi::class)->export((int) $reviewer->id, (int) $employee->id, '2025-10');
    $check($lockedId > 0 && ($export['locked'] ?? false) === true && ($contractExport['locked'] ?? false) === true
        && ! array_key_exists('salary', $export)
        && ! array_key_exists('wage', $export) && ! array_key_exists('cost', $export),
        'Authorized export contains factual locked attendance and no payroll, wage, or costing fields');
    $reject(fn () => $actions->punch((int) $writer->id, (int) $employee->id, $cairoIso('2025-11-01', '05:00'), 'break_start', (string) Str::uuid()),
        'October timesheet lock rejects a later punch mutation for its overnight schedule spill into November');
    $reject(fn () => $actions->schedule((int) $writer->id, (int) $employee->id, $dayShiftId, '2025-10-25', (string) Str::uuid()),
        'October timesheet lock prevents adding a schedule to its closed month');
    // Approved adjustments hit the immutable-history trigger before the month UPDATE trigger, so the database UPDATE arm is not isolated here.
    $nightCorrection = ['check_in' => $cairoIso('2025-10-31', '22:00'), 'check_out' => $cairoIso('2025-11-01', '06:00'),
        'break_minutes' => 0, 'reason' => 'تصحيح بعد إقفال الشهر'];
    $reject(fn () => $actions->requestAdjustment((int) $writer->id, $overnightSchedule, 'correction', $nightCorrection, (string) Str::uuid()),
        'Locked start-month prevents a later correction to an overnight schedule');
    $reject(fn () => $actions->export((int) $outsider->id, (int) $employee->id, '2025-10'),
        'Attendance export requires employee branch authority and export permission');
    $check(DB::table('attendance_punches')->whereIn('id', [$nightIn, $nightOut])->count() === 2,
        'Overnight original punches remain append-only after timesheet lock');
    $check(DB::table('audit_entries')->count() > ($beforeCounts['audit_entries'] ?? 0),
        'Attendance actions append auditable actor-attributed history');

    // The explicit demo seeder may add a planned schedule only; the enclosing transaction rolls it back.
    $demoCounts = static fn (): array => ['shifts' => DB::table('attendance_shifts')->count(), 'schedules' => DB::table('attendance_schedules')->count(),
        'punches' => DB::table('attendance_punches')->count(), 'adjustments' => DB::table('attendance_adjustments')->count(),
        'timesheets' => DB::table('attendance_timesheets')->count(), 'requests' => DB::table('attendance_requests')->count(),
        'employees' => DB::table('organization_employees')->count(), 'users' => DB::table('users')->count(),
        'branches' => DB::table('branches')->count(), 'companies' => DB::table('companies')->count(),
        'grants' => DB::table('access_grants')->count(), 'scopes' => DB::table('user_branch_scopes')->count(),
        'roles' => DB::table('user_roles')->count(), 'audit' => DB::table('audit_entries')->count()];
    $demoBefore = $demoCounts();
    app(AttendanceDemoSeeder::class)->run();
    $demoAfterFirst = $demoCounts();
    app(AttendanceDemoSeeder::class)->run();
    $demoAfterSecond = $demoCounts();
    $demoScheduleExists = DB::table('attendance_schedules as s')->join('attendance_shifts as sh', 'sh.id', '=', 's.shift_id')
        ->join('organization_employees as e', 'e.id', '=', 's.employee_id')->where('sh.code', 'DEMO-EG-SHIFT-DAY')
        ->where('e.code', 'DEMO-EG-EMP-ATTENDANCE')->exists();
    $check($demoScheduleExists && $demoAfterFirst === $demoAfterSecond
        && $demoBefore['punches'] === $demoAfterFirst['punches'] && $demoBefore['adjustments'] === $demoAfterFirst['adjustments']
        && $demoBefore['timesheets'] === $demoAfterFirst['timesheets'] && $demoBefore['requests'] === $demoAfterFirst['requests']
        && $demoBefore['grants'] === $demoAfterFirst['grants'] && $demoBefore['scopes'] === $demoAfterFirst['scopes']
        && $demoBefore['roles'] === $demoAfterFirst['roles'],
        'Explicit attendance demo seeder persists one planned graph idempotently without punches, decisions, timesheets, privileges, or authority');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $fixtureCounts = $countTables();
    DB::rollBack();
    $afterRollbackCounts = $countTables();
    if (realpath(dirname($privateStorage)) === realpath($taskRoot) && str_starts_with(basename($privateStorage), 'm15-private-storage-')) {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($privateStorage);
    }
}
$rollbackClean = $beforeCounts === $afterRollbackCounts;
$checks[] = ['name' => 'All fixture rows roll back and original data counts remain unchanged.', 'result' => $rollbackClean ? 'PASS' : 'FAIL'];
$assertions++;
if (! $rollbackClean && $failure === null) $failure = new RuntimeException('M15 acceptance left fixture rows after rollback.');
$receipt = ['all_pass' => $failure === null, 'phase' => 'Gate3 M15 guarded behavior acceptance', 'assertions' => $assertions,
    'database' => ['name' => $identity->database, 'server_address' => $identity->server_address, 'port' => (int) $identity->port,
        'postgres_major' => intdiv((int) $identity->server_version_num, 10000)],
    'counts' => ['before' => $beforeCounts, 'during_fixture' => $fixtureCounts, 'after_rollback' => $afterRollbackCounts],
    'checks' => $checks, 'failure' => $failure === null ? null : ['class' => $failure::class, 'message' => $failure->getMessage()]];
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate3';
if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
    throw new RuntimeException('Unable to create Gate3 evidence directory.');
}
$evidencePath = $writeReceipt($evidenceDirectory.'/m15-attendance-acceptance.json', $receipt);
fwrite(($failure === null ? STDOUT : STDERR), 'RESULT gate=3 scope=m15-attendance result='.($failure === null ? 'PASS' : 'FAIL')
    .' assertions='.$assertions.' evidence='.$evidencePath.PHP_EOL);
fwrite(($failure === null ? STDOUT : STDERR), json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
if ($failure !== null) exit(1);
