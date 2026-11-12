<?php

declare(strict_types=1);

// Guarded Gate2 M10 billing acceptance. Synthetic rows roll back; real owner APIs create every business effect.
use App\Application\ActivateFinancialPosting;
use App\Application\ExecutiveDashboard;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\ServiceContracts\Application\ServiceContractAccess;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractBilling;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use App\Modules\ServiceContracts\Application\ServiceContractReport;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 2);
$evidence = $root.'/docs/release/remediation/evidence/gate2';
$stage = 'source preflight';
$emit = static function (array $receipt) use ($evidence): string {
    if (! is_dir($evidence) && ! mkdir($evidence, 0770, true) && ! is_dir($evidence)) {
        throw new RuntimeException('Unable to create Gate2 evidence directory.');
    }
    $path = $evidence.'/m10-billing-'.gmdate('Ymd\\THis\\Z').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);

    return $path;
};
$checks = [];
$assertions = 0;
set_exception_handler(static function (Throwable $error) use (&$stage, &$checks, &$assertions, $emit): void {
    $safe = preg_replace('/(password|secret|token)=\\S+/i', '$1=[redacted]', $error->getMessage());
    $receipt = ['all_pass' => false, 'mode' => 'm10-billing', 'phase' => $stage, 'assertions' => $assertions,
        'rollback_verified' => false, 'checks' => $checks, 'failure' => ['class' => $error::class, 'message' => mb_substr((string) $safe, 0, 500)]];
    try {
        $path = $emit($receipt);
        fwrite(STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        fwrite(STDERR, 'RESULT all_pass=0 assertions='.$assertions.' rollback_verified=0 receipt='.$path.PHP_EOL);
    } catch (Throwable) {
        fwrite(STDERR, "RESULT all_pass=0 assertions={$assertions} rollback_verified=0 receipt_write_failed=1\n");
    }
    exit(1);
});

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';
$required = [
    $root.'/app/Modules/ServiceContracts/Application/ServiceContractBilling.php',
    $root.'/app/Modules/ServiceContracts/Application/ServiceContractReport.php',
    $root.'/app/Modules/ReceivablesPayables/Application/ContractInvoices.php',
    $root.'/database/migrations/2026_11_01_208000_create_service_contract_billing.php',
    $root.'/database/migrations/2026_11_01_209000_allow_contract_invoice_source_lines.php',
];
foreach ($required as $file) {
    if (! is_file($file)) {
        throw new RuntimeException('Required M10 billing source is missing: '.basename($file));
    }
}
if (! class_exists(ServiceContractBilling::class) || ! method_exists(ServiceContractReport::class, 'show') || ! method_exists(ReceivablesPayablesApi::class, 'createContractInvoice')
    || ! method_exists(ReceivablesPayablesApi::class, 'contractFinancialSummary') || ! method_exists(ServiceContractsApi::class, 'recognizedValue')) {
    throw new RuntimeException('M10/M12 billing contracts are incomplete.');
}
$environment = ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'), 'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'), 'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = OptimizationRegressionDatabaseGuard::target($root, $environment);
$privateRoot = $root.'/.tmp/master-remediation-20261101/m10-billing-private-'.bin2hex(random_bytes(8));
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$cacheRelative = '.tmp/master-remediation-20261101/m10-billing-cache';
$viewsRelative = '.tmp/master-remediation-20261101/m10-billing-views';
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'], 'DB_PORT' => (string) $target['port'], 'DB_DATABASE' => $target['database'],
    'DB_USERNAME' => $target['username'], 'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''), 'DB_URL' => '', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'LARAVEL_STORAGE_PATH' => $taskRoot.'/m10-billing-storage',
    'APP_CONFIG_CACHE' => $cacheRelative.'/config.php', 'APP_ROUTES_CACHE' => $cacheRelative.'/routes.php', 'APP_EVENTS_CACHE' => $cacheRelative.'/events.php',
    'APP_SERVICES_CACHE' => $cacheRelative.'/services.php', 'APP_PACKAGES_CACHE' => $cacheRelative.'/packages.php',
    'APP_VIEW_COMPILED_PATH' => $viewsRelative, 'VIEW_COMPILED_PATH' => $viewsRelative, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach ([$privateRoot, $taskRoot.'/m10-billing-cache', $taskRoot.'/m10-billing-storage', $taskRoot.'/m10-billing-views'] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        throw new RuntimeException('Could not create private M10 billing runtime directory.');
    }
}
register_shutdown_function(static function () use ($privateRoot, $taskRoot): void {
    if (is_dir($privateRoot) && realpath(dirname($privateRoot)) === realpath($taskRoot)
        && str_starts_with(basename($privateRoot), 'm10-billing-private-')) {
        (new Filesystem)->deleteDirectory($privateRoot);
    }
});
$stage = 'Laravel bootstrap and exact PostgreSQL guard';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($taskRoot.'/m10-billing-storage');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $target['connection_host'], 'database.connections.pgsql.port' => $target['port'],
    'database.connections.pgsql.database' => $target['database'], 'database.connections.pgsql.username' => $target['username'],
    'database.connections.pgsql.password' => (string) (getenv('DB_PASSWORD') ?: ''), 'database.connections.pgsql.url' => null,
    'filesystems.disks.local.root' => $privateRoot, 'filesystems.disks.local.visibility' => 'private', 'filesystems.disks.local.throw' => true,
    'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
 current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
 (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
    throw new RuntimeException('M10 billing acceptance requires the explicitly owned PostgreSQL 17 target.');
}
$requiredMigrations = ['2026_11_01_208000_create_service_contract_billing', '2026_11_01_209000_allow_contract_invoice_source_lines'];
if (DB::table('migrations')->whereIn('migration', $requiredMigrations)->count() !== count($requiredMigrations)) {
    throw new RuntimeException('M10 billing acceptance requires migration 208 applied to the guarded test database.');
}

$pass = static function (bool $condition, string $name) use (&$assertions, &$checks): void {
    $assertions++;
    $checks[] = ['name' => $name, 'result' => $condition ? 'PASS' : 'FAIL'];
    if (! $condition) {
        throw new RuntimeException($name);
    }
};
$reject = static function (callable $action, string $name) use ($pass): void {
    try {
        $action();
    } catch (ValidationException|HttpExceptionInterface $error) {
        $pass(true, $name);

        return;
    }
    $pass(false, $name);
};
$uuid = static fn (): string => (string) Str::uuid();
$grant = static function (User $actor, array $permissions, int $branch): void {
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => $permission, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$scope = static function (User $actor, int $branch): void {
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $actor->id, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
};
$countTables = ['companies', 'branches', 'users', 'customers', 'products', 'organization_employees', 'service_contracts', 'service_contract_revisions',
    'service_contract_documents', 'service_contract_work', 'service_contract_work_parts', 'service_contract_measurements', 'service_contract_claims',
    'service_contract_claim_lines', 'service_contract_billing_sources', 'service_contract_billing_allocations', 'service_contract_recognitions',
    'service_contract_recognition_reversals', 'service_contract_billing_corrections',
    'rp_customer_invoices', 'rp_customer_invoice_lines', 'rp_open_items', 'rp_credit_notes', 'rp_settlement_allocations', 'treasury_movements',
    'system_official_sequences', 'system_official_documents',
    'accounting_journals', 'accounting_journal_lines', 'inventory_movements', 'inventory_fifo_layers', 'inventory_balances', 'rp_party_credits',
    'accounting_accounts', 'accounting_account_mappings', 'accounting_tax_rules', 'accounting_periods', 'accounting_opening_batches', 'accounting_opening_lines',
    'access_grants', 'user_branch_scopes', 'audit_entries'];
$counts = static function () use ($countTables): array {
    $result = [];
    foreach ($countTables as $table) {
        if (DB::getSchemaBuilder()->hasTable($table)) {
            $result[$table] = (int) DB::table($table)->count();
        }
    }

    return $result;
};
$before = $counts();
$companyBefore = DB::table('companies')->where('id', 1)->first();
$activationBefore = DB::table('accounting_activation')->where('id', 1)->first();
if (! $activationBefore) {
    throw new RuntimeException('M11 activation row is missing; required migrations are incomplete.');
}
$during = [];
$activationDuring = null;
$zeroOpeningActivated = false;
$failureTrace = [];
$failure = null;
$stage = 'synthetic source graph and M11 readiness';
DB::beginTransaction();
try {
    if (! $companyBefore) {
        Company::factory()->create(['id' => 1, 'name' => 'Synthetic M10 billing acceptance entity', 'legal_name' => 'Synthetic M10 billing acceptance entity Ltd.',
            'address' => 'Rollback-only test address', 'setup_complete' => true]);
    } else {
        DB::table('companies')->where('id', 1)->update(['legal_name' => 'Synthetic M10 billing acceptance entity Ltd.',
            'address' => 'Rollback-only test address', 'setup_complete' => true, 'updated_at' => now()]);
    }
    $tag = 'DEMO-EG-M10B-'.Str::upper(Str::random(6));
    $branch = Branch::factory()->create(['company_id' => 1, 'code' => $tag]);
    $customer = Customer::factory()->create(['status' => 'active']);
    $service = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $measuredService = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $writer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $technician = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reportOnly = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$writer, $reviewer, $technician, $reportOnly] as $actor) {
        $scope($actor, (int) $branch->id);
    }
    $grant($writer, ['M03.customer_view', 'M02.product_view', 'M01.employee_view', 'M01.employee_manage', 'M10.contract_view', 'M10.contract_create',
        'M10.contract_edit', 'M10.contract_sign', 'M10.contract_activate', 'M10.document_manage', 'M10.document_view', 'M10.document_issue', 'M10.billing_manage', 'M10.coverage_use',
        'M12.customer_invoice_manage', 'M12.credit_note_manage', 'M12.ar_view',
        'M08.view', 'M08.workorder_manage', 'M08.standalone_create', 'M08.assign', 'M08.checklist_manage', 'M08.close', 'M08.billing_request', 'M08.visit_record', 'M08.labor_record'], (int) $branch->id);
    $grant($reviewer, ['M03.customer_view', 'M02.product_view', 'M01.employee_view', 'M10.contract_view', 'M10.contract_approve', 'M10.contract_activate',
        'M10.billing_approve', 'M10.coverage_use', 'M10.reports_view', 'M08.view', 'M08.verify', 'M08.close', 'M12.customer_invoice_manage', 'M12.customer_invoice_post',
        'M12.ar_view', 'M12.credit_note_approve', 'M13.collection_execute', 'M11.report_view', 'M11.tax_calculate', 'M11.source_post', 'M11.journal_approve', 'M11.journal_post', 'M11.journal_view'], (int) $branch->id);
    $grant($technician, ['M03.customer_view', 'M10.coverage_use', 'M08.workorder_manage', 'M08.assign', 'M08.visit_record', 'M08.labor_record', 'M08.close', 'M08.view'], (int) $branch->id);
    $grant($reportOnly, ['M10.reports_view'], (int) $branch->id);
    foreach ([$writer, $reviewer] as $reader) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $reader->id, 'permission_code' => 'M03.customer_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('access_grants')->insertOrIgnore(['user_id' => $reader->id, 'permission_code' => 'M02.product_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'active' => true, 'starts_on' => now('Africa/Cairo')->subDay()->toDateString()]);
    $today = now('Africa/Cairo')->toDateString();
    Carbon::setTestNow(CarbonImmutable::parse($today, 'Africa/Cairo')->startOfDay()->addHours(12));
    $invoiceDate = now('Africa/Cairo')->addDay()->toDateString();
    $grant($writer, ['M11.account_manage', 'M11.setup_manage', 'M11.tax_manage', 'M11.period_open', 'M11.opening_manage', 'M11.tax_calculate', 'M11.source_post',
        'M11.journal_create', 'M11.journal_submit', 'M11.journal_approve', 'M11.journal_post'], (int) $branch->id);
    $grant($reviewer, ['M11.report_view', 'M11.source_post', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_create', 'M11.journal_submit',
        'M11.journal_approve', 'M11.journal_post', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ar_view', 'M13.collection_execute'], (int) $branch->id);
    foreach (['M11.account_manage', 'M11.setup_manage', 'M11.tax_manage', 'M11.tax_calculate', 'M11.period_open', 'M11.opening_manage', 'M11.source_post',
        'M11.journal_create', 'M11.journal_submit', 'M11.journal_approve', 'M11.journal_post', 'M13.account_manage'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $writer->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M11.report_view', 'M11.tax_calculate', 'M11.source_post', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_approve', 'M11.journal_post', 'M13.account_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $reviewer->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    // Recreate M11 posting readiness with owner actions and an explicit no-opening declaration, all inside rollback.
    $setup = app(FinancialActivation::class);
    $accounts = app(AccountingActions::class);
    $mappings = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $mappings[$purpose] = $accounts->account((int) $writer->id, null, 0, ['code' => $tag.'-'.strtoupper($purpose),
            'name' => 'Synthetic M10 billing '.$label, 'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]);
    }
    $activationVersion = (int) $activationBefore->version;
    $setup->save((int) $writer->id, $activationVersion, ['go_live_date' => $today, 'mappings' => $mappings]);
    $activationVersion++;
    foreach ([['sale', null, 'GENERIC-SALE', 'exempt', false], ['purchase', null, 'GENERIC-PURCHASE', 'exempt', false],
        ['sale', $service->id, 'EXEMPT', 'exempt', false], ['sale', $measuredService->id, 'PERCENT', 'percentage', false]] as [$kind, $productId, $suffix, $treatment, $recoverable]) {
        $setup->addTaxRule((int) $writer->id, $activationVersion, ['code' => $tag.'-'.$suffix, 'name' => 'Synthetic contract '.$suffix.' tax treatment',
            'transaction_type' => $kind, 'product_id' => $productId, 'rate' => '0', 'starts_on' => $productId === null ? $today : $invoiceDate, 'ends_on' => null,
            'treatment' => $treatment, 'tax_scale' => 2, 'legal_reference' => 'Rollback-only synthetic acceptance tax treatment', 'purchase_recoverable' => $recoverable]);
        $activationVersion++;
    }
    $setup->validateTaxTreatment((int) $writer->id, $activationVersion, 'Rollback-only synthetic M10 billing tax fixture.');
    $activationVersion++;
    $year = (int) substr($today, 0, 4);
    $accounts->period((int) $writer->id, ['name' => 'Synthetic M10 billing '.$tag, 'starts_on' => $year.'-01-01', 'ends_on' => $year.'-12-31']);
    $opening = app(OpeningBalances::class);
    $batch = $opening->save((int) $writer->id, $activationVersion, $uuid(), [], true,
        'Explicit rollback-only synthetic no-opening-balances declaration for M10 billing acceptance.');
    $opening->advance((int) $writer->id, $batch, 1, 'submit');
    $opening->advance((int) $reviewer->id, $batch, 2, 'approve');
    app(ActivateFinancialPosting::class)->execute((int) $reviewer->id, $batch, 3);
    $activationDuring = DB::table('accounting_activation')->where('id', 1)->first();
    $zeroOpeningActivated = DB::table('accounting_opening_batches')->where('id', $batch)->where('status', 'posted')->where('no_balances', true)->exists();
    $pass($activationDuring?->activated_at !== null && $activationDuring?->go_live_date === $today && $zeroOpeningActivated,
        'Synthetic M11 Go-Live uses owner setup, validated tax, independent no-opening approval and ActivateFinancialPosting inside rollback.');

    if (! DB::table('accounting_account_mappings')->where('purpose', 'service_contract_deferred')->exists()) {
        $accountId = $accounts->account((int) $writer->id, null, 0, ['code' => 'M10B-DEF-'.Str::upper(Str::random(5)),
            'name' => 'Synthetic service contract deferred revenue', 'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
        $setup->saveOptionalMapping((int) $writer->id, 'service_contract_deferred', $accountId);
    }

    $contracts = app(ServiceContractActions::class);
    $billing = app(ServiceContractBilling::class);
    $starts = now('Africa/Cairo')->subDay()->toDateString();
    $ends = now('Africa/Cairo')->addMonths(2)->toDateString();
    $baseTerms = ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_employee_id' => $employee->id, 'title' => 'عقد قبول فوترة',
        'starts_on' => $starts, 'ends_on' => $ends, 'value' => '100.00', 'service_product_id' => $service->id, 'billing_mode' => 'installments', 'pricing_tax_mode' => 'exclusive',
        'terms' => 'شروط اختبار محلية دون أسعار ضريبية مخترعة.', 'sla_response_minutes' => 60, 'sla_resolution_minutes' => 240, 'covered_labor_minutes' => 300,
        'coverage' => [['equipment_ref' => $tag.'-EQ', 'site_id' => null, 'preventive_visits' => 2, 'corrective_visits' => 0]],
        'preventive_schedule' => [['code' => 'PM-01', 'equipment_ref' => $tag.'-EQ', 'site_id' => null, 'due_date' => $today, 'description' => 'Synthetic scheduled preventive service']],
        'parts' => [], 'excluded_parts' => [], 'boq' => [],
        'schedule' => [['code' => 'INST-01', 'due_date' => $today, 'amount' => '100.00', 'description' => 'دفعة الاختبار']]];
    $installmentContract = $contracts->save((int) $writer->id, null, 0, $uuid(), $baseTerms);
    $activate = static function (int $contractId) use ($contracts, $writer, $reviewer, $today): void {
        $signature = app(ServiceContractDocuments::class)->store((int) $writer->id, $contractId,
            UploadedFile::fake()->createWithContent('billing-signature.pdf', "%PDF-1.4\nSynthetic signed contract evidence\n%%EOF"), 'signature', 'Rollback-only billing acceptance');
        $row = DB::table('service_contracts')->find($contractId);
        $contracts->submit((int) $writer->id, $contractId, (int) $row->version);
        $row = DB::table('service_contracts')->find($contractId);
        $contracts->approve((int) $reviewer->id, $contractId, (int) $row->version);
        $row = DB::table('service_contracts')->find($contractId);
        $contracts->sign((int) $writer->id, $contractId, (int) $row->version, $signature, $today);
        $row = DB::table('service_contracts')->find($contractId);
        $contracts->activate((int) $reviewer->id, $contractId, (int) $row->version);
    };
    $activate($installmentContract);
    $officialContract = app(ServiceContractDocuments::class)->issue((int) $writer->id, $installmentContract);
    $officialRow = DB::table('system_official_documents')->find($officialContract['id']);
    $officialSnapshot = $officialRow ? json_decode($officialRow->snapshot, true, 512, JSON_THROW_ON_ERROR) : [];
    $officialContent = $officialSnapshot['content'] ?? [];
    $officialFields = $officialContent['fields'] ?? [];
    app(ServiceContractAccess::class)->authorize((int) $writer->id, (int) $branch->id, 'document_view');
    $officialPdf = app(OfficialDocuments::class)->retrieve($officialContract['id']);
    $activeContract = DB::table('service_contracts')->find($installmentContract);
    $activeRevision = DB::table('service_contract_revisions')->find($activeContract->current_revision_id);
    $pass($officialRow && $officialRow->document_type === 'service_contract' && (int) $officialSnapshot['revision'] === (int) $activeRevision->revision
        && $officialSnapshot['company']['legal_name'] === 'Synthetic M10 billing acceptance entity Ltd.'
        && $officialFields['العنوان'] === $baseTerms['title'] && $officialFields['شروط الخدمة'] === $baseTerms['terms']
        && $officialFields['مهلة الاستجابة بالدقائق'] === '60' && $officialFields['مهلة المعالجة بالدقائق'] === '240'
        && $officialFields['وقت العمل المغطى بالدقائق'] === '300' && $officialFields['طريقة الفوترة'] === 'أقساط'
        && $officialFields['أساس الأسعار'] === 'غير شامل الضريبة'
        && $officialFields['جدول الفوترة الموقع'] === 'INST-01 | '.$today.' | 100.00 EGP | دفعة الاختبار'
        && $officialFields['جدول الخدمة الوقائية'] === 'PM-01 | '.$tag.'-EQ | موقع غير محدد | '.$today.' | Synthetic scheduled preventive service'
        && $officialContent['columns'] === ['المعدة', 'الموقع', 'زيارات وقائية', 'زيارات إصلاح']
        && $officialContent['rows'] === [[$tag.'-EQ', '', '2', '0']]
        && $officialContent['totals'] === ['قيمة العقد EGP' => '100.00']
        && str_starts_with($officialPdf['bytes'], '%PDF-') && hash_equals($officialContract['sha256'], hash('sha256', $officialPdf['bytes']))
        && hash_equals($officialRow->sha256, $officialPdf['sha256']),
        'Issued M10 PDF has an integrity-checked frozen snapshot of signed terms, billing and preventive schedules, covered labor, and coverage rows.');
    $due = ['invoice_date' => $invoiceDate, 'due_date' => $invoiceDate, 'tax_mode' => 'exclusive', 'reference' => 'Synthetic installment invoice'];
    $source = $billing->proposeInstallment((int) $writer->id, $installmentContract, 'INST-01', $uuid(), $due + ['amount' => '100.00']);
    $reject(fn () => $billing->approveSource((int) $writer->id, $source, 1), 'Installment proposer cannot approve their own billing source.');
    $billing->approveSource((int) $reviewer->id, $source, 1);
    $invoiceApi = app(ReceivablesPayablesApi::class);
    $invoice = $billing->invoice((int) $writer->id, $source, $uuid());
    $pass($invoice === $billing->invoice((int) $writer->id, $source, $uuid())
        && DB::table('rp_customer_invoices')->where('id', $invoice)->where('service_contract_id', $installmentContract)
            ->where('service_contract_source_id', $source)->where('status', 'draft')->where('total_amount', '100.00')->exists()
        && DB::table('service_contract_billing_sources')->where('id', $source)->where('tax_mode', 'exclusive')->where('tax_snapshot->treatment', 'exempt')->exists()
        && DB::table('service_contract_billing_allocations')->where('source_id', $source)->where('invoice_id', $invoice)->count() === 1,
        'Approved installment allocates once to a source-linked M12 draft using the signed exclusive exempt amount.');
    $reject(fn () => $billing->proposeInstallment((int) $writer->id, $installmentContract, 'INST-01', $uuid(), $due + ['amount' => '1.00']),
        'A schedule line cannot be overbilled through a second source proposal.');
    $wrongTaxMode = $due;
    $wrongTaxMode['tax_mode'] = 'inclusive';
    $sourceCountBeforeTaxDenial = DB::table('service_contract_billing_sources')->count();
    $reject(fn () => $billing->proposeInstallment((int) $writer->id, $installmentContract, 'INST-01', $uuid(), $wrongTaxMode + ['amount' => '1.00']),
        'Billing cannot change the signed exclusive pricing tax mode.');
    $pass(DB::table('service_contract_billing_sources')->count() === $sourceCountBeforeTaxDenial,
        'Rejected tax-mode proposal leaves no billing source behind.');

    $invoiceRow = DB::table('rp_customer_invoices')->find($invoice);
    $invoiceApi->validateCustomerInvoice((int) $reviewer->id, $invoice, (int) $invoiceRow->version);
    $invoiceRow = DB::table('rp_customer_invoices')->find($invoice);
    $invoiceJournalsBeforeDenial = DB::table('accounting_journals')->count();
    $reject(fn () => $invoiceApi->postCustomerInvoice((int) $writer->id, $invoice, (int) $invoiceRow->version),
        'Invoice creator without M12 posting authority cannot post the validated invoice.');
    $pass(DB::table('rp_customer_invoices')->where('id', $invoice)->where('status', 'validated')->exists()
        && ! DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoice)->exists()
        && DB::table('accounting_journals')->count() === $invoiceJournalsBeforeDenial,
        'Denied invoice posting leaves validation state, open items and GL unchanged.');
    $invoicePosting = $invoiceApi->postCustomerInvoice((int) $reviewer->id, $invoice, (int) $invoiceRow->version);
    $invoiceReplay = $invoiceApi->postCustomerInvoice((int) $reviewer->id, $invoice, (int) $invoiceRow->version);
    $pass($invoicePosting['journal_id'] === $invoiceReplay['journal_id']
        && DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoice)->count() === 1,
        'M12 invoice posting replays the same journal and creates one open item.');

    $workForContract = static function (int $contractId, string $equipment) use ($writer, $technician, $reviewer, $branch, $customer, $uuid, $today, &$stage): int {
        $actions = app(MaintenanceActions::class);
        $execution = app(MaintenanceExecution::class);
        $stage = 'M08 writer workorder_manage plus standalone_create';
        $workId = $actions->createWorkOrder((int) $writer->id, null, 0, $uuid(), ['branch_id' => $branch->id, 'customer_id' => $customer->id,
            'responsible_id' => $technician->id, 'description' => 'Synthetic completed electrical service for contract billing.', 'priority' => 'normal', 'billable' => false, 'acknowledgment_required' => false]);
        $row = DB::table('maintenance_work_orders')->find($workId);
        $stage = 'M10 writer coverage_use plus M08 workorder_manage contract link';
        $actions->linkContract((int) $writer->id, $workId, (int) $row->version, $contractId, $equipment, $today, 'preventive');
        $stage = 'M08 writer checklist_manage template';
        $template = $execution->saveTemplate((int) $writer->id, null, 0, ['branch_id' => $branch->id, 'code' => 'M10B-'.Str::upper(Str::random(6)),
            'title' => 'Synthetic test checklist', 'items' => [['key' => 'safe', 'label' => 'Safety check', 'required' => true]], 'active' => true]);
        $base = CarbonImmutable::now('UTC')->startOfMinute();
        $row = DB::table('maintenance_work_orders')->find($workId);
        $stage = 'M08 writer assign; technician list requires writer view and technician visit_record';
        $assignment = $execution->assign((int) $writer->id, $workId, (int) $row->version, ['technician_id' => $technician->id,
            'starts_at' => $base->subHours(3)->toIso8601String(), 'ends_at' => $base->addHours(2)->toIso8601String()]);
        $row = DB::table('maintenance_work_orders')->find($workId);
        $stage = 'M08 writer assign schedules assigned technician visit';
        $visit = $execution->schedule((int) $writer->id, $workId, (int) $row->version, $uuid(), ['assignment_id' => $assignment,
            'scheduled_at' => $base->subHours(2)->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
        $row = DB::table('maintenance_work_orders')->find($workId);
        $actions->state((int) $technician->id, $workId, (int) $row->version, 'start', 'Synthetic field work started.');
        $arrival = $base->subMinutes(110);
        $departure = $base->subMinutes(5);
        $visitRow = DB::table('maintenance_visits')->find($visit);
        $stage = 'M08 technician visit_record and labor_record';
        $execution->recordVisit((int) $technician->id, $workId, $visit, (int) $visitRow->version, ['arrival_at' => $arrival->toIso8601String(),
            'departure_at' => $departure->toIso8601String(), 'work_performed' => 'Completed an electrical inspection under contract.', 'outcome' => 'completed'], ['safe' => ['result' => 'complete']]);
        $execution->labor((int) $technician->id, $workId, $uuid(), ['visit_id' => $visit, 'starts_at' => $arrival->toIso8601String(),
            'ends_at' => $arrival->addMinutes(60)->toIso8601String(), 'work_type' => 'Contract work']);
        $row = DB::table('maintenance_work_orders')->find($workId);
        $stage = 'M08 technician workorder_manage completion';
        $actions->state((int) $technician->id, $workId, (int) $row->version, 'complete', 'Synthetic field work completed.');
        $row = DB::table('maintenance_work_orders')->find($workId);
        $stage = 'M08 independent reviewer verify and M10 coverage consumption';
        $actions->state((int) $reviewer->id, $workId, (int) $row->version, 'verify', 'Independent synthetic field work verification.');

        return (int) DB::table('service_contract_work')->where('owner', 'FieldMaintenance')->where('source_id', $workId)->where('status', 'consumed')->value('id');
    };
    $installmentWork = $workForContract($installmentContract, $tag.'-EQ');
    Carbon::setTestNow(CarbonImmutable::parse($invoiceDate, 'Africa/Cairo')->startOfDay()->addHours(12));
    $recognition = $billing->proposeRecognition((int) $writer->id, $installmentContract, [$installmentWork], '100.00', $invoiceDate, $uuid(), 'Synthetic earned contract revenue');
    $recognitionJournal = $billing->approveRecognition((int) $reviewer->id, $recognition, 1);
    $recognitionReplay = $billing->approveRecognition((int) $reviewer->id, $recognition, 2);
    $pass($recognitionJournal === $recognitionReplay
        && DB::table('service_contract_recognitions')->where('id', $recognition)->where('status', 'posted')->where('amount', '100.00')->count() === 1
        && DB::table('accounting_journals')->where('source_key', 'contract-recognition:'.$recognition)->count() === 1,
        'Recognition posts once from independently approved actual M08 work and cannot duplicate its earning journal.');
    $overCap = $billing->proposeRecognition((int) $writer->id, $installmentContract, [$installmentWork], '0.01', $invoiceDate, $uuid(), 'Synthetic excess earned revenue');
    $recognitionJournalsBeforeDenial = DB::table('accounting_journals')->count();
    $reject(fn () => $billing->approveRecognition((int) $reviewer->id, $overCap, 1), 'Recognition cannot exceed posted deferred billing even with completed work evidence.');
    $pass(DB::table('service_contract_recognitions')->where('id', $overCap)->where('status', 'proposed')->exists()
        && DB::table('accounting_journals')->count() === $recognitionJournalsBeforeDenial,
        'Rejected excess recognition remains unposted and creates no journal.');

    $claimTerms = $baseTerms;
    $claimTerms['billing_mode'] = 'measured_work';
    $claimTerms['value'] = '100.01';
    $claimTerms['schedule'] = [];
    $claimTerms['boq'] = [['code' => 'BOQ-01', 'description' => 'Synthetic measured electrical service', 'product_id' => $service->id, 'quantity' => '10.00000000', 'rate' => '10.00'],
        ['code' => 'BOQ-02', 'description' => 'Synthetic second measured service', 'product_id' => $measuredService->id, 'quantity' => '1.00000000', 'rate' => '0.01']];
    $claimContract = $contracts->save((int) $writer->id, null, 0, $uuid(), $claimTerms);
    $activate($claimContract);
    $mixedTerms = $claimTerms;
    $mixedTerms['schedule'] = [['code' => 'MIXED', 'due_date' => $today, 'amount' => '100.00', 'description' => 'Forbidden mixed billing']];
    $reject(fn () => $contracts->save((int) $writer->id, null, 0, $uuid(), $mixedTerms), 'Signed contract cannot mix installment and measured-work entitlements.');
    $claimWork = $workForContract($claimContract, $tag.'-EQ');
    $measurement = $billing->createMeasurement((int) $writer->id, $claimContract, $claimWork, 'BOQ-01', '10.00000000', $uuid(), 'Synthetic completed measured work');
    $reject(fn () => $billing->approveMeasurement((int) $writer->id, $measurement, 1), 'Measurement creator cannot approve their own work quantity.');
    $billing->approveMeasurement((int) $reviewer->id, $measurement, 1);
    $measurement2 = $billing->createMeasurement((int) $writer->id, $claimContract, $claimWork, 'BOQ-02', '1.00000000', $uuid(), 'Synthetic second product work quantity');
    $billing->approveMeasurement((int) $reviewer->id, $measurement2, 1);
    $claimInput = ['period_from' => $today, 'period_to' => $today, 'invoice_date' => $invoiceDate, 'due_date' => $invoiceDate,
        'tax_mode' => 'exclusive', 'reference' => 'Synthetic measured progress claim'];
    $stage = 'M10 measured claims preserve prior six and remaining four quantities';
    $claim6 = $billing->createClaim((int) $writer->id, $claimContract, $uuid(), $claimInput + ['lines' => [
        ['measurement_id' => $measurement, 'quantity' => '6.00000000'], ['measurement_id' => $measurement2, 'quantity' => '1.00000000']]]);
    $billing->submitClaim((int) $writer->id, $claim6, 1);
    $claimSource = $billing->approveClaim((int) $reviewer->id, $claim6, 2);
    $claimsBeforeOverclaim = DB::table('service_contract_claims')->count();
    $sourcesBeforeOverclaim = DB::table('service_contract_billing_sources')->count();
    $reject(fn () => $billing->createClaim((int) $writer->id, $claimContract, $uuid(), $claimInput + ['lines' => [['measurement_id' => $measurement, 'quantity' => '5.00000000']]]),
        'Prior claimed quantity 6 of 10 leaves only 4; creating a five-unit overclaim is rejected.');
    $pass(DB::table('service_contract_claims')->count() === $claimsBeforeOverclaim
        && DB::table('service_contract_billing_sources')->count() === $sourcesBeforeOverclaim,
        'Rejected overclaim leaves no draft claim or billing source behind.');
    $claim4 = $billing->createClaim((int) $writer->id, $claimContract, $uuid(), $claimInput + ['lines' => [['measurement_id' => $measurement, 'quantity' => '4.00000000']]]);
    $billing->submitClaim((int) $writer->id, $claim4, 1);
    $claim4Source = $billing->approveClaim((int) $reviewer->id, $claim4, 2);
    $claim6Invoice = $billing->invoice((int) $writer->id, $claimSource, $uuid());
    $claimInvoice = $billing->invoice((int) $writer->id, $claim4Source, $uuid());
    $productLines = DB::table('rp_customer_invoice_lines')->where('invoice_id', $claim6Invoice)->orderBy('id')->get();
    $productTax = $productLines->map(fn ($line) => ['product_id' => (int) $line->product_id] + json_decode($line->tax_snapshot, true, 512, JSON_THROW_ON_ERROR))->keyBy('product_id');
    $pass($productLines->count() === 2 && isset($productTax[(int) $service->id], $productTax[(int) $measuredService->id])
        && $productTax[(int) $service->id]['treatment'] === 'exempt'
        && (int) $productTax[(int) $service->id]['tax_rule_id'] === (int) DB::table('accounting_tax_rules')->where('product_id', $service->id)->where('transaction_type', 'sale')->value('id')
        && $productTax[(int) $measuredService->id]['treatment'] === 'percentage'
        && (int) $productTax[(int) $measuredService->id]['tax_rule_id'] === (int) DB::table('accounting_tax_rules')->where('product_id', $measuredService->id)->where('transaction_type', 'sale')->value('id')
        && DB::table('rp_customer_invoices')->where('id', $claim6Invoice)->where('net_amount', '60.01')->exists(),
        'One measured M12 invoice preserves both BOQ product IDs and their distinct signed product-specific tax snapshots.');
    $pass($claimSource > 0 && $claim4Source > 0 && $claim6Invoice > 0 && $claimInvoice > 0
        && DB::table('service_contract_claim_lines')->where('claim_id', $claim4)->where('measurement_id', $measurement)->where('quantity', '4.00000000')->exists()
        && DB::table('rp_customer_invoices')->where('id', $claimInvoice)->where('service_contract_source_id', $claim4Source)->where('service_contract_id', $claimContract)->exists()
        && DB::table('rp_customer_invoices')->where('id', $claim6Invoice)->where('service_contract_source_id', $claimSource)->where('service_contract_id', $claimContract)->exists(),
        'Measured claims respect prior allocation and the remaining four units become one linked M12 invoice.');

    $stage = 'standard M12 invoice posting, M13 settlement, M11/M10 projections';
    $cashAccount = DB::table('accounting_account_mappings')->where('purpose', 'cash')->value('account_id');
    $treasuryAccount = app(TreasuryApi::class)->saveOperationalAccount((int) $writer->id, ['code' => 'M10B-CASH-'.Str::upper(Str::random(5)),
        'name' => 'Synthetic M10 billing cash account', 'kind' => 'cash', 'gl_account_id' => (int) $cashAccount]);
    $open = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoice)->first();
    $collectionData = ['idempotency_key' => $uuid(), 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'account_id' => $treasuryAccount,
        'date' => $invoiceDate, 'amount' => '100.00', 'reference' => 'M10 billing acceptance collection'];
    $allocations = [['open_item_id' => $open->id, 'amount' => '100.00']];
    $collection = app(TreasuryApi::class)->executeCustomerCollection((int) $reviewer->id, $collectionData, $allocations);
    $collectionReplay = app(TreasuryApi::class)->executeCustomerCollection((int) $reviewer->id, $collectionData, $allocations);
    $summary = $invoiceApi->contractFinancialSummary((int) $reviewer->id, $installmentContract, (int) $branch->id, $invoiceDate);
    $pass($summary === ['invoiced' => '100.00', 'credited' => '0.00', 'collected' => '100.00', 'remaining' => '0.00', 'deferred_billed' => '100.00']
        && $collection['movement_id'] === $collectionReplay['movement_id'] && DB::table('treasury_movements')->where('id', $collection['movement_id'])->count() === 1
        && DB::table('rp_settlement_allocations')->where('movement_id', $collection['movement_id'])->count() === 1
        && (int) DB::table('rp_open_items')->where('id', $open->id)->value('settled_amount') === 100,
        'M12 summary reads its own posted invoice and M13 settlement without double-counting collection or deferred revenue.');

    $stage = 'executive dashboard reads actual M10 revenue, invoice, and collection separately';
    $dashboard = app(ExecutiveDashboard::class)->read((int) $reviewer->id, (int) $branch->id, $invoiceDate, $invoiceDate);
    $dashboardAccounting = $dashboard['sections']['accounting']['metrics'] ?? [];
    $dashboardReceivables = $dashboard['sections']['receivables']['metrics'] ?? [];
    $dashboardTreasury = $dashboard['sections']['treasury']['metrics'] ?? [];
    $pass($dashboardAccounting === ['revenue' => '100.00', 'expenses' => '0.00', 'net' => '100.00']
        && $dashboardReceivables === ['customer_invoiced' => '100.00']
        && $dashboardTreasury === ['bank' => '0.00', 'cash' => '100.00']
        && ! array_key_exists('total', $dashboard['sections']['accounting']['metrics'] ?? [])
        && ! array_key_exists('total', $dashboard['sections']['receivables']['metrics'] ?? [])
        && ! array_key_exists('total', $dashboard['sections']['treasury']['metrics'] ?? []),
        'Executive Dashboard reads the real posted revenue, receivable invoice, and cash collection as separate 100.00 measures, with no combined 300.00 total.');
    $reportDashboard = app(ExecutiveDashboard::class)->read((int) $reportOnly->id, (int) $branch->id, $invoiceDate, $invoiceDate);
    $financialSections = array_intersect_key($reportDashboard['sections'], array_flip(['accounting', 'receivables', 'treasury']));
    $pass(count($financialSections) === 3 && collect($financialSections)->every(fn ($section) => ($section['status'] ?? null) === 'unavailable'
        && ! array_key_exists('metrics', $section) && ! array_intersect(['revenue', 'expenses', 'net', 'customer_invoiced', 'supplier_invoiced', 'bank', 'cash'], array_keys($section))),
        'M10 report-only actor receives no accounting, receivables, or treasury amounts from Executive Dashboard.');

    foreach ([$claim6Invoice, $claimInvoice] as $claimInvoiceId) {
        $claimInvoiceRow = DB::table('rp_customer_invoices')->find($claimInvoiceId);
        $invoiceApi->validateCustomerInvoice((int) $reviewer->id, $claimInvoiceId, (int) $claimInvoiceRow->version);
        $claimInvoiceRow = DB::table('rp_customer_invoices')->find($claimInvoiceId);
        $invoiceApi->postCustomerInvoice((int) $reviewer->id, $claimInvoiceId, (int) $claimInvoiceRow->version);
    }
    $during = $counts();
    $pass(collect(['inventory_movements', 'inventory_fifo_layers', 'inventory_balances'])->every(fn ($table) => ($during[$table] ?? null) === ($before[$table] ?? null)),
        'Contract invoicing, recognition and collection create no duplicate stock or FIFO posting.');
    $claimSummary = $invoiceApi->contractFinancialSummary((int) $reviewer->id, $claimContract, (int) $branch->id, $invoiceDate);
    $pass($claimSummary === ['invoiced' => '100.01', 'credited' => '0.00', 'collected' => '0.00', 'remaining' => '100.01', 'deferred_billed' => '0.00'],
        'Measured contract summary reconciles approved claims to the three posted M12 invoices.');
    $pass(app(ServiceContractsApi::class)->recognizedValue((int) $reviewer->id, [(int) $branch->id], $invoiceDate) === '100.00',
        'M10 recognized-value projection reports the posted earning journal once.');

    $stage = 'M10 owner report permission and M12 amount redaction';
    $report = app(ServiceContractReport::class);
    $reject(fn () => $report->show((int) $writer->id, $installmentContract, $invoiceDate), 'M10 contract report requires reports_view.');
    $availableReport = $report->show((int) $reviewer->id, $installmentContract, $invoiceDate);
    $pass($availableReport['financial'] === ['status' => 'available', 'earned' => '100.00', 'deferred_remaining' => '0.00', 'to' => $invoiceDate,
        'invoiced' => '100.00', 'credited' => '0.00', 'collected' => '100.00', 'remaining' => '0.00', 'deferred_billed' => '100.00'],
        'M10 owner report reconciles the real posted installment invoice, treasury collection, deferred revenue and earned revenue as of the same date.');
    $restrictedReport = $report->show((int) $reportOnly->id, $installmentContract, $invoiceDate);
    $pass($restrictedReport['financial']['status'] === 'unavailable'
        && ! array_intersect(['earned', 'deferred_remaining', 'invoiced', 'credited', 'collected', 'remaining', 'deferred_billed'], array_keys($restrictedReport['financial'])),
        'M10 report remains available without M12 ar_view while withholding every receivables amount.');

    $stage = 'M10 signed amendment preserves billed contract commitments';
    $rejectContractAmendment = static function (array $terms, string $label) use ($contracts, $writer, $reviewer, $installmentContract, $today, $uuid, $reject, $pass): void {
        $before = DB::table('service_contracts')->find($installmentContract);
        $activeRevision = (int) $before->active_revision_id;
        $sourceCount = DB::table('service_contract_billing_sources')->where('contract_id', $installmentContract)->count();
        $invoiceCount = DB::table('rp_customer_invoices')->where('service_contract_id', $installmentContract)->count();
        $journalCount = DB::table('accounting_journals')->count();
        $revision = $contracts->amend((int) $writer->id, $installmentContract, (int) $before->version, $uuid(), $terms);
        $signature = app(ServiceContractDocuments::class)->store((int) $writer->id, $installmentContract,
            UploadedFile::fake()->createWithContent('amended-billing-signature.pdf', "%PDF-1.4\nSynthetic amended signature evidence\n%%EOF"), 'signature', 'Rollback-only amendment safety acceptance');
        $row = DB::table('service_contracts')->find($installmentContract);
        $contracts->submit((int) $writer->id, $installmentContract, (int) $row->version);
        $row = DB::table('service_contracts')->find($installmentContract);
        $contracts->approve((int) $reviewer->id, $installmentContract, (int) $row->version);
        $row = DB::table('service_contracts')->find($installmentContract);
        $contracts->sign((int) $writer->id, $installmentContract, (int) $row->version, $signature, $today);
        $row = DB::table('service_contracts')->find($installmentContract);
        $reject(fn () => $contracts->activate((int) $reviewer->id, $installmentContract, (int) $row->version), $label);
        $after = DB::table('service_contracts')->find($installmentContract);
        $pass((int) $after->active_revision_id === $activeRevision && $after->status === 'active'
            && (int) $after->current_revision_id === $revision
            && DB::table('service_contract_revisions')->where('id', $revision)->where('status', 'signed')->exists()
            && DB::table('service_contract_revisions')->where('id', $activeRevision)->where('status', 'active')->exists()
            && DB::table('service_contract_billing_sources')->where('contract_id', $installmentContract)->count() === $sourceCount
            && DB::table('rp_customer_invoices')->where('service_contract_id', $installmentContract)->count() === $invoiceCount
            && DB::table('accounting_journals')->count() === $journalCount,
            'Rejected '.$label.' amendment preserves the active revision and posted M12/M11 sources.');
    };
    $reducedTerms = $baseTerms;
    $reducedTerms['value'] = '99.00';
    $reducedTerms['schedule'][0]['amount'] = '99.00';
    $rejectContractAmendment($reducedTerms, 'A signed amendment cannot reduce value or scheduled installments below already billed 100.00.');
    $changedModeTerms = $baseTerms;
    $changedModeTerms['billing_mode'] = 'measured_work';
    $changedModeTerms['schedule'] = [];
    $changedModeTerms['boq'] = [['code' => 'BOQ-SWITCH', 'description' => 'Synthetic billing mode change', 'product_id' => $service->id, 'quantity' => '10.00000000', 'rate' => '10.00']];
    $rejectContractAmendment($changedModeTerms, 'A billed installment contract cannot switch to measured-work billing.');
    $changedTaxModeTerms = $baseTerms;
    $changedTaxModeTerms['pricing_tax_mode'] = 'inclusive';
    $rejectContractAmendment($changedTaxModeTerms, 'A billed contract cannot change its signed pricing tax mode.');

    $reversal = $billing->proposeRecognitionReversal((int) $writer->id, $recognition, $invoiceDate, $uuid(), 'Synthetic correction of recognized service revenue');
    $reversalJournal = $billing->approveRecognitionReversal((int) $reviewer->id, $reversal, 1);
    $reversalReplay = $billing->approveRecognitionReversal((int) $reviewer->id, $reversal, 2);
    $pass($reversalJournal === $reversalReplay
        && DB::table('service_contract_recognition_reversals')->where('id', $reversal)->where('status', 'posted')->where('amount', '100.00')->count() === 1
        && app(ServiceContractsApi::class)->recognizedValue((int) $reviewer->id, [(int) $branch->id], $invoiceDate) === '0.00',
        'Independent recognition reversal posts and replays once, reversing the dated M10 projection.');

    $correctionsBeforeDenial = DB::table('service_contract_billing_corrections')->count();
    $creditNotesBeforeDenial = DB::table('rp_credit_notes')->count();
    $reject(fn () => $billing->correctContractBilling((int) $writer->id, $source, $uuid(), '101.00', $invoiceDate, 'Synthetic over-credit must roll back'),
        'Contract billing correction cannot credit more than the invoiced amount.');
    $pass(DB::table('service_contract_billing_corrections')->count() === $correctionsBeforeDenial
        && DB::table('rp_credit_notes')->count() === $creditNotesBeforeDenial,
        'Rejected correction rolls back its contract correction row and draft M12 credit note.');
    $credit = $billing->correctContractBilling((int) $writer->id, $source, $uuid(), '10.00', $invoiceDate, 'Synthetic approved contract billing correction');
    $invoiceApi->approveCreditNote((int) $reviewer->id, $credit);
    $creditPosting = $invoiceApi->postCreditNote((int) $reviewer->id, $credit);
    $creditReplay = $invoiceApi->postCreditNote((int) $reviewer->id, $credit);
    $creditedSummary = $invoiceApi->contractFinancialSummary((int) $reviewer->id, $installmentContract, (int) $branch->id, $invoiceDate);
    $correctionId = DB::table('service_contract_billing_corrections')->where('source_id', $source)->value('id');
    $pass($creditPosting['journal_id'] === $creditReplay['journal_id']
        && DB::table('rp_credit_notes')->where('id', $credit)->where('contract_correction_id', $correctionId)->where('status', 'posted')->where('amount', '10.00')->exists()
        && $creditedSummary === ['invoiced' => '100.00', 'credited' => '10.00', 'collected' => '100.00', 'remaining' => '0.00', 'deferred_billed' => '90.00'],
        'M12 posts the real M10-linked credit note once and reconciles credited, collected and deferred balances.');
} catch (Throwable $error) {
    $failure = $error;
    foreach (array_slice($error->getTrace(), 0, 24) as $frame) {
        $file = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : null;
        if ($file !== null && str_starts_with(strtolower($file), strtolower(str_replace('\\', '/', $root).'/'))) {
            $file = substr($file, strlen(str_replace('\\', '/', $root)) + 1);
        } elseif ($file !== null) {
            $file = basename($file);
        }
        $failureTrace[] = ['file' => $file, 'line' => $frame['line'] ?? null, 'class' => $frame['class'] ?? null,
            'function' => $frame['function'] ?? null];
    }
} finally {
    DB::rollBack();
    if (is_dir($privateRoot) && realpath(dirname($privateRoot)) === realpath($taskRoot)
        && str_starts_with(basename($privateRoot), 'm10-billing-private-')) {
        (new Filesystem)->deleteDirectory($privateRoot);
    }
}
$after = $counts();
$activationAfter = DB::table('accounting_activation')->where('id', 1)->first();
$companyAfter = DB::table('companies')->where('id', 1)->first();
$companyRestored = ($companyBefore === null && $companyAfter === null)
    || (is_object($companyBefore) && is_object($companyAfter) && (array) $companyBefore === (array) $companyAfter);
$activationRestored = $activationAfter && (array) $activationAfter === (array) $activationBefore;
$rollback = $before === $after && $activationRestored && $companyRestored && ! is_dir($privateRoot);
$assertions++;
$checks[] = ['name' => 'M11 setup/activation, company profile, billing, invoice, collection, journals, audit and private scratch all roll back.', 'result' => $rollback ? 'PASS' : 'FAIL'];
if (! $rollback && $failure === null) {
    $failure = new RuntimeException('M10 billing acceptance left fixture data, company profile changes or private scratch behind after rollback.');
}
$receipt = ['all_pass' => $failure === null, 'mode' => 'm10-billing', 'phase' => $stage, 'assertions' => $assertions, 'rollback_verified' => $rollback,
    'database' => ['name' => $identity->database, 'server_address' => $identity->server_address, 'port' => (int) $identity->port,
        'postgres_major' => intdiv((int) $identity->server_version_num, 10000)], 'counts' => ['before' => $before, 'during_fixture' => $during, 'after_rollback' => $after],
    'checks' => $checks, 'failure' => $failure === null ? null : ['class' => $failure::class,
        'message' => mb_substr((string) preg_replace('/(password|secret|token)=\\S+/i', '$1=[redacted]', $failure->getMessage()), 0, 500),
        'trace_without_arguments' => $failureTrace],
    'company_profile' => ['synthetic_fields_set_inside_transaction' => true, 'exact_baseline_restored' => $companyRestored],
    'synthetic_m11_activation' => ['performed' => $activationDuring !== null, 'rolled_back' => $activationRestored,
        'go_live_date' => $activationDuring?->go_live_date, 'zero_opening' => $zeroOpeningActivated]];
$receiptPath = $emit($receipt);
fwrite($failure === null ? STDOUT : STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
fwrite($failure === null ? STDOUT : STDERR, 'RESULT all_pass='.($failure === null ? '1' : '0').' assertions='.$assertions.' rollback_verified='.($rollback ? '1' : '0').' receipt='.$receiptPath.PHP_EOL);
if ($failure !== null) {
    exit(1);
}
