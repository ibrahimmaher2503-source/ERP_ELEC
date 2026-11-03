<?php

declare(strict_types=1);

// M01 employee identity acceptance. Requires migration 201 on the marked private test clone.
use App\Models\User;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\ErrorCode;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\EmployeeActions;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Employee;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$root = dirname(__DIR__, 2);
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$targetPath = $taskRoot.'/target.json';
$target = is_file($targetPath) ? json_decode((string) file_get_contents($targetPath), true) : null;
if (! is_array($target) || ($target['database'] ?? null) !== 'erp_elec_new_test' || (int) ($target['port'] ?? 0) !== 49685) {
    throw new RuntimeException('ABORT before writes: expected the owned erp_elec_new_test PostgreSQL clone on port 49685.');
}
$expectedData = realpath($taskRoot.'/pgdata');
$markerPath = $taskRoot.'/pgdata/.codex-audit-owned.txt';
if (! $expectedData || realpath((string) ($target['pgdata'] ?? '')) !== $expectedData || ! is_file($markerPath)
    || trim((string) file_get_contents($markerPath)) !== 'master-remediation-20261101 Gate1 private PostgreSQL marker') {
    throw new RuntimeException('ABORT before writes: private PostgreSQL ownership marker is missing or mismatched.');
}
$cachePath = '.tmp/master-remediation-20261101/employee-check-cache';
$storagePath = $taskRoot.'/employee-check-storage';
$viewPath = $taskRoot.'/employee-check-views';
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '49685', 'DB_DATABASE' => 'erp_elec_new_test',
    'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '', 'DB_URL' => '', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'LARAVEL_STORAGE_PATH' => $storagePath,
    'APP_CONFIG_CACHE' => $cachePath.'/config.php', 'APP_ROUTES_CACHE' => $cachePath.'/routes.php',
    'APP_EVENTS_CACHE' => $cachePath.'/events.php', 'APP_SERVICES_CACHE' => $cachePath.'/services.php',
    'APP_PACKAGES_CACHE' => $cachePath.'/packages.php', 'APP_VIEW_COMPILED_PATH' => $viewPath, 'VIEW_COMPILED_PATH' => $viewPath,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach ([$root.'/'.$cachePath, $storagePath, $viewPath] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create isolated employee-check runtime directory.');
    }
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storagePath);
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port, current_setting('data_directory') as data_directory");
if (DB::connection()->getDriverName() !== 'pgsql' || $identity->database !== 'erp_elec_new_test'
    || $identity->server_address !== '127.0.0.1' || (int) $identity->port !== 49685
    || realpath((string) $identity->data_directory) !== $expectedData) {
    throw new RuntimeException('ABORT before writes: live PostgreSQL identity does not match the owned test clone.');
}
foreach (['organization_employees', 'audit_entries', 'access_grants', 'branches', 'companies', 'users'] as $table) {
    if (! Schema::hasTable($table)) {
        throw new RuntimeException("Run the required test migrations before checking {$table}.");
    }
}

$passes = 0;
$check = static function (bool $condition, string $name) use (&$passes): void {
    if (! $condition) {
        throw new RuntimeException('FAIL '.$name);
    }
    $passes++;
    fwrite(STDOUT, 'PASS '.$name.PHP_EOL);
};
$expect = static function (callable $action, string $name, string $kind, ?string $field = null) use ($check): Throwable {
    try {
        $action();
    } catch (Throwable $error) {
        $ok = match ($kind) {
            'forbidden' => $error instanceof ContractException && $error->errorCode === ErrorCode::Forbidden,
            'validation' => $error instanceof ContractException && $error->errorCode === ErrorCode::ValidationFailed,
            'http409' => $error instanceof HttpExceptionInterface && $error->getStatusCode() === 409,
            'field' => $error instanceof ValidationException && $field !== null && array_key_exists($field, $error->errors()),
            default => false,
        };
        $check($ok, $name.' (actual '.$error::class.': '.$error->getMessage().')');

        return $error;
    }
    $check(false, $name.' (no exception)');
    throw new LogicException('Unreachable');
};
$counts = static fn (): array => array_map(static fn (string $table): int => DB::table($table)->count(),
    ['branches', 'users', 'access_grants', 'organization_employees', 'audit_entries']);
$before = $counts();
DB::beginTransaction();
try {
    DB::table('companies')->insertOrIgnore(['id' => 1, 'code' => 'M01-EMP-CHECK', 'name' => 'Synthetic employee check',
        'currency' => 'EGP', 'setup_complete' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $branchA = Branch::factory()->create(['code' => 'EMP-CHECK-A-'.$suffix]);
    $branchB = Branch::factory()->create(['code' => 'EMP-CHECK-B-'.$suffix]);
    $manager = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $viewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $branchAOnly = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $denied = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $linked = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('users')->where('id', $linked->id)->update(['branch_id' => $branchA->id]);
    $grant = static function (User $actor, string $permission, int $branchId): void {
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now()]);
    };
    foreach ([$branchA, $branchB] as $branch) {
        $grant($manager, 'M01.employee_manage', $branch->id);
    }
    $grant($manager, 'M01.employee_view', $branchA->id);
    $grant($viewer, 'M01.employee_view', $branchA->id);
    $grant($branchAOnly, 'M01.employee_manage', $branchA->id);
    $actions = app(EmployeeActions::class);
    $api = app(OrganizationApi::class);
    $input = static fn (string $code, int $branchId, array $extra = []): array => $extra + [
        'code' => $code, 'name' => 'موظف اختبار '.$code, 'branch_id' => $branchId, 'user_id' => null,
        'starts_on' => '2020-01-01', 'ends_on' => '2030-01-01', 'active' => true, 'job_title' => 'فني اختبار',
    ];

    $expect(fn () => $actions->save($denied->id, null, 0, $input('DENIED-'.$suffix, $branchA->id)), 'Create requires explicit employee-manage authority', 'forbidden');
    $employeeId = $actions->save($manager->id, null, 0, $input('EMP-'.$suffix, $branchA->id));
    $employee = DB::table('organization_employees')->find($employeeId);
    $check($employee && (int) $employee->branch_id === $branchA->id && (int) $employee->version === 1
        && $employee->code === 'EMP-'.$suffix, 'Create stores a version-1 identity on its authorized active branch');
    $audit = DB::table('audit_entries')->where('source_type', 'employee')->where('source_id', $employeeId)->latest('id')->first();
    $check($audit && $audit->action === 'created' && (int) $audit->actor_id === $manager->id
        && json_decode((string) $audit->safe_after, true)['code'] === 'EMP-'.$suffix,
        'Create writes an actor-attributed employee audit entry with the safe identity snapshot');

    $identityAtStart = $api->employee($viewer->id, $employeeId, $branchA->id, '2020-01-01');
    $identityAtEnd = $api->employee($viewer->id, $employeeId, $branchA->id, '2030-01-01');
    $check($identityAtStart['id'] === $employeeId && $identityAtEnd['id'] === $employeeId
        && array_keys($identityAtStart) === ['id', 'code', 'name', 'branch_id', 'user_id', 'starts_on', 'ends_on', 'active', 'version'],
        'Employee lookup returns only the scoped safe identity on inclusive employment-date boundaries');
    $expect(fn () => $api->employee($viewer->id, $employeeId, $branchA->id, '2019-12-31'), 'Employee lookup rejects dates before employment', 'validation');
    $expect(fn () => $api->employee($viewer->id, $employeeId, $branchA->id, '2030-01-02'), 'Employee lookup rejects dates after employment', 'validation');
    $expect(fn () => $api->employee($viewer->id, $employeeId, $branchA->id, '2026-02-30'), 'Employee lookup validates strict calendar dates', 'field', 'date');
    $expect(fn () => $api->employee($denied->id, $employeeId, $branchA->id, '2026-01-01'), 'Employee lookup requires explicit view authority', 'forbidden');

    $otherBranchEmployee = $actions->save($manager->id, null, 0, $input('EMP-B-'.$suffix, $branchB->id));
    $expect(fn () => $api->employee($viewer->id, $otherBranchEmployee, $branchB->id, '2026-01-01'), 'Employee lookup denies an unauthorized branch even for a known record id', 'forbidden');
    $expect(fn () => $actions->save($branchAOnly->id, null, 0, $input('EMP-MOVE-'.$suffix, $branchB->id)),
        'Employee create requires authority scoped to the target branch', 'forbidden');

    $updatedInput = $input('EMP-'.$suffix, $branchA->id, ['job_title' => 'فني صيانة']);
    $updatedId = $actions->save($manager->id, $employeeId, 1, $updatedInput);
    $updated = DB::table('organization_employees')->find($employeeId);
    $check($updatedId === $employeeId && (int) $updated->version === 2 && $updated->job_title === 'فني صيانة',
        'Update accepts the current version and increments it once');
    $auditCount = DB::table('audit_entries')->where('source_type', 'employee')->where('source_id', $employeeId)->count();
    $expect(fn () => $actions->save($manager->id, $employeeId, 1, $input('EMP-'.$suffix, $branchA->id, ['job_title' => 'stale overwrite'])),
        'Concurrent stale employee update is rejected by version check', 'http409');
    $expect(fn () => $actions->save($manager->id, $employeeId, 2, $input('CHANGED-'.$suffix, $branchA->id)),
        'Employee code remains immutable after creation', 'http409');
    $expect(fn () => $actions->save($manager->id, $employeeId, 2, $input('EMP-'.$suffix, $branchA->id, ['branch_id' => $branchB->id])),
        'Employee branch remains immutable after creation', 'http409');
    $check((int) DB::table('organization_employees')->where('id', $employeeId)->value('version') === 2
        && DB::table('audit_entries')->where('source_type', 'employee')->where('source_id', $employeeId)->count() === $auditCount,
        'Rejected stale or identity-changing updates preserve the row and write no audit event');
    $updateAudit = DB::table('audit_entries')->where('source_type', 'employee')->where('source_id', $employeeId)->where('action', 'updated')->first();
    $check($updateAudit && (int) $updateAudit->actor_id === $manager->id
        && (int) json_decode((string) $updateAudit->safe_before, true)['version'] === 1
        && (int) json_decode((string) $updateAudit->safe_after, true)['version'] === 2,
        'Update audit captures before/after versions and the acting user');

    $expect(fn () => $actions->save($manager->id, null, 0, $input('EMP-LINK-'.$suffix, $branchA->id, ['user_id' => $linked->id])),
        'Linking a login account requires separate user-view authority', 'forbidden');
    $grant($manager, 'M01.user_view', $branchA->id);
    $linkedEmployee = $actions->save($manager->id, null, 0, $input('EMP-LINK-'.$suffix, $branchA->id, ['user_id' => $linked->id]));
    $check((int) DB::table('organization_employees')->where('id', $linkedEmployee)->value('user_id') === $linked->id
        && User::findOrFail($linked->id)->active && User::findOrFail($linked->id)->email_verified_at !== null
        && ! DB::table('user_roles')->where('user_id', $linked->id)->exists(),
        'Linking an employee preserves account state and grants no login role or authority');

    $inactiveEmployee = $actions->save($manager->id, null, 0, $input('EMP-OFF-'.$suffix, $branchA->id, ['active' => false]));
    $expect(fn () => $api->employee($viewer->id, $inactiveEmployee, $branchA->id, '2026-01-01'), 'Inactive employee cannot be resolved for service work', 'validation');
    $expect(fn () => $actions->save($manager->id, null, 0, $input('EMP-DATES-'.$suffix, $branchA->id,
        ['starts_on' => '2026-05-01', 'ends_on' => '2026-04-30'])), 'Employment end cannot precede its start', 'field', 'ends_on');

    $allBeforeRollback = $counts();
    DB::rollBack();
    $afterRollback = $counts();
    $check($afterRollback === $before, 'Employee, audit, branch, account, and grant fixtures all roll back');
    fwrite(STDOUT, 'RESULT all_pass=true assertions='.$passes.' rollback=true fixtures_before='.$before[0].','.implode(',', array_slice($before, 1))
        .' fixtures_after='.$afterRollback[0].','.implode(',', array_slice($afterRollback, 1)).PHP_EOL);
} catch (Throwable $error) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, 'FAIL '.$error::class.': '.$error->getMessage().' assertions='.$passes.PHP_EOL);
    exit(1);
}
