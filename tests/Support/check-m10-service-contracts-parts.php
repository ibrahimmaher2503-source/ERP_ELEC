<?php

declare(strict_types=1);

// M10 part entitlements from real M06 inventory and M08 issue/usage owner actions. Fixtures roll back.
use App\Application\ActivateFinancialPosting;
use App\Application\PostPurchaseReceipt;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\FieldMaintenance\Application\MaintenanceParts;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use App\Modules\WorkshopRepairs\Application\RepairOffers;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use App\Modules\WorkshopRepairs\Application\WorkshopExecution;
use App\Modules\WorkshopRepairs\Application\WorkshopParts;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$root = dirname(__DIR__, 2);
$stage = 'source preflight';
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate2';
$emit = static function (array $receipt) use ($evidenceDirectory): string {
    if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
        throw new RuntimeException('Unable to create Gate2 evidence directory.');
    }
    $path = $evidenceDirectory.'/m10-parts-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);

    return $path;
};
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$runtimeTag = bin2hex(random_bytes(6));
$diagnosticPath = $taskRoot.'/m10-parts-diagnostic-'.$runtimeTag.'.json';
$writeDiagnostic = static function (Throwable $error) use (&$stage, $diagnosticPath, $root): array {
    $frames = [];
    foreach (array_slice($error->getTrace(), 0, 24) as $frame) {
        $file = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : null;
        if ($file !== null && str_starts_with(strtolower($file), strtolower(str_replace('\\', '/', $root).'/'))) {
            $file = substr($file, strlen(str_replace('\\', '/', $root)) + 1);
        } elseif ($file !== null) {
            $file = basename($file);
        }
        $frames[] = ['file' => $file, 'line' => $frame['line'] ?? null, 'class' => $frame['class'] ?? null,
            'function' => $frame['function'] ?? null];
    }
    $diagnostic = ['phase' => $stage, 'exception' => $error::class, 'source_file' => $error->getFile(), 'source_line' => $error->getLine(),
        'trace_without_arguments' => $frames];
    if (! is_dir(dirname($diagnosticPath))) {
        mkdir(dirname($diagnosticPath), 0700, true);
    }
    file_put_contents($diagnosticPath, json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    @chmod($diagnosticPath, 0600);

    return ['diagnostic_file' => basename($diagnosticPath), 'source_file' => basename($error->getFile()), 'source_line' => $error->getLine(),
        'trace_frames' => $frames];
};
set_exception_handler(static function (Throwable $error) use (&$stage, $emit, $writeDiagnostic): void {
    $message = preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $error->getMessage());
    $receipt = ['all_pass' => false, 'mode' => 'm10-m06-m08-parts-entitlements', 'phase' => $stage, 'assertions' => 0,
        'rollback_verified' => false, 'checks' => [], 'failure' => ['class' => $error::class, 'message' => mb_substr((string) $message, 0, 500)] + $writeDiagnostic($error)];
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
    $root.'/database/migrations/2026_11_01_205000_track_contract_work_usage.php',
    $root.'/database/migrations/2026_11_01_207000_separate_contract_source_identity_guards.php',
    $root.'/app/Modules/ServiceContracts/Application/ServiceContractEntitlements.php',
    $root.'/app/Modules/FieldMaintenance/Application/MaintenanceParts.php',
    $root.'/app/Modules/Inventory/Application/InventoryActions.php',
];
foreach ($required as $file) {
    if (! is_file($file)) {
        throw new RuntimeException('Required M10 parts source is missing: '.basename($file));
    }
}

$environment = ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $environment);
$privateRoot = $taskRoot.'/m10-parts-private-'.$runtimeTag;
$appStorage = $taskRoot.'/m10-parts-storage-'.$runtimeTag;
$cacheRelative = '.tmp/master-remediation-20261101/m10-parts-cache-'.$runtimeTag;
$viewsRelative = '.tmp/master-remediation-20261101/m10-parts-views-'.$runtimeTag;
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'], 'DB_PORT' => (string) $target['port'],
    'DB_DATABASE' => $target['database'], 'DB_USERNAME' => $target['username'], 'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''),
    'DB_URL' => '', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'LARAVEL_STORAGE_PATH' => $appStorage, 'APP_CONFIG_CACHE' => $cacheRelative.'/config.php',
    'APP_ROUTES_CACHE' => $cacheRelative.'/routes.php', 'APP_EVENTS_CACHE' => $cacheRelative.'/events.php',
    'APP_SERVICES_CACHE' => $cacheRelative.'/services.php', 'APP_PACKAGES_CACHE' => $cacheRelative.'/packages.php',
    'APP_VIEW_COMPILED_PATH' => $viewsRelative, 'VIEW_COMPILED_PATH' => $viewsRelative,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach ([$privateRoot, $appStorage, $taskRoot.'/m10-parts-cache-'.$runtimeTag, $taskRoot.'/m10-parts-views-'.$runtimeTag] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create isolated M10 parts-check runtime directory.');
    }
}
$stage = 'Laravel bootstrap and exact PostgreSQL guard';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($appStorage);
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $target['connection_host'],
    'database.connections.pgsql.port' => $target['port'], 'database.connections.pgsql.database' => $target['database'],
    'database.connections.pgsql.username' => $target['username'], 'database.connections.pgsql.password' => (string) (getenv('DB_PASSWORD') ?: ''),
    'database.connections.pgsql.url' => null, 'filesystems.disks.local.root' => $privateRoot,
    'filesystems.disks.local.visibility' => 'private', 'filesystems.disks.local.throw' => true,
    'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
register_shutdown_function(static function () use ($taskRoot, $privateRoot, $appStorage, $runtimeTag): void {
    foreach ([$privateRoot, $appStorage, $taskRoot.'/m10-parts-cache-'.$runtimeTag, $taskRoot.'/m10-parts-views-'.$runtimeTag] as $path) {
        if (is_dir($path) && realpath(dirname($path)) === realpath($taskRoot) && str_starts_with(basename($path), 'm10-parts-')) {
            (new Illuminate\Filesystem\Filesystem)->deleteDirectory($path);
        }
    }
});
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
$requiredMigrations = ['2026_10_22_130000_create_inventory_foundation', '2026_10_22_144000_create_purchasing_orders',
    '2026_10_22_145000_create_purchase_receiving_coordination', '2026_10_27_155000_create_financial_activation',
    '2026_10_27_157000_create_opening_contributors_and_fifo', '2026_10_27_158000_harden_financial_activation',
    '2026_10_27_159000_guard_scheduled_go_live', '2026_10_30_182000_create_field_maintenance',
    '2026_11_01_201000_create_organization_employees', '2026_11_01_202000_create_service_contracts',
    '2026_11_01_203000_link_service_contract_work', '2026_11_01_205000_track_contract_work_usage',
    '2026_11_01_207000_separate_contract_source_identity_guards', '2026_11_01_212000_link_serial_receipts_and_fifo_allocations',
    '2026_11_01_213000_link_serial_openings_and_receipts', '2026_11_01_221000_link_service_part_serial_usage_and_returns'];
if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000
    || DB::table('migrations')->whereIn('migration', $requiredMigrations)->count() !== count($requiredMigrations)) {
    throw new RuntimeException('M10 parts acceptance requires the owned PostgreSQL17 target and M01/M10/M08 source migrations.');
}

$checks = [];
$assertions = 0;
$pass = static function (bool $condition, string $name) use (&$assertions, &$checks): void {
    $assertions++;
    $checks[] = ['name' => $name, 'result' => $condition ? 'PASS' : 'FAIL'];
    if (! $condition) {
        throw new RuntimeException($name);
    }
    fwrite(STDOUT, 'PASS '.$name.PHP_EOL);
};
$reject = static function (callable $action, string $name, ?int $status = null) use ($pass): void {
    try {
        $action();
    } catch (Throwable $error) {
        $denied = $error instanceof ValidationException || ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404, 409, 422], true));
        if (! $denied || ($status !== null && (! $error instanceof HttpExceptionInterface || $error->getStatusCode() !== $status))) {
            throw $error;
        }
        $pass(true, $name);

        return;
    }
    $pass(false, $name.' unexpectedly succeeded');
};
$tables = ['companies', 'branches', 'users', 'customers', 'suppliers', 'products', 'organization_employees', 'accounting_activation',
    'accounting_accounts', 'accounting_account_mappings', 'accounting_tax_rules', 'accounting_periods', 'accounting_opening_batches',
    'accounting_opening_lines', 'accounting_journals', 'accounting_journal_lines', 'accounting_project_dimensions', 'system_official_documents',
    'system_official_sequences', 'inventory_warehouses', 'user_warehouse_scopes', 'inventory_movements', 'inventory_reservations', 'inventory_reservation_events', 'inventory_balances',
    'inventory_fifo_layers', 'inventory_fifo_allocations', 'inventory_fifo_corrections', 'inventory_fifo_serial_allocations',
    'inventory_corrections', 'inventory_movement_journals', 'purchasing_orders', 'purchasing_order_lines', 'purchasing_order_issues',
    'purchasing_receipts', 'purchasing_receipt_lines', 'purchasing_receipt_postings', 'maintenance_work_orders', 'maintenance_visits',
    'maintenance_assignments', 'maintenance_labor_entries', 'maintenance_activities', 'maintenance_checklist_templates', 'maintenance_checklist_results',
    'maintenance_part_requirements', 'maintenance_part_reservations', 'maintenance_part_issues', 'maintenance_part_usage',
    'service_contracts', 'service_contract_revisions', 'service_contract_documents', 'service_contract_activities', 'service_contract_work',
    'service_contract_work_parts', 'inventory_serials', 'inventory_serial_events',
    'maintenance_part_returns', 'maintenance_part_serial_usage', 'workshop_part_returns', 'workshop_part_serial_usage',
    'workshops', 'workshop_members', 'workshop_intakes', 'workshop_custody_events', 'workshop_jobs', 'workshop_activities',
    'workshop_nonbillable_authorizations', 'workshop_part_requirements', 'workshop_part_reservations', 'workshop_part_issues',
    'workshop_part_usage', 'workshop_work_records', 'workshop_labor_entries', 'inventory_isolations',
    'access_grants', 'user_branch_scopes', 'user_roles', 'audit_entries'];
$counts = static function () use ($tables): array {
    $result = [];
    foreach ($tables as $table) {
        if (DB::getSchemaBuilder()->hasTable($table)) {
            $result[$table] = (int) DB::table($table)->count();
        }
    }

    return $result;
};
$before = $counts();
$companyBaseline = DB::table('companies')->where('id', 1)->first();
$companyBaseline = $companyBaseline ? (array) $companyBaseline : null;
$fixtureCounts = $before;
$after = [];
$failure = null;
$rollbackVerified = false;
$scratchClean = false;
$companyRollbackVerified = false;
$today = now('Africa/Cairo')->toDateString();
$date = $today;
$uuid = static fn (): string => (string) Str::uuid();
$grant = static function (User $user, array $codes, ?int $branch = null): void {
    foreach ($codes as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $user->id, 'permission_code' => $code, 'branch_id' => $branch,
            'created_at' => now(), 'updated_at' => now()]);
    }
};
DB::beginTransaction();
try {
    $stage = 'synthetic inventory, active contract, and source part usage';
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $approver = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $tech = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create(['id' => 1, 'legal_name' => 'Synthetic M10 parts acceptance entity',
            'address' => 'Rollback-only synthetic acceptance address', 'setup_complete' => true]);
    } else {
        $company = DB::table('companies')->where('id', 1)->first();
        if (blank($company->legal_name) || blank($company->address)) {
            DB::table('companies')->where('id', 1)->update(['legal_name' => 'Synthetic M10 parts acceptance entity',
                'address' => 'Rollback-only synthetic acceptance address', 'setup_complete' => true, 'updated_at' => now()]);
        }
    }
    $tag = 'DEMO-EG-M10P-'.Str::upper(Str::random(6));
    $branch = Branch::factory()->create(['code' => $tag, 'company_id' => 1]);
    $customer = Customer::factory()->create(['status' => 'active']);
    $supplier = Supplier::factory()->create(['status' => 'active']);
    $coveredPart = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $excludedPart = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $serialPart = Product::factory()->create(['kind' => 'serialized_equipment', 'tracking' => 'serial', 'active' => true]);
    $companyEquipment = Product::factory()->create(['kind' => 'serialized_equipment', 'tracking' => 'serial', 'active' => true]);
    $workshopSerialPart = Product::factory()->create(['kind' => 'serialized_equipment', 'tracking' => 'serial', 'active' => true]);
    $serviceProduct = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    foreach ([$maker, $approver, $tech, $reviewer] as $user) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant($maker, ['M01.employee_manage', 'M01.employee_view', 'M03.customer_view', 'M03.supplier_view', 'M02.product_view',
        'M11.setup_manage', 'M11.account_manage', 'M11.tax_manage', 'M11.period_open', 'M11.opening_manage', 'M11.tax_calculate',
        'M11.source_post', 'M11.journal_create', 'M11.journal_submit', 'M11.journal_post', 'M11.journal_approve', 'M11.journal_view',
        'M05.order_view', 'M05.order_create', 'M05.order_submit', 'M05.order_approve', 'M05.order_issue', 'M05.receipt_view',
        'M05.receipt_create', 'M05.receipt_post', 'M06.receipt_create', 'M06.receipt_post', 'M06.reservation_manage',
        'M06.reservation_view', 'M06.issue_create', 'M06.issue_post', 'M06.issue_approve', 'M06.movement_view', 'M06.balance_view',
        'M01.branch_view', 'M10.contract_create', 'M10.contract_edit', 'M10.contract_sign', 'M10.contract_activate', 'M10.coverage_use', 'M10.document_manage',
        'M08.workorder_manage', 'M08.standalone_create', 'M08.assign', 'M08.checklist_manage', 'M08.parts_request', 'M08.parts_issue',
        'M08.parts_return', 'M08.labor_record', 'M08.verify', 'M08.view', 'M06.correction_execute', 'M06.isolation_manage'], $branch->id);
    $grant($approver, ['M11.tax_calculate', 'M11.source_post', 'M11.receipt_post', 'M11.opening_approve', 'M11.financial_activate',
        'M08.verify', 'M08.view', 'M01.employee_view', 'M10.contract_view', 'M10.contract_approve', 'M10.contract_activate',
        'M10.coverage_use', 'M03.customer_view', 'M02.product_view', 'M05.order_view', 'M05.order_approve', 'M05.order_issue',
        'M05.receipt_view', 'M05.receipt_post', 'M06.receipt_post'], $branch->id);
    $grant($approver, ['M03.customer_view', 'M02.product_view', 'M03.supplier_view', 'M11.tax_calculate', 'M11.source_post',
        'M11.journal_create', 'M11.journal_submit', 'M11.journal_post', 'M11.journal_approve'], null);
    $grant($tech, ['M03.customer_view', 'M02.product_view', 'M06.reservation_view', 'M06.issue_create', 'M06.issue_post', 'M06.issue_approve',
        'M06.movement_view', 'M11.source_post', 'M11.journal_post', 'M08.workorder_manage', 'M08.visit_record', 'M08.labor_record',
        'M08.parts_issue', 'M08.view', 'M10.coverage_use', 'M09.execute', 'M09.assign', 'M09.labor_record', 'M09.parts_issue'], $branch->id);
    $grant($reviewer, ['M03.customer_view', 'M02.product_view', 'M01.employee_view', 'M10.contract_view', 'M10.contract_approve',
        'M10.contract_activate', 'M10.coverage_use', 'M08.view', 'M08.verify'], $branch->id);
    $grant($reviewer, ['M03.customer_view', 'M02.product_view'], null);
    $grant($maker, ['M09.manage', 'M09.assign', 'M09.intake_manage', 'M09.custody', 'M09.job_manage', 'M09.parts_request', 'M09.parts_return'], $branch->id);
    $grant($reviewer, ['M09.nonbillable_approve'], $branch->id);
    $grant($maker, ['M03.customer_view', 'M03.supplier_view', 'M02.product_view', 'M11.tax_calculate', 'M11.source_post',
        'M11.journal_create', 'M11.journal_submit', 'M11.journal_post', 'M11.journal_approve', 'M06.warehouse_create'], null);
    $grant($maker, ['M11.setup_manage', 'M11.account_manage', 'M11.tax_manage', 'M11.period_open', 'M11.opening_manage', 'M11.financial_activate'], null);
    $grant($approver, ['M05.order_view', 'M05.order_approve', 'M05.order_issue', 'M05.receipt_view', 'M05.receipt_post', 'M06.receipt_post',
        'M11.opening_approve', 'M11.financial_activate', 'M11.source_post', 'M11.journal_post'], null);
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'active' => true, 'starts_on' => now('Africa/Cairo')->subDay()->toDateString()]);
    $employee->refresh();

    // Build the M11 source posting prerequisites through their owner actions, with a rollback-only zero-balance opening.
    $activation = DB::table('accounting_activation')->where('id', 1)->first();
    $setup = app(FinancialActivation::class);
    $accounts = app(AccountingActions::class);
    $mappings = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $mappings[$purpose] = $accounts->account((int) $maker->id, null, 0, ['code' => $tag.'-'.strtoupper($purpose),
            'name' => 'Synthetic M10 parts '.$label, 'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]);
    }
    $activationVersion = (int) ($activation->version ?? 1);
    $setup->save((int) $maker->id, $activationVersion, ['go_live_date' => $today, 'mappings' => $mappings]);
    $activationVersion++;
    foreach ([['sale', null, false], ['purchase', null, true]] as [$kind, $productId, $recoverable]) {
        $setup->addTaxRule((int) $maker->id, $activationVersion, ['code' => $tag.'-TAX-'.$kind, 'name' => 'Rollback-only M10 parts '.$kind,
            'transaction_type' => $kind, 'product_id' => $productId, 'rate' => '14', 'starts_on' => $today, 'ends_on' => null,
            'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Rollback-only synthetic M10 inventory fixture; not a company tax input.',
            'purchase_recoverable' => $recoverable]);
        $activationVersion++;
    }
    $setup->validateTaxTreatment((int) $maker->id, $activationVersion, 'Rollback-only synthetic M10 inventory tax fixture.');
    $activationVersion++;
    $year = (int) substr($today, 0, 4);
    $accounts->period((int) $maker->id, ['name' => 'Synthetic M10 parts '.$year, 'starts_on' => $year.'-01-01', 'ends_on' => $year.'-12-31']);
    Carbon::setTestNow(CarbonImmutable::parse($today, 'Africa/Cairo')->startOfDay()->addHours(12));
    $opening = app(OpeningBalances::class);
    $batch = $opening->save((int) $maker->id, $activationVersion, $uuid(), [], true,
        'Explicit rollback-only synthetic no-opening-balances declaration for M10 parts acceptance.');
    $opening->advance((int) $maker->id, $batch, 1, 'submit');
    $opening->advance((int) $approver->id, $batch, 2, 'approve');
    app(ActivateFinancialPosting::class)->execute((int) $approver->id, $batch, 3);

    $stage = 'M06 company-scoped warehouse creation for real posted stock';
    $warehouse = app(InventoryActions::class)->saveWarehouse((int) $maker->id, ['code' => $tag.'-WH', 'name' => 'مخزن قبول قطع العقد',
        'branch_id' => $branch->id, 'active' => true]);
    foreach ([$maker, $approver, $tech] as $user) {
        DB::table('user_warehouse_scopes')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id,
            'created_at' => now(), 'updated_at' => now()]);
    }
    $stage = 'M06 scoped fixture authorization for receipt, balance, reservation, and issue actors';
    $inventoryAccess = app(\App\Modules\Inventory\Application\InventoryAccess::class);
    $pass($inventoryAccess->allows((int) $maker->id, 'balance_view', (int) $warehouse['id'])
        && $inventoryAccess->allows((int) $maker->id, 'reservation_manage', (int) $warehouse['id'])
        && $inventoryAccess->allows((int) $approver->id, 'receipt_post', (int) $warehouse['id'])
        && $inventoryAccess->allows((int) $tech->id, 'issue_post', (int) $warehouse['id']),
        'Each synthetic inventory actor has its explicit permission and branch/warehouse scope');
    $order = app(\App\Modules\Purchasing\Application\PurchaseOrders::class)->save((int) $maker->id, [
        'creation_key' => $uuid(), 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'order_date' => $today,
        'expected_date' => null, 'currency' => 'EGP', 'payment_terms' => 'Rollback-only synthetic test', 'notes' => 'M10 actual part source fixture',
        'tax_mode' => 'exclusive', 'reported_tax' => null,
        'lines' => [['product_id' => $coveredPart->id, 'quantity' => '8.00000000', 'unit_price' => '10.00000000', 'discount_amount' => '0.00'],
            ['product_id' => $excludedPart->id, 'quantity' => '8.00000000', 'unit_price' => '12.00000000', 'discount_amount' => '0.00'],
            ['product_id' => $serialPart->id, 'quantity' => '2.00000000', 'unit_price' => '10.00000000', 'discount_amount' => '0.00'],
            ['product_id' => $companyEquipment->id, 'quantity' => '1.00000000', 'unit_price' => '30.00000000', 'discount_amount' => '0.00'],
            ['product_id' => $workshopSerialPart->id, 'quantity' => '2.00000000', 'unit_price' => '15.00000000', 'discount_amount' => '0.00']]], null, 0);
    app(\App\Modules\Purchasing\Application\PurchaseOrders::class)->transition((int) $maker->id, $order, 1, 'submit');
    app(\App\Modules\Purchasing\Application\PurchaseOrders::class)->transition((int) $approver->id, $order, 2, 'approve');
    app(\App\Modules\Purchasing\Application\PurchaseOrders::class)->issue((int) $approver->id, $order);
    $lines = DB::table('purchasing_order_lines')->where('order_id', $order)->orderBy('id')->get();
    $receipt = app(\App\Modules\Purchasing\Application\PurchaseReceiving::class)->save((int) $maker->id, [
        'creation_key' => $uuid(), 'order_id' => $order, 'warehouse_id' => $warehouse['id'], 'receipt_date' => $today,
        'delivery_reference' => $tag.'-RECEIPT', 'notes' => 'Rollback-only received stock for M08 owner actions',
        'lines' => $lines->map(fn ($line) => ['order_line_id' => $line->id, 'quantity' => match ((int) $line->product_id) {
            (int) $serialPart->id, (int) $workshopSerialPart->id => '2.00000000', (int) $companyEquipment->id => '1.00000000', default => '8.00000000'},
            'serials' => match ((int) $line->product_id) {
                (int) $serialPart->id => [$tag.'-M08-USED-A', $tag.'-M08-UNUSED-B'],
                (int) $companyEquipment->id => [$tag.'-M09-COMPANY-EQUIP'],
                (int) $workshopSerialPart->id => [$tag.'-M09-PART-A', $tag.'-M09-PART-B'], default => []}])->all()], null, 0);
    app(\App\Modules\Purchasing\Application\PurchaseReceiving::class)->submit((int) $maker->id, $receipt, 1);
    app(PostPurchaseReceipt::class)->execute((int) $approver->id, $receipt, 2);
    $pass(DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $coveredPart->id)->where('on_hand', '8.00000000')->exists()
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $excludedPart->id)->where('on_hand', '8.00000000')->exists()
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $serialPart->id)->where('on_hand', '2.00000000')->exists()
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $companyEquipment->id)->where('on_hand', '1.00000000')->exists()
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $workshopSerialPart->id)->where('on_hand', '2.00000000')->exists()
        && DB::table('inventory_movements')->where('operation', 'purchase_receipt')->where('source_id', (string) $receipt)->count() === 5,
        'M06 quantity and serial stock exists only from posted owner purchase receipt and exact FIFO source movements');

    $today = now('Africa/Cairo')->toDateString();
    $ends = now('Africa/Cairo')->addMonths(4)->toDateString();
    $writerId = (int) $maker->id;
    $reviewerId = (int) $reviewer->id;
    $actions = app(ServiceContractActions::class);
    $terms = ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_employee_id' => $employee->id,
        'title' => 'تغطية قبول قطع الغيار الفعلية', 'starts_on' => $today, 'ends_on' => $ends, 'value' => '300.00',
        'service_product_id' => $serviceProduct->id, 'billing_mode' => 'installments', 'terms' => 'مراجعة تعاقدية اصطناعية لتغطية القطع.',
        'sla_response_minutes' => 120, 'sla_resolution_minutes' => 480, 'covered_labor_minutes' => 120,
        'coverage' => [['equipment_ref' => $tag.'-CB-001', 'site_id' => null, 'preventive_visits' => 5, 'corrective_visits' => 0]],
        'parts' => [['product_id' => $coveredPart->id, 'quantity' => '1.50000000']], 'excluded_parts' => [$excludedPart->id], 'boq' => [],
        'schedule' => [['code' => 'PART-01', 'due_date' => now('Africa/Cairo')->addDays(15)->toDateString(), 'amount' => '300.00', 'description' => 'قسط اختبار']]];
    $contract = $actions->save($writerId, null, 0, $uuid(), $terms);
    $header = DB::table('service_contracts')->find($contract);
    $actions->submit($writerId, $contract, (int) $header->version);
    $header = DB::table('service_contracts')->find($contract);
    $actions->approve($reviewerId, $contract, (int) $header->version);
    $header = DB::table('service_contracts')->find($contract);
    $signature = app(ServiceContractDocuments::class)->store($writerId, $contract,
        UploadedFile::fake()->createWithContent('m10-parts-signature.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n"), 'signature', 'توقيع اصطناعي لقبول القطع');
    $header = DB::table('service_contracts')->find($contract);
    $actions->sign($writerId, $contract, (int) $header->version, $signature, $today);
    $header = DB::table('service_contracts')->find($contract);
    $actions->activate($writerId, $contract, (int) $header->version);
    $api = app(ServiceContractsApi::class);
    $equipment = $tag.'-CB-001';
    $work = app(MaintenanceActions::class);
    $execution = app(MaintenanceExecution::class);
    $parts = app(MaintenanceParts::class);
    $template = $execution->saveTemplate($writerId, null, 0, ['branch_id' => $branch->id, 'code' => $tag.'-CHECK', 'title' => 'فحص اللوحة',
        'active' => true, 'items' => [['key' => 'safety', 'label' => 'فحص السلامة', 'required' => true]]]);
    $performSource = static function (int $number, int $productId, string $quantity, bool $consume, string $label) use (&$stage,
        $tag, $today, $branch, $customer, $maker, $tech, $writerId, $work, $execution, $parts, $warehouse, $contract, $equipment,
        $uuid, $pass, $reject
    ): array {
        $stage = 'M08 source '.$label.': create work order and link contract';
        $workId = $work->createWorkOrder($writerId, null, 0, $uuid(), ['branch_id' => $branch->id, 'customer_id' => $customer->id,
            'responsible_id' => $tech->id, 'description' => 'مصدر M08 لاستهلاك القطعة '.$label, 'priority' => 'normal', 'billable' => false,
            'acknowledgment_required' => true]);
        $workRow = DB::table('maintenance_work_orders')->find($workId);
        $work->linkContract($writerId, $workId, (int) $workRow->version, $contract, $equipment, $today, 'preventive');
        $workRow = DB::table('maintenance_work_orders')->find($workId);
        $stage = 'M08 source '.$label.': assign technician and schedule visit';
        $slot = CarbonImmutable::now('UTC')->startOfMinute()->subHours(9 - ($number * 2));
        $assignment = $execution->assign($writerId, $workId, (int) $workRow->version, ['technician_id' => $tech->id,
            'starts_at' => $slot->toIso8601String(), 'ends_at' => $slot->addMinutes(90)->toIso8601String()]);
        $workRow = DB::table('maintenance_work_orders')->find($workId);
        $visit = $execution->schedule($writerId, $workId, (int) $workRow->version, $uuid(), ['assignment_id' => $assignment,
            'scheduled_at' => $slot->addMinutes(5)->toIso8601String(), 'template_id' => DB::table('maintenance_checklist_templates')->where('code', $tag.'-CHECK')->value('id'), 'mandatory' => true]);
        $work->state((int) $tech->id, $workId, (int) DB::table('maintenance_work_orders')->find($workId)->version, 'start', 'بدء تنفيذ اختبار قبول قطع.');
        $stage = 'M06 source '.$label.': request covered-stock availability';
        $requirement = $parts->requirement($writerId, $workId, (int) DB::table('maintenance_work_orders')->find($workId)->version,
            ['product_id' => $productId, 'warehouse_id' => $warehouse['id'], 'quantity' => $quantity]);
        $stage = 'M06 source '.$label.': reserve genuine warehouse stock';
        $reservation = $parts->reserve($writerId, $workId, $requirement, $quantity, $uuid());
        $stage = 'M06 source '.$label.': issue reserved stock through authorized M08 technician';
        $issue = $parts->issue((int) $tech->id, $workId, (int) $reservation['id'], $quantity, $uuid());
        $arrival = $slot->addMinutes(10);
        $departure = $arrival->addMinutes(45);
        $stage = 'M08 source '.$label.': record authorized technician visit';
        $visitRow = DB::table('maintenance_visits')->find($visit);
        $execution->recordVisit((int) $tech->id, $workId, $visit, (int) $visitRow->version, ['arrival_at' => $arrival->toIso8601String(),
            'departure_at' => $departure->toIso8601String(), 'work_performed' => 'تم فحص اللوحة ومراجعة نقاط التوصيل.',
            'diagnosis' => 'تم توثيق حالة التوصيل.', 'observations' => 'الاختبار موثق.', 'acknowledgment' => 'استلم العميل النتيجة.',
            'outcome' => 'completed'], ['safety' => ['result' => 'complete']]);
        $workRow = DB::table('maintenance_work_orders')->find($workId);
        $ledgerBeforeUse = DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $workId)->first();
        $versionBeforeUse = (int) $workRow->version;
        $activityBeforeUse = DB::table('maintenance_activities')->where('entity_type', 'maintenance_work_order')->where('entity_id', $workId)->count();
        if ($consume) {
            $stage = 'M10 positive source '.$label.': use covered part and verify owner work';
            $parts->usage((int) $tech->id, $workId, $visit, $requirement, $quantity);
            $usage = DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $workId)->first();
            $workRow = DB::table('maintenance_work_orders')->find($workId);
            $partSnapshot = $usage ? json_decode($usage->parts_snapshot, true, 512, JSON_THROW_ON_ERROR) : [];
            $pass($usage && $usage->status === 'reserved' && (int) $usage->visits === 1 && (int) $usage->labor_minutes === 0
                && count($partSnapshot) === 1 && (int) ($partSnapshot[0]['product_id'] ?? 0) === $productId
                && is_string($partSnapshot[0]['quantity'] ?? null) && bccomp($partSnapshot[0]['quantity'], $quantity, 8) === 0
                && (int) $usage->source_version === (int) $workRow->version,
                'Allowed real M08 part use synchronizes its reserved M10 snapshot from the owner projection');
            $arrival = $arrival;
            $stage = 'M08 source '.$label.': record actual labor and independently verify';
            $execution->labor((int) $tech->id, $workId, $uuid(), ['visit_id' => $visit, 'starts_at' => $arrival->toIso8601String(),
                'ends_at' => $arrival->addMinutes(30)->toIso8601String(), 'work_type' => 'فحص كهربائي']);
            $work->state((int) $tech->id, $workId, (int) DB::table('maintenance_work_orders')->find($workId)->version, 'complete', 'إتمام العمل بعد تسوية القطع والوقت.');
            $work->state((int) $maker->id, $workId, (int) DB::table('maintenance_work_orders')->find($workId)->version, 'verify', 'تحقق مستقل من مستندات القطع والزيارة.');
            $usage = DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $workId)->first();
            $partRows = DB::table('service_contract_work_parts')->where('work_id', $usage->id)->get();
            $pass($usage->status === 'consumed' && (int) $usage->visits === 1 && (int) $usage->labor_minutes === 30
                && $partRows->count() === 1 && (int) $partRows[0]->product_id === $productId && bccomp($partRows[0]->quantity, $quantity, 8) === 0,
                'M08 independent verification consumes the real source and persists exact numeric covered-part quantity');

            return ['work_id' => $workId, 'product_id' => $productId, 'quantity' => $quantity];
        }
        $stage = 'M10 denial source '.$label.': reject excluded or over-quantity owner usage';
        $beforeFailure = ['work_version' => $versionBeforeUse, 'activities' => $activityBeforeUse,
            'usage_rows' => DB::table('maintenance_part_usage')->where('requirement_id', $requirement)->count(),
            'ledger_version' => (int) $ledgerBeforeUse->source_version, 'parts_snapshot' => $ledgerBeforeUse->parts_snapshot];
        $reject(fn () => $parts->usage((int) $tech->id, $workId, $visit, $requirement, $quantity),
            'M10 rejects '.$label.' during the actual M08 part-use action');
        $afterFailure = ['work_version' => (int) DB::table('maintenance_work_orders')->find($workId)->version,
            'activities' => DB::table('maintenance_activities')->where('entity_type', 'maintenance_work_order')->where('entity_id', $workId)->count(),
            'usage_rows' => DB::table('maintenance_part_usage')->where('requirement_id', $requirement)->count(),
            'ledger_version' => (int) DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $workId)->value('source_version'),
            'parts_snapshot' => DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $workId)->value('parts_snapshot')];
        $pass($beforeFailure === $afterFailure,
            'Rejected M08 part-use transaction leaves no usage, work-version, audit, source-version, or entitlement-snapshot delta');

        return ['work_id' => $workId, 'product_id' => $productId, 'quantity' => $quantity];
    };

    $valid = $performSource(0, (int) $coveredPart->id, '1.00000000', true, 'covered part');
    $coverage = $api->coverage($writerId, $contract, (int) $customer->id, (int) $branch->id, $equipment, null, $today, 'preventive');
    $pass($coverage['remaining_parts'] === [['product_id' => (int) $coveredPart->id, 'quantity' => '0.50000000']]
        && (int) $coverage['remaining_visits'] === 4,
        'Coverage reports the exact remaining covered part quantity after real M08 issue, use, and verification');
    $excluded = $performSource(1, (int) $excludedPart->id, '0.25000000', false, 'excluded part');
    $overLimit = $performSource(2, (int) $coveredPart->id, '0.75000000', false, 'quantity above the remaining 0.50000000 covered amount');
    $pass(DB::table('maintenance_part_usage')->where('requirement_id', DB::table('maintenance_part_requirements')->where('work_order_id', $excluded['work_id'])->value('id'))->doesntExist()
        && DB::table('maintenance_part_usage')->where('requirement_id', DB::table('maintenance_part_requirements')->where('work_order_id', $overLimit['work_id'])->value('id'))->doesntExist()
        && DB::table('service_contract_work_parts')->where('product_id', $excludedPart->id)->doesntExist()
        && DB::table('service_contract_work_parts')->where('product_id', $coveredPart->id)->where('quantity', '>', '1.00000000')->doesntExist(),
        'Excluded and over-quantity attempts create no final numeric M10 part-consumption rows');
    $pass(DB::table('inventory_movements')->where('operation', 'maintenance_issue')->whereIn('source_id', [(string) $valid['work_id'], (string) $excluded['work_id'], (string) $overLimit['work_id']])->count() === 3
        && DB::table('maintenance_part_issues')->whereIn('requirement_id', DB::table('maintenance_part_requirements')->whereIn('work_order_id', [$valid['work_id'], $excluded['work_id'], $overLimit['work_id']])->select('id'))->count() === 3,
        'All three M08 inputs are backed by genuine posted M06 stock issues even when M10 entitlement rejects use');

    $stage = 'M08 serialized source: standalone work order, posted-stock issue, recorded usage, and unused return';
    $serialWork = $work->createWorkOrder($writerId, null, 0, $uuid(), ['branch_id' => $branch->id, 'customer_id' => $customer->id,
        'responsible_id' => $tech->id, 'description' => 'استخدام وإرجاع رقم مسلسل من مخزون فعلي', 'priority' => 'normal', 'billable' => false,
        'acknowledgment_required' => true]);
    $serialWorkRow = DB::table('maintenance_work_orders')->find($serialWork);
    $serialSlot = CarbonImmutable::now('UTC')->startOfMinute()->subHours(2);
    $serialAssignment = $execution->assign($writerId, $serialWork, (int) $serialWorkRow->version, ['technician_id' => $tech->id,
        'starts_at' => $serialSlot->toIso8601String(), 'ends_at' => $serialSlot->addMinutes(90)->toIso8601String()]);
    $serialWorkRow = DB::table('maintenance_work_orders')->find($serialWork);
    $serialVisit = $execution->schedule($writerId, $serialWork, (int) $serialWorkRow->version, $uuid(), ['assignment_id' => $serialAssignment,
        'scheduled_at' => $serialSlot->addMinutes(5)->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
    $work->state($writerId, $serialWork, (int) DB::table('maintenance_work_orders')->find($serialWork)->version, 'start', 'بدء تحقق الرقم المسلسل.');
    $serialRequirement = $parts->requirement($writerId, $serialWork, (int) DB::table('maintenance_work_orders')->find($serialWork)->version,
        ['product_id' => $serialPart->id, 'warehouse_id' => $warehouse['id'], 'quantity' => '2.00000000']);
    $pass(DB::table('maintenance_part_requirements')->where('id', $serialRequirement)->value('tracking') === 'serial',
        'M08 requirement preserves the actual product serial tracking policy');
    $serialReservation = $parts->reserve($writerId, $serialWork, $serialRequirement, '2.00000000', $uuid());
    $serialIssue = $parts->issue((int) $tech->id, $serialWork, (int) $serialReservation['id'], '2.00000000', $uuid());
    $serialArrival = $serialSlot->addMinutes(10);
    $serialDeparture = $serialArrival->addMinutes(40);
    $serialVisitRow = DB::table('maintenance_visits')->find($serialVisit);
    $execution->recordVisit((int) $tech->id, $serialWork, $serialVisit, (int) $serialVisitRow->version,
        ['arrival_at' => $serialArrival->toIso8601String(), 'departure_at' => $serialDeparture->toIso8601String(),
            'work_performed' => 'تم فحص القطعة وتسجيل استخدامها.', 'diagnosis' => 'القطعة تعمل.', 'observations' => 'تم توثيق الرقم المسلسل.',
            'acknowledgment' => 'تم استلام نتيجة العمل.', 'outcome' => 'completed'], ['safety' => ['result' => 'complete']]);
    $serialRows = DB::table('inventory_serial_events as e')->join('inventory_serials as s', 's.id', '=', 'e.serial_id')
        ->where('e.movement_id', $serialIssue['movement_id'])->orderByRaw('s.normalized COLLATE "C"')->get(['s.id', 's.normalized', 's.state', 's.last_movement_id']);
    $pass($serialRows->count() === 2 && count($serialIssue['serials']) === 2
        && $serialRows->pluck('normalized')->all() === $serialIssue['serials'],
        'M08 issue evidence names the two actual serial identities reserved from the posted receipt');
    $usedSerial = $serialRows[0];
    $unusedSerial = $serialRows[1];
    $parts->usage((int) $tech->id, $serialWork, $serialVisit, $serialRequirement, '1.00000000', [$usedSerial->normalized]);
    $serialUsage = DB::table('maintenance_part_usage')->where('requirement_id', $serialRequirement)->first();
    $serialUsageRefs = DB::table('maintenance_part_serial_usage')->where('usage_id', $serialUsage->id)->get();
    $pass(json_decode($serialUsage->serials, true, 512, JSON_THROW_ON_ERROR) === [$usedSerial->normalized]
        && $serialUsageRefs->count() === 1 && (int) $serialUsageRefs[0]->serial_id === (int) $usedSerial->id
        && (int) $serialUsageRefs[0]->movement_id === (int) $serialIssue['movement_id'],
        'M08 usage stores the canonical selected serial and an immutable reference to its real issue movement');
    $beforeDuplicateUsage = ['usage' => DB::table('maintenance_part_usage')->where('requirement_id', $serialRequirement)->count(),
        'refs' => DB::table('maintenance_part_serial_usage')->where('usage_id', $serialUsage->id)->count(),
        'work_version' => (int) DB::table('maintenance_work_orders')->find($serialWork)->version];
    $reject(fn () => $parts->usage((int) $tech->id, $serialWork, $serialVisit, $serialRequirement, '1.00000000', [$usedSerial->normalized]),
        'M08 cannot register the same physical serial as used twice', 422);
    $pass($beforeDuplicateUsage === ['usage' => DB::table('maintenance_part_usage')->where('requirement_id', $serialRequirement)->count(),
        'refs' => DB::table('maintenance_part_serial_usage')->where('usage_id', $serialUsage->id)->count(),
        'work_version' => (int) DB::table('maintenance_work_orders')->find($serialWork)->version],
        'Duplicate serial usage denial leaves owner usage, serial references, and work version unchanged');
    $serialIssueId = (int) DB::table('maintenance_part_issues')->where('requirement_id', $serialRequirement)->value('id');
    $reject(fn () => $parts->returnUnused($writerId, $serialWork, $serialIssueId, '1.00000000', $uuid(), 'رفض إعادة القطعة المستخدمة', [$usedSerial->normalized]),
        'M08 cannot return a serial already recorded as used', 422);
    $reject(fn () => app(InventoryApi::class)->returnMaintenanceIssue($writerId, (int) $serialIssue['movement_id'], '1.00000000', $uuid(),
        'منع الإرجاع المباشر لرقم مستخدم', [$usedSerial->normalized]),
        'M06 direct maintenance return also enforces the M08 unused-serial proof', 422);

    $serialIssueMap = DB::table('inventory_fifo_serial_allocations as f')->join('inventory_serials as s', 's.id', '=', 'f.serial_id')
        ->join('inventory_fifo_allocations as a', 'a.id', '=', 'f.allocation_id')->join('inventory_fifo_layers as l', 'l.id', '=', 'a.layer_id')
        ->where('f.movement_id', $serialIssue['movement_id'])->orderByRaw('s.normalized COLLATE "C"')
        ->get(['s.id', 's.normalized', 'f.allocation_id', 'f.amount', 'l.movement_id as source_movement_id']);
    $unusedOriginal = $serialIssueMap->firstWhere('id', $unusedSerial->id);
    $receiptMovement = (int) DB::table('inventory_movements')->where('operation', 'purchase_receipt')->where('source_id', (string) $receipt)
        ->where('product_id', $serialPart->id)->value('id');
    $pass($serialIssueMap->count() === 2 && $unusedOriginal && bccomp($unusedOriginal->amount, '10.00', 2) === 0
        && (int) $unusedOriginal->source_movement_id === $receiptMovement,
        'M08 issue serials each retain their actual ten-unit FIFO cost from the posted purchase receipt');
    $unusedReturn = $parts->returnUnused($writerId, $serialWork, $serialIssueId, '1.00000000', $uuid(), 'قطعة غير مستخدمة أعيدت للمخزن', [$unusedSerial->normalized]);
    $returnRow = DB::table('maintenance_part_returns')->where('movement_id', $unusedReturn['movement_id'])->first();
    $returnRefs = DB::table('inventory_fifo_serial_allocations as f')->join('inventory_serials as s', 's.id', '=', 'f.serial_id')
        ->where('f.movement_id', $unusedReturn['movement_id'])->get(['s.id', 'f.allocation_id', 'f.amount']);
    $usedAfter = DB::table('inventory_serials')->find($usedSerial->id);
    $unusedAfter = DB::table('inventory_serials')->find($unusedSerial->id);
    $pass(json_decode($returnRow->serials, true, 512, JSON_THROW_ON_ERROR) === [$unusedSerial->normalized]
        && $returnRefs->count() === 1 && (int) $returnRefs[0]->id === (int) $unusedSerial->id
        && (int) $returnRefs[0]->allocation_id === (int) $unusedOriginal->allocation_id
        && bccomp($returnRefs[0]->amount, $unusedOriginal->amount, 2) === 0
        && $usedAfter->state === 'issued' && (int) $usedAfter->last_movement_id === (int) $serialIssue['movement_id']
        && $unusedAfter->state === 'available' && (int) $unusedAfter->last_movement_id === (int) $unusedReturn['movement_id'],
        'M08 unused return restores only the selected serial with its exact original FIFO allocation cents');
    $issueJournal = DB::table('inventory_movement_journals')->where('movement_id', $serialIssue['movement_id'])->value('journal_id');
    $returnJournal = DB::table('inventory_movement_journals')->where('movement_id', $unusedReturn['movement_id'])->value('journal_id');
    $issueGl = DB::table('accounting_journal_lines as l')->join('accounting_account_mappings as m', 'm.account_id', '=', 'l.account_id')
        ->where('l.journal_id', $issueJournal)->get(['m.purpose', 'l.debit', 'l.credit'])->keyBy('purpose');
    $returnGl = DB::table('accounting_journal_lines as l')->join('accounting_account_mappings as m', 'm.account_id', '=', 'l.account_id')
        ->where('l.journal_id', $returnJournal)->get(['m.purpose', 'l.debit', 'l.credit'])->keyBy('purpose');
    $pass($issueJournal && $returnJournal && $issueGl->has('inventory_issue') && $issueGl->has('inventory')
        && bccomp($issueGl['inventory_issue']->debit, '20.00', 2) === 0 && bccomp($issueGl['inventory']->credit, '20.00', 2) === 0
        && $returnGl->has('inventory') && $returnGl->has('inventory_issue')
        && bccomp($returnGl['inventory']->debit, $unusedOriginal->amount, 2) === 0
        && bccomp($returnGl['inventory_issue']->credit, $unusedOriginal->amount, 2) === 0,
        'M08 issue and unused return journals post exact FIFO cost to the mapped inventory accounts');
    $parts->reconciled($writerId, $serialWork);

    $stage = 'M09 serialized source: genuine company custody, approved repair, part issue, usage, and unused return';
    $companyEquipmentSerial = DB::table('inventory_serials')->where('product_id', $companyEquipment->id)->where('normalized', $tag.'-M09-COMPANY-EQUIP')->first();
    $workshopActions = app(WorkshopActions::class);
    $workshop = $workshopActions->saveWorkshop($writerId, null, 0, ['code' => $tag.'-M09', 'name' => 'ورشة قبول الأرقام المسلسلة',
        'specialty' => 'إصلاح معدات الشركة', 'branch_id' => $branch->id, 'supervisor_id' => $writerId, 'warehouse_id' => null,
        'capacity' => 3, 'schedule' => [['day' => 0, 'start' => '08:00', 'end' => '16:00']], 'active' => true]);
    $workshopActions->member($writerId, $workshop, 1, (int) $tech->id, true);
    $workshopIntake = $workshopActions->saveIntake($writerId, null, 0, $uuid(), ['branch_id' => $branch->id, 'customer_id' => null,
        'company_serial_id' => $companyEquipmentSerial->id, 'company_movement_id' => null, 'equipment_description' => 'معدة شركة مسجلة للاستلام والإصلاح',
        'model' => 'DEMO-M09-COMPANY', 'serial_number' => $companyEquipmentSerial->normalized, 'accessories' => 'ملحقات موثقة عند الاستلام',
        'reported_fault' => 'عطل فني موثق في المعدة.', 'arrival_condition' => 'الحالة موثقة قبل الإصلاح.', 'customer_owned' => false,
        'location' => 'استلام الورشة']);
    $custodyReceivedAt = CarbonImmutable::now('UTC')->subHours(8)->startOfMinute();
    $workshopActions->receive($writerId, $workshopIntake, 1, $uuid(), $custodyReceivedAt->toIso8601String(), 'تم استلام معدة الشركة المسجلة للعهدة والإصلاح.');
    $custodyRow = DB::table('workshop_intakes')->find($workshopIntake);
    $companyJob = $workshopActions->createJob($writerId, $workshopIntake, (int) $custodyRow->version, $uuid(), $workshop, 'normal', 'nonbillable');
    $workshopActions->assign($writerId, $companyJob, 1, $workshop, (int) $tech->id, 'تعيين الفني المعتمد لإصلاح معدة الشركة.');
    $workshopActions->diagnose((int) $tech->id, $companyJob, 2, ['diagnosis' => 'تم تحديد الخلل في مكون قابل للاستبدال.',
        'planned_work' => 'استبدال القطعة ثم اختبار المعدة.', 'required_tests' => [['name' => 'اختبار التشغيل بعد الإصلاح', 'required' => true]], 'estimated_minutes' => 30]);
    $offers = app(RepairOffers::class);
    $offers->nonbillable((int) $reviewer->id, $companyJob, 3, 'internal', null, 'اعتماد داخلي مستقل لإصلاح معدة الشركة المسجلة.');
    $companyJobRow = DB::table('workshop_jobs')->find($companyJob);
    $workshopExecution = app(WorkshopExecution::class);
    $workshopExecution->state((int) $tech->id, $companyJob, (int) $companyJobRow->version, 'start', 'بدء الإصلاح الداخلي المعتمد.');
    $workshopParts = app(WorkshopParts::class);
    $workshopRequirement = $workshopParts->requirement($writerId, $companyJob, (int) DB::table('workshop_jobs')->find($companyJob)->version,
        ['product_id' => $workshopSerialPart->id, 'warehouse_id' => $warehouse['id'], 'quantity' => '2.00000000']);
    $workshopReservation = $workshopParts->reserve($writerId, $companyJob, $workshopRequirement, '2.00000000', $uuid());
    $workshopIssue = $workshopParts->issue((int) $tech->id, $companyJob, (int) $workshopReservation['id'], '2.00000000', $uuid());
    $workshopSerialRows = DB::table('inventory_serial_events as e')->join('inventory_serials as s', 's.id', '=', 'e.serial_id')
        ->where('e.movement_id', $workshopIssue['movement_id'])->orderByRaw('s.normalized COLLATE "C"')->get(['s.id', 's.normalized']);
    $workshopRecordStart = $custodyReceivedAt->addHours(1);
    $workshopRecordEnd = $workshopRecordStart->addMinutes(30);
    $workshopRecord = $workshopExecution->record((int) $tech->id, $companyJob, (int) DB::table('workshop_jobs')->find($companyJob)->version, $uuid(), [
        'started_at' => $workshopRecordStart->toIso8601String(), 'ended_at' => $workshopRecordEnd->toIso8601String(),
        'work_performed' => 'تم استبدال القطعة المسلسلة وتوثيق العمل.', 'observations' => 'تم حفظ سجل العمل الفعلي.', 'work_type' => 'repair']);
    $workshopUsedSerial = $workshopSerialRows[0];
    $workshopUnusedSerial = $workshopSerialRows[1];
    $workshopParts->usage((int) $tech->id, $companyJob, $workshopRecord, $workshopRequirement, '1.00000000', [$workshopUsedSerial->normalized]);
    $workshopUsage = DB::table('workshop_part_usage')->where('requirement_id', $workshopRequirement)->first();
    $workshopUsageRef = DB::table('workshop_part_serial_usage')->where('usage_id', $workshopUsage->id)->first();
    $companyCustody = DB::table('workshop_intakes')->where('id', $workshopIntake)->where('customer_owned', false)
        ->where('company_serial_id', $companyEquipmentSerial->id)->whereNotNull('isolation_id')->first();
    $pass($custodyRow && $companyCustody && DB::table('workshop_custody_events')->where('intake_id', $workshopIntake)->where('status', 'received')->exists()
        && DB::table('inventory_isolations')->where('id', $companyCustody->isolation_id)->exists()
        && json_decode($workshopUsage->serials, true, 512, JSON_THROW_ON_ERROR) === [$workshopUsedSerial->normalized]
        && $workshopUsageRef && (int) $workshopUsageRef->serial_id === (int) $workshopUsedSerial->id
        && (int) $workshopUsageRef->movement_id === (int) $workshopIssue['movement_id'],
        'M09 company equipment enters actual inventory isolation and custody while its usage records a real issued serial');
    $workshopIssueMap = DB::table('inventory_fifo_serial_allocations as f')->join('inventory_serials as s', 's.id', '=', 'f.serial_id')
        ->join('inventory_fifo_allocations as a', 'a.id', '=', 'f.allocation_id')->join('inventory_fifo_layers as l', 'l.id', '=', 'a.layer_id')
        ->where('f.movement_id', $workshopIssue['movement_id'])->get(['s.id', 'f.allocation_id', 'f.amount', 'l.movement_id as source_movement_id']);
    $workshopUnusedOriginal = $workshopIssueMap->firstWhere('id', $workshopUnusedSerial->id);
    $workshopReceiptMovement = (int) DB::table('inventory_movements')->where('operation', 'purchase_receipt')->where('source_id', (string) $receipt)
        ->where('product_id', $workshopSerialPart->id)->value('id');
    $pass($workshopSerialRows->count() === 2 && $workshopIssueMap->count() === 2 && $workshopUnusedOriginal
        && bccomp($workshopUnusedOriginal->amount, '15.00', 2) === 0 && (int) $workshopUnusedOriginal->source_movement_id === $workshopReceiptMovement,
        'M09 issue serial allocations retain exact cost and the real posted receipt source');
    $workshopIssueId = (int) DB::table('workshop_part_issues')->where('movement_id', $workshopIssue['movement_id'])->value('id');
    $reject(fn () => $workshopParts->returnUnused($writerId, $companyJob, $workshopIssueId, '1.00000000', $uuid(),
        'رفض إعادة الرقم المسلسل المستخدم في الورشة', [$workshopUsedSerial->normalized]),
        'M09 cannot return a serial already recorded as used', 422);
    $reject(fn () => app(InventoryApi::class)->returnWorkshopIssue($writerId, (int) $workshopIssue['movement_id'], '1.00000000', $uuid(),
        'منع الإرجاع المباشر لرقم مستخدم في الورشة', [$workshopUsedSerial->normalized]),
        'M06 direct workshop return also requires the M09 unused-serial proof', 422);
    $workshopUnusedReturn = $workshopParts->returnUnused($writerId, $companyJob, $workshopIssueId, '1.00000000', $uuid(),
        'قطعة ورشة غير مستخدمة أعيدت إلى المخزن', [$workshopUnusedSerial->normalized]);
    $workshopReturnRow = DB::table('workshop_part_returns')->where('movement_id', $workshopUnusedReturn['movement_id'])->first();
    $workshopReturnMap = DB::table('inventory_fifo_serial_allocations as f')->join('inventory_serials as s', 's.id', '=', 'f.serial_id')
        ->where('f.movement_id', $workshopUnusedReturn['movement_id'])->get(['s.id', 'f.allocation_id', 'f.amount']);
    $workshopUsedAfter = DB::table('inventory_serials')->find($workshopUsedSerial->id);
    $workshopUnusedAfter = DB::table('inventory_serials')->find($workshopUnusedSerial->id);
    $workshopReturnJournal = DB::table('inventory_movement_journals')->where('movement_id', $workshopUnusedReturn['movement_id'])->value('journal_id');
    $workshopReturnGl = DB::table('accounting_journal_lines as l')->join('accounting_account_mappings as m', 'm.account_id', '=', 'l.account_id')
        ->where('l.journal_id', $workshopReturnJournal)->get(['m.purpose', 'l.debit', 'l.credit'])->keyBy('purpose');
    $pass(json_decode($workshopReturnRow->serials, true, 512, JSON_THROW_ON_ERROR) === [$workshopUnusedSerial->normalized]
        && $workshopReturnMap->count() === 1 && (int) $workshopReturnMap[0]->id === (int) $workshopUnusedSerial->id
        && (int) $workshopReturnMap[0]->allocation_id === (int) $workshopUnusedOriginal->allocation_id
        && bccomp($workshopReturnMap[0]->amount, $workshopUnusedOriginal->amount, 2) === 0
        && $workshopUsedAfter->state === 'issued' && (int) $workshopUsedAfter->last_movement_id === (int) $workshopIssue['movement_id']
        && $workshopUnusedAfter->state === 'available' && (int) $workshopUnusedAfter->last_movement_id === (int) $workshopUnusedReturn['movement_id']
        && $workshopReturnGl->has('inventory') && $workshopReturnGl->has('inventory_issue')
        && bccomp($workshopReturnGl['inventory']->debit, $workshopUnusedOriginal->amount, 2) === 0
        && bccomp($workshopReturnGl['inventory_issue']->credit, $workshopUnusedOriginal->amount, 2) === 0,
        'M09 returns only its unused serial at the original FIFO cents and reverses exact inventory expense GL');
    $workshopParts->reconciled($writerId, $companyJob);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $pass(true, 'Deferred serial source, FIFO cent, and M08/M09 owner usage, custody, and return constraints validate before rollback');
    $fixtureCounts = $counts();
} catch (Throwable $error) {
    $failure = $error;
} finally {
    DB::rollBack();
    $after = $counts();
    $companyAfter = DB::table('companies')->where('id', 1)->first();
    $companyAfter = $companyAfter ? (array) $companyAfter : null;
    $companyRollbackVerified = $companyBaseline === $companyAfter;
    foreach ([$privateRoot, $appStorage, $taskRoot.'/m10-parts-cache-'.$runtimeTag, $taskRoot.'/m10-parts-views-'.$runtimeTag] as $path) {
        if (is_dir($path) && realpath(dirname($path)) === realpath($taskRoot) && str_starts_with(basename($path), 'm10-parts-')) {
            (new Illuminate\Filesystem\Filesystem)->deleteDirectory($path);
        }
    }
    Carbon::setTestNow();
    $scratchClean = ! is_dir($privateRoot) && ! is_dir($appStorage)
        && ! is_dir($taskRoot.'/m10-parts-cache-'.$runtimeTag) && ! is_dir($taskRoot.'/m10-parts-views-'.$runtimeTag);
$rollbackVerified = $before === $after && $scratchClean && $companyRollbackVerified;
}
$checks[] = ['name' => 'All source fixtures, accounting/inventory evidence, and M10 ledger rows roll back.', 'result' => $rollbackVerified ? 'PASS' : 'FAIL'];
$checks[] = ['name' => 'Synthetic company legal profile restores the exact pre-check row.', 'result' => $companyRollbackVerified ? 'PASS' : 'FAIL'];
$assertions++;
if (! $rollbackVerified && $failure === null) {
    $failure = new RuntimeException('M10 parts acceptance left database or private scratch changes after rollback.');
}
$receipt = ['all_pass' => $failure === null, 'mode' => 'm10-m06-m08-parts-entitlements', 'assertions' => $assertions,
    'rollback_verified' => $rollbackVerified, 'private_scratch_clean' => $scratchClean,
    'database' => ['name' => $identity->database, 'server_address' => $identity->server_address, 'port' => (int) $identity->port,
        'postgres_major' => intdiv((int) $identity->server_version_num, 10000)],
    'counts' => ['before' => $before, 'during_fixture' => $fixtureCounts, 'after_rollback' => $after], 'checks' => $checks,
    'phase' => $failure === null ? 'complete' : $stage,
    'failure' => $failure === null ? null : ['class' => $failure::class,
        'message' => mb_substr((string) preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $failure->getMessage()), 0, 500)] + $writeDiagnostic($failure)];
$receiptPath = $emit($receipt);
fwrite($failure === null ? STDOUT : STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
fwrite($failure === null ? STDOUT : STDERR, 'RESULT all_pass='.($failure === null ? '1' : '0').' assertions='.$assertions
    .' rollback_verified='.($rollbackVerified ? '1' : '0').' receipt='.$receiptPath.PHP_EOL);
if ($failure !== null || ! $rollbackVerified) {
    exit(1);
}
