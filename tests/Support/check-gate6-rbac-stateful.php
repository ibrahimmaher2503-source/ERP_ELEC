<?php

declare(strict_types=1);

// Gate 6 durable RBAC state matrix. Mutations are limited to a guarded transaction.
use App\Models\User;
use App\Modules\Attendance\Application\AttendanceReports;
use App\Modules\Inventory\Domain\Warehouse;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\EmployeeActions;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Parties\Domain\Customer;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use App\Modules\ServiceContracts\Application\ServiceContractReport;
use App\Modules\Treasury\Application\TreasuryAccess;
use App\Modules\Treasury\Application\TreasuryService;
use App\Modules\Treasury\Application\TreasuryStatementActions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 2);
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';

$environment = ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
$target = OptimizationRegressionDatabaseGuard::target($root, $environment);
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'], 'DB_PORT' => (string) $target['port'],
    'DB_DATABASE' => $target['database'], 'DB_USERNAME' => $target['username'],
    'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''), 'DB_URL' => '', 'APP_DEBUG' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $target['connection_host'],
    'database.connections.pgsql.port' => $target['port'], 'database.connections.pgsql.database' => $target['database'],
    'database.connections.pgsql.username' => $target['username'], 'database.connections.pgsql.password' => (string) (getenv('DB_PASSWORD') ?: ''),
    'database.connections.pgsql.url' => null]);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address,
    inet_server_port() as port, current_setting('data_directory') as data_directory, current_user as current_user,
    current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
    throw new RuntimeException('Gate 6 state matrix requires the owned PostgreSQL 17 target.');
}

$requiredTables = ['users', 'companies', 'branches', 'roles', 'role_permissions', 'user_roles', 'user_branch_scopes',
    'user_warehouse_scopes', 'access_grants', 'permission_catalog', 'inventory_warehouses', 'customers',
    'organization_employees', 'service_contracts', 'service_contract_revisions', 'service_contract_activities', 'service_contract_documents',
    'attendance_shifts', 'attendance_schedules', 'attendance_punches', 'attendance_adjustments', 'attendance_timesheets',
    'attendance_requests', 'accounting_accounts', 'treasury_accounts', 'treasury_opening_balances', 'treasury_movements',
    'treasury_statements', 'treasury_statement_lines', 'treasury_statement_matches', 'treasury_statement_reviews',
    'accounting_journals', 'rp_customer_advances', 'rp_customer_advance_applications', 'rp_customer_advance_application_lines',
    'rp_customer_advance_refunds', 'access_audits'];
foreach ($requiredTables as $table) {
    if (! DB::getSchemaBuilder()->hasTable($table)) {
        throw new RuntimeException("Gate 6 requires migrated {$table}.");
    }
}
if (! DB::table('roles')->where('code', 'Administrator')->exists()) {
    throw new RuntimeException('Gate 6 requires the explicitly named Administrator role.');
}

$tables = array_merge($requiredTables, ['audit_entries']);
$counts = static function () use ($tables): array {
    $result = [];
    foreach ($tables as $table) {
        $result[$table] = (int) DB::table($table)->count();
    }

    return $result;
};
$checks = [];
$assertions = 0;
$check = static function (bool $ok, string $name) use (&$checks, &$assertions): void {
    $checks[] = ['name' => $name, 'result' => $ok ? 'PASS' : 'FAIL'];
    if (! $ok) {
        throw new RuntimeException('FAIL '.$name);
    }
    $assertions++;
    fwrite(STDOUT, 'PASS '.$name.PHP_EOL);
};
$denied = static function (callable $action): bool {
    try {
        $action();
    } catch (HttpException $error) {
        return $error->getStatusCode() === 403;
    } catch (ContractException $error) {
        return $error->getCode() === 403;
    }

    return false;
};
$before = $counts();
$auditHistoryBefore = ['audit_entries' => (int) DB::table('audit_entries')->count(), 'access_audits' => (int) DB::table('access_audits')->count()];
$companiesBefore = DB::table('companies')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
$failure = null;
$after = [];
$companiesAfter = [];
$privateFixturePaths = [];
$privateFixturesCleaned = false;
DB::beginTransaction();
try {
    $tag = strtoupper(bin2hex(random_bytes(5)));
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create();
    }
    $branchA = Branch::factory()->create(['company_id' => 1, 'code' => 'G6-A-'.$tag]);
    $branchB = Branch::factory()->create(['company_id' => 1, 'code' => 'G6-B-'.$tag]);
    $warehouseA = Warehouse::factory()->create(['branch_id' => $branchA->id, 'code' => 'G6-WA-'.$tag]);
    $warehouseB = Warehouse::factory()->create(['branch_id' => $branchB->id, 'code' => 'G6-WB-'.$tag]);
    $ordinary = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $disabledOrdinary = User::factory()->create(['active' => false, 'email_verified_at' => now()]);
    $administrator = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $disabledAdministrator = User::factory()->create(['active' => false, 'email_verified_at' => now()]);
    $adminRoleId = (int) DB::table('roles')->where('code', 'Administrator')->value('id');
    foreach ([[$administrator, $adminRoleId], [$disabledAdministrator, $adminRoleId]] as [$actor, $assignedRole]) {
        DB::table('user_roles')->insert(['user_id' => $actor->id, 'role_id' => $assignedRole,
            'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ([$ordinary, $disabledOrdinary, $administrator, $disabledAdministrator] as $actor) {
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branchA->id,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_warehouse_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branchA->id,
            'warehouse_id' => $warehouseA->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M10.contract_create', 'M10.contract_edit', 'M10.contract_view', 'M10.reports_view', 'M06.balance_view',
        'M01.employee_manage', 'M01.employee_view', 'M15.view', 'M15.export', 'M11.report_view', 'M13.statement_view',
        'M12.advance_apply'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $ordinary->id, 'permission_code' => $permission,
            'branch_id' => $branchA->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M10.contract_create', 'M15.view'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $disabledOrdinary->id, 'permission_code' => $permission,
            'branch_id' => $branchA->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('access_grants')->insert(['user_id' => $ordinary->id, 'permission_code' => 'M01.employee_view',
        'branch_id' => $branchB->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('access_grants')->insert(['user_id' => $ordinary->id, 'permission_code' => 'M13.account_view',
        'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    $access = app(PermissionAccess::class);
    $refresh = static fn () => PermissionAccess::forgetRequestSnapshot();
    $refresh();
    $check($access->allows($ordinary, 'M10.contract_view', (int) $branchA->id), 'Manual M10 grant works only in scoped branch A.');
    $check(! $access->allows($ordinary, 'M10.contract_view', (int) $branchB->id), 'Manual branch-A grant cannot authorize branch B.');
    $check(! $access->roleCapability((int) $ordinary->id, 'M15.export'), 'Manual M10/M06 grants do not imply M15 role capability.');
    $check($access->allows($ordinary, 'M06.balance_view', (int) $branchA->id, (int) $warehouseA->id), 'Ordinary role can view its explicitly scoped branch A warehouse.');
    $check(! $access->allows($ordinary, 'M06.balance_view', (int) $branchB->id, (int) $warehouseB->id), 'Ordinary role cannot view branch B warehouse.');
    $check(! $access->allows($ordinary, 'M06.balance_view', (int) $branchA->id, (int) $warehouseB->id), 'Warehouse access rejects a mismatched branch and warehouse pair.');
    $check($access->roleCapability((int) $administrator->id, 'M15.export'), 'Actual Administrator membership inherits current M15 export capability.');
    $check($access->allows($administrator, 'M10.contract_view', (int) $branchA->id), 'Administrator branch authority still requires a branch scope.');
    $check($access->allows($administrator, 'M06.balance_view', (int) $branchA->id, (int) $warehouseA->id), 'Administrator warehouse access still requires a matching warehouse scope.');
    $check(! $access->allows($disabledAdministrator, 'M15.view', (int) $branchA->id), 'Disabled Administrator account receives no effective branch permission.');

    $employees = app(EmployeeActions::class);
    $check($denied(fn () => $employees->save((int) $ordinary->id, null, 1, ['code' => 'G6-EMP-DENIED', 'name' => 'موظف اختبار', 'branch_id' => (int) $branchB->id,
        'user_id' => null, 'starts_on' => now()->toDateString(), 'ends_on' => null, 'active' => true, 'job_title' => null])),
        'Employee Action denies creating an employee outside the actor branch scope.');
    $employeeA = Employee::factory()->create(['branch_id' => $branchA->id]);
    $employeeB = Employee::factory()->create(['branch_id' => $branchB->id]);
    $attendance = app(AttendanceReports::class);
    $month = now('Africa/Cairo')->format('Y-m');
    $timesheet = $attendance->timesheet((int) $ordinary->id, (int) $employeeA->id, $month);
    $check($timesheet['employee_id'] === (int) $employeeA->id && $timesheet['branch_id'] === (int) $branchA->id && $timesheet['days'] === [], 'M15 report reads only the explicitly scoped employee and creates no attendance facts.');
    $check($denied(fn () => $attendance->timesheet((int) $ordinary->id, (int) $employeeB->id, $month)), 'M15 report denies an employee in an unscoped branch.');
    $check($denied(fn () => $attendance->timesheet((int) $disabledAdministrator->id, (int) $employeeA->id, $month)), 'M15 report denies disabled Administrator membership.');
    $check($denied(fn () => $attendance->timesheet((int) $disabledOrdinary->id, (int) $employeeA->id, $month)), 'M15 report denies a disabled ordinary account despite a direct grant.');

    $actions = app(ServiceContractActions::class);
    $contractsBeforeDeniedActions = (int) DB::table('service_contracts')->count();
    $key = (string) Str::uuid();
    $check($denied(fn () => $actions->save((int) $ordinary->id, null, 1, $key, ['branch_id' => (int) $branchB->id])), 'Real contract-create Action denies an out-of-scope branch.');
    $check($denied(fn () => $actions->save((int) $disabledAdministrator->id, null, 1, (string) Str::uuid(), ['branch_id' => (int) $branchA->id])), 'Real contract-create Action denies a disabled Administrator account.');
    $check($denied(fn () => $actions->save((int) $disabledOrdinary->id, null, 1, (string) Str::uuid(), ['branch_id' => (int) $branchA->id])), 'Real contract-create Action denies a disabled ordinary account despite a direct grant.');
    $check((int) DB::table('service_contracts')->count() === $contractsBeforeDeniedActions, 'Denied contract Actions create no business records.');

    $customer = Customer::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branchB->id]);
    $objectKey = (string) Str::uuid();
    $contractId = DB::table('service_contracts')->insertGetId(['creation_key' => $objectKey, 'branch_id' => $branchB->id,
        'customer_id' => $customer->id, 'responsible_employee_id' => $employee->id, 'created_by' => $ordinary->id]);
    $revisionId = DB::table('service_contract_revisions')->insertGetId(['contract_id' => $contractId, 'revision' => 1,
        'creation_key' => $objectKey, 'data' => '{}', 'created_by' => $ordinary->id]);
    DB::table('service_contracts')->where('id', $contractId)->update(['current_revision_id' => $revisionId]);
    $check($denied(fn () => $actions->submit((int) $ordinary->id, (int) $contractId, 1)), 'Real contract Action denies an existing object outside the actor branch scope.');
    $check($denied(fn () => app(ServiceContractReport::class)->show((int) $ordinary->id, (int) $contractId)), 'M10 report denies an existing out-of-scope contract object.');

    $dashboard = app(TreasuryService::class);
    $summary = $dashboard->executiveSummary((int) $ordinary->id, (int) $branchA->id, $month.'-01', now('Africa/Cairo')->toDateString());
    $check($summary['scope'] === 'authorized_branches' && array_keys($summary['metrics']) === ['bank', 'cash'], 'Treasury executive summary returns the explicitly authorized branch scope.');
    $check($denied(fn () => $dashboard->executiveSummary((int) $ordinary->id, (int) $branchB->id, $month.'-01', now('Africa/Cairo')->toDateString())), 'Treasury executive summary denies an unscoped branch.');

    $account = DB::table('treasury_accounts')->where('kind', 'bank')->where('active', true)->first();
    if (! $account) {
        $gl = DB::table('accounting_accounts')->insertGetId(['code' => 'G6-BANK-'.$tag, 'name' => 'حساب بنكي اصطناعي لفحص الوصول', 'kind' => 'asset', 'postable' => true, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $accountId = DB::table('treasury_accounts')->insertGetId(['code' => 'G6-BANK-'.$tag, 'name' => 'حساب بنكي اصطناعي لفحص الوصول', 'kind' => 'bank', 'gl_account_id' => $gl, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    } else {
        $accountId = (int) $account->id;
    }
    $privateFixturePath = 'treasury-statements/gate6-'.$tag.'.csv';
    $privateFixturePaths[] = $privateFixturePath;
    Storage::disk('local')->put($privateFixturePath, "synthetic rollback-only bank statement fixture\n");
    $statementHash = hash_file('sha256', Storage::disk('local')->path($privateFixturePath));
    $statementId = DB::table('treasury_statements')->insertGetId(['creation_key' => (string) Str::uuid(), 'branch_id' => $branchA->id,
        'account_id' => $accountId, 'external_reference' => 'G6-'.$tag, 'starts_on' => $month.'-01', 'ends_on' => now('Africa/Cairo')->toDateString(),
        'opening_balance' => '0.00', 'closing_balance' => '0.00', 'file_path' => $privateFixturePath, 'file_sha256' => $statementHash,
        'original_name' => 'gate6.csv', 'payload_hash' => hash('sha256', $tag), 'created_by' => $ordinary->id]);
    $statementActions = app(TreasuryStatementActions::class);
    $privateDownload = $statementActions->download((int) $ordinary->id, $statementId);
    $cacheDirectives = array_map('trim', explode(',', strtolower((string) $privateDownload->headers->get('Cache-Control'))));
    $check(in_array('private', $cacheDirectives, true) && in_array('no-store', $cacheDirectives, true) && in_array('max-age=0', $cacheDirectives, true)
        && strtolower((string) $privateDownload->headers->get('X-Content-Type-Options')) === 'nosniff'
        && hash_equals($statementHash, hash_file('sha256', $privateDownload->getFile()->getPathname())),
        'Authorized Treasury statement download is private/no-store and serves the unchanged source file hash.');
    $documentPath = 'service-contracts/'.$contractId.'/revisions/'.$revisionId.'/gate6-'.$tag.'.txt';
    $privateFixturePaths[] = $documentPath;
    Storage::disk('local')->put($documentPath, "synthetic rollback-only contract evidence fixture\n");
    $documentId = DB::table('service_contract_documents')->insertGetId(['contract_id' => $contractId, 'revision_id' => $revisionId,
        'classification' => 'supporting', 'reference' => 'G6-'.$tag, 'path' => $documentPath, 'original_name' => 'gate6.txt', 'mime' => 'text/plain',
        'sha256' => hash_file('sha256', Storage::disk('local')->path($documentPath)), 'terms_sha256' => hash('sha256', '{}'), 'uploaded_by' => $ordinary->id]);
    $check($denied(fn () => app(ServiceContractDocuments::class)->download((int) $ordinary->id, $documentId)), 'M10 private document download denies an existing contract object outside branch scope.');
    $check($denied(fn () => $statementActions->download((int) $disabledAdministrator->id, $statementId)), 'Treasury statement download denies a disabled Administrator account.');
    $check($access->allows($ordinary, 'M12.advance_apply', (int) $branchA->id), 'M12 customer-advance application uses its explicit branch grant.');
    $check(! app(TreasuryAccess::class)->allows((int) $ordinary->id, 'advance_refund', (int) $branchA->id), 'Statement and M12 advance-apply grants do not imply Treasury advance-refund permission.');
    $check($denied(fn () => $dashboard->advanceRefundAccounts((int) $ordinary->id, (int) $branchA->id)), 'Treasury customer-advance refund lookup denies before exposing account choices without its permission.');

    DB::table('user_branch_scopes')->where('user_id', $ordinary->id)->where('branch_id', $branchA->id)->delete();
    $refresh();
    $check(! isset($access->scopedBranches((int) $ordinary->id)[$branchA->id]), 'Revoked branch scope disappears from the next authorization snapshot.');
    $check($denied(fn () => $actions->save((int) $ordinary->id, null, 1, (string) Str::uuid(), ['branch_id' => (int) $branchA->id])), 'M10 Action enforces branch membership after scope revocation despite a direct grant.');
    $check($denied(fn () => $attendance->timesheet((int) $ordinary->id, (int) $employeeA->id, $month)), 'M15 report enforces branch membership after scope revocation despite a direct grant.');

    DB::table('access_grants')->where('user_id', $ordinary->id)->where('permission_code', 'M10.contract_create')->delete();
    $refresh();
    $check(! $access->allows($ordinary, 'M10.contract_create', (int) $branchA->id), 'Revoking the manual grant removes its permission on the next authorization snapshot.');
    $check($denied(fn () => $actions->save((int) $ordinary->id, null, 1, (string) Str::uuid(), ['branch_id' => (int) $branchA->id])), 'Real contract-create Action denies after its manual grant is revoked.');
    DB::table('access_grants')->where('user_id', $ordinary->id)->where('permission_code', 'M13.statement_view')->delete();
    $refresh();
    $check($denied(fn () => $statementActions->download((int) $ordinary->id, $statementId)), 'Treasury private download denies after its manual grant is revoked.');
    $auditHistoryAfterDeniedReads = ['audit_entries' => (int) DB::table('audit_entries')->count(), 'access_audits' => (int) DB::table('access_audits')->count()];
    $check($auditHistoryAfterDeniedReads === $auditHistoryBefore, 'Denied employee, contract, attendance and Treasury operations add no success/security audit history.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    DB::rollBack();
    $after = $counts();
    $companiesAfter = DB::table('companies')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    foreach ($privateFixturePaths as $path) {
        Storage::disk('local')->delete($path);
    }
    $privateFixturesCleaned = collect($privateFixturePaths)->every(fn ($path) => ! Storage::disk('local')->exists($path));
}
$rollbackOk = $before === $after && $companiesBefore === $companiesAfter && $privateFixturesCleaned;
$checks[] = ['name' => 'All synthetic company, RBAC and workflow rows roll back; company data and private fixtures are restored.', 'result' => $rollbackOk ? 'PASS' : 'FAIL'];
$assertions++;
if (! $rollbackOk && $failure === null) {
    $failure = new RuntimeException('Gate 6 fixtures remained after rollback.');
}
$receipt = ['all_pass' => $failure === null, 'phase' => 'Gate 6 guarded stateful RBAC behavior', 'assertions' => $assertions,
    'database' => ['name' => $identity->database, 'server_address' => $identity->server_address, 'port' => (int) $identity->port,
        'postgres_major' => intdiv((int) $identity->server_version_num, 10000)],
    'counts' => ['before' => $before, 'after_rollback' => $after], 'private_fixtures_cleaned' => $privateFixturesCleaned, 'checks' => $checks,
    'failure' => $failure === null ? null : ['class' => $failure::class, 'message' => $failure->getMessage()]];
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate6';
if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
    throw new RuntimeException('Unable to create Gate 6 evidence directory.');
}
$evidencePath = $evidenceDirectory.'/rbac-stateful-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json';
file_put_contents($evidencePath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
fwrite(($failure === null ? STDOUT : STDERR), 'RESULT gate=6 scope=stateful-rbac result='.($failure === null ? 'PASS' : 'FAIL')
    .' assertions='.$assertions.' rollback_verified='.($rollbackOk ? 'yes' : 'no').' evidence='.$evidencePath.PHP_EOL);
fwrite(($failure === null ? STDOUT : STDERR), json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
if ($failure !== null) {
    exit(1);
}
