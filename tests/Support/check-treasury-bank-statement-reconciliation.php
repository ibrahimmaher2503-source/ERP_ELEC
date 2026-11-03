<?php

declare(strict_types=1);

// Gate5 bank statement matching uses genuine M11/M12/M13 source flows and rolls the synthetic fixture back.
use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\{AccountingActions, FinancialActivation, OpeningBalances};
use App\Modules\Organization\Domain\{Branch, Company, Employee};
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ServiceContracts\Application\{ServiceContractActions, ServiceContractBilling, ServiceContractDocuments};
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\Treasury\Application\TreasuryStatementActions;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

$root = dirname(__DIR__, 2);
$evidence = $root.'/docs/release/remediation/evidence/gate5';
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$tagRuntime = bin2hex(random_bytes(8));
$privateRoot = $taskRoot.'/treasury-statement-private-'.$tagRuntime;
$storage = $taskRoot.'/treasury-statement-storage-'.$tagRuntime;
$cachePrefix = '.tmp/master-remediation-20261101/treasury-statement-cache-'.$tagRuntime;
$viewsRelative = '.tmp/master-remediation-20261101/treasury-statement-views-'.$tagRuntime;
$stage = 'source preflight';
$checks = [];
$assertions = 0;
$emit = static function (array $receipt) use ($evidence): string {
    if (! is_dir($evidence) && ! mkdir($evidence, 0770, true) && ! is_dir($evidence)) { throw new RuntimeException('Unable to create Gate5 evidence directory.'); }
    $path = $evidence.'/treasury-statement-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    return $path;
};
set_exception_handler(static function (Throwable $error) use (&$stage, &$checks, &$assertions, $emit): void {
    $safe = preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $error->getMessage());
    $receipt = ['all_pass' => false, 'mode' => 'treasury-bank-statement-reconciliation', 'phase' => $stage, 'assertions' => $assertions,
        'rollback_verified' => false, 'checks' => $checks, 'failure' => ['class' => $error::class, 'message' => mb_substr((string) $safe, 0, 500)]];
    try { $path = $emit($receipt); fwrite(STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        fwrite(STDERR, 'RESULT all_pass=0 assertions='.$assertions.' rollback_verified=0 receipt='.$path.PHP_EOL);
    } catch (Throwable) { fwrite(STDERR, "RESULT all_pass=0 assertions={$assertions} rollback_verified=0 receipt_write_failed=1\n"); }
    exit(1);
});
putenv('APP_ENV=testing'); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';
if (! class_exists(TreasuryStatementActions::class)) { throw new RuntimeException('TreasuryStatementActions is not implemented yet.'); }
foreach (['import', 'match', 'unmatch', 'review', 'reopen', 'view', 'download'] as $method) {
    if (! method_exists(TreasuryStatementActions::class, $method)) { throw new RuntimeException('Treasury statement action is missing: '.$method); }
}
$environment = ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'), 'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'), 'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $environment);
$runtime = $storage;
foreach ([$privateRoot, $storage, $taskRoot.'/treasury-statement-cache-'.$tagRuntime, $taskRoot.'/treasury-statement-views-'.$tagRuntime] as $dir) {
    if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) { throw new RuntimeException('Could not create private statement runtime directory.'); }
}
$base = ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'], 'DB_PORT' => (string) $target['port'], 'DB_DATABASE' => $target['database'],
    'DB_USERNAME' => $target['username'], 'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''), 'DB_URL' => '', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'LARAVEL_STORAGE_PATH' => $storage,
    'APP_CONFIG_CACHE' => $cachePrefix.'/config.php', 'APP_ROUTES_CACHE' => $cachePrefix.'/routes.php', 'APP_EVENTS_CACHE' => $cachePrefix.'/events.php',
    'APP_SERVICES_CACHE' => $cachePrefix.'/services.php', 'APP_PACKAGES_CACHE' => $cachePrefix.'/packages.php',
    'APP_VIEW_COMPILED_PATH' => $viewsRelative, 'VIEW_COMPILED_PATH' => $viewsRelative, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'];
foreach ($base as $key => $value) { putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value; }
$app = require $root.'/bootstrap/app.php'; $app->loadEnvironmentFrom('.env.testing'); $app->useStoragePath($storage); $app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $target['connection_host'], 'database.connections.pgsql.port' => $target['port'],
    'database.connections.pgsql.database' => $target['database'], 'database.connections.pgsql.username' => $target['username'],
    'database.connections.pgsql.password' => (string) (getenv('DB_PASSWORD') ?: ''), 'database.connections.pgsql.url' => null,
    'filesystems.disks.local.root' => $privateRoot, 'filesystems.disks.local.visibility' => 'private', 'filesystems.disks.local.throw' => true,
    'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database,host(inet_server_addr()) as server_address,inet_server_port() as port,
    current_setting('data_directory') as data_directory,current_user as current_user,current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receipt = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, $identity, $receipt);
if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) { throw new RuntimeException('Gate5 requires the owned PostgreSQL 17 target.'); }
$pass = static function (bool $condition, string $name) use (&$assertions, &$checks): void {
    $assertions++; $checks[] = ['name' => $name, 'result' => $condition ? 'PASS' : 'FAIL']; if (! $condition) { throw new RuntimeException($name); }
};
$expect = static function (callable $action, int $expected, string $name) use ($pass): void {
    try { $action(); } catch (ValidationException $e) { $pass($expected === 422, $name); return; }
    catch (HttpExceptionInterface $e) { $pass($e->getStatusCode() === $expected, $name); return; }
    $pass(false, $name);
};
$uuid = static fn (): string => (string) Str::uuid();
$grant = static function (User $actor, array $permissions, int $branch): void {
    foreach ($permissions as $permission) { DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => $permission,
        'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]); }
};
$scope = static function (User $actor, int $branch): void {
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $actor->id, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
};
$tables = ['companies', 'branches', 'users', 'customers', 'products', 'organization_employees', 'service_contracts', 'service_contract_revisions',
    'service_contract_documents', 'service_contract_billing_sources', 'service_contract_billing_allocations', 'rp_customer_invoices', 'rp_customer_invoice_lines',
    'rp_open_items', 'rp_customer_advances', 'rp_customer_advance_refunds', 'treasury_accounts', 'treasury_movements', 'accounting_accounts',
    'accounting_account_mappings', 'accounting_tax_rules', 'accounting_periods', 'accounting_opening_batches', 'accounting_opening_lines',
    'accounting_journals', 'accounting_journal_lines', 'system_official_sequences', 'system_official_documents', 'access_grants', 'user_branch_scopes',
    'audit_entries', 'treasury_bank_statements', 'treasury_bank_statement_lines', 'treasury_bank_statement_matches', 'treasury_bank_statement_reviews',
    'treasury_statements', 'treasury_statement_lines', 'treasury_statement_matches', 'treasury_statement_reviews'];
$counts = static function () use ($tables): array {
    $out = []; foreach ($tables as $table) { if (DB::getSchemaBuilder()->hasTable($table)) { $out[$table] = (int) DB::table($table)->count(); } } return $out;
};
$guardSqlReject = static function (callable $action, string $expectedMessage, string $name, callable $state) use ($pass): void {
    $beforeState = $state(); $failure = null; DB::beginTransaction();
    try { $action(); } catch (QueryException $error) { $failure = $error; }
    finally { if (DB::transactionLevel() > 1) { DB::rollBack(); } }
    $afterState = $state();
    $pass($failure !== null && str_contains($failure->getMessage(), $expectedMessage) && $beforeState === $afterState, $name);
};
$before = $counts(); $companyBefore = DB::table('companies')->where('id', 1)->first(); $activationBefore = DB::table('accounting_activation')->where('id', 1)->first();
if (! $activationBefore) { throw new RuntimeException('M11 activation row is missing.'); }
$failure = null; $failureTrace = []; $activationDuring = null; $during = []; $stage = 'rollback-only real M11/M10/M12/M13 statement sources';
DB::beginTransaction();
try {
    if (! $companyBefore) { Company::factory()->create(['id' => 1, 'name' => 'Synthetic statement acceptance entity', 'legal_name' => 'Synthetic statement acceptance entity Ltd.',
        'address' => 'Rollback-only bank statement fixture', 'setup_complete' => true]); }
    else { DB::table('companies')->where('id', 1)->update(['legal_name' => 'Synthetic statement acceptance entity Ltd.', 'address' => 'Rollback-only bank statement fixture',
        'setup_complete' => true, 'updated_at' => now()]); }
    $tag = 'DEMO-EG-STMT-'.Str::upper(Str::random(6)); $branch = Branch::factory()->create(['company_id' => 1, 'code' => $tag]);
    $otherBranch = Branch::factory()->create(['company_id' => 1, 'code' => $tag.'-OTHER']);
    $customer = Customer::factory()->create(['status' => 'active']); $service = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]); $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $manager = User::factory()->create(['active' => true, 'email_verified_at' => now()]); $managerOther = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewerOther = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $viewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]); $denied = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$maker, $reviewer, $manager, $viewer, $denied] as $actor) { $scope($actor, (int) $branch->id); }
    foreach ([$managerOther, $reviewerOther] as $actor) { $scope($actor, (int) $otherBranch->id); }
    $grant($maker, ['M01.employee_manage', 'M01.employee_view', 'M03.customer_view', 'M02.product_view', 'M10.contract_create', 'M10.contract_edit',
        'M10.contract_sign', 'M10.contract_activate', 'M10.contract_view', 'M10.document_manage', 'M10.billing_manage', 'M12.customer_invoice_manage',
        'M11.account_manage', 'M11.setup_manage', 'M11.tax_manage', 'M11.period_open', 'M11.opening_manage', 'M11.tax_calculate', 'M11.source_post',
        'M11.journal_create', 'M11.journal_submit', 'M11.journal_approve', 'M11.journal_post', 'M13.account_manage'], (int) $branch->id);
    $grant($reviewer, ['M01.employee_view', 'M03.customer_view', 'M02.product_view', 'M10.contract_view', 'M10.contract_approve', 'M10.contract_activate',
        'M10.billing_approve', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ar_view', 'M11.report_view', 'M11.tax_calculate',
        'M11.source_post', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_approve', 'M11.journal_post', 'M13.statement_review', 'M13.statement_view'], (int) $branch->id);
    $statementPermissions = ['M03.customer_view', 'M13.statement_manage', 'M13.statement_review', 'M13.statement_view', 'M12.ar_view', 'M13.collection_execute', 'M13.advance_refund',
        'M11.report_view', 'M11.source_post', 'M11.journal_post'];
    $grant($manager, $statementPermissions, (int) $branch->id);
    $grant($managerOther, $statementPermissions, (int) $otherBranch->id);
    $grant($reviewerOther, ['M13.statement_review', 'M13.statement_view'], (int) $otherBranch->id);
    $grant($viewer, ['M13.statement_view'], (int) $branch->id);
    $grant($denied, ['M10.reports_view'], (int) $branch->id);
    foreach ([$maker, $reviewer] as $reader) { foreach (['M03.customer_view', 'M02.product_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $reader->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]); } }
    foreach (['M11.account_manage', 'M11.setup_manage', 'M11.tax_manage', 'M11.period_open', 'M11.opening_manage', 'M11.tax_calculate', 'M11.source_post',
        'M11.journal_create', 'M11.journal_submit', 'M11.journal_approve', 'M11.journal_post', 'M13.account_manage'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $maker->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]); }
    foreach (['M11.report_view', 'M11.tax_calculate', 'M11.source_post', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_approve', 'M11.journal_post'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $reviewer->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]); }
    foreach (['M03.customer_view', 'M11.report_view', 'M11.source_post', 'M11.journal_post', 'M13.collection_execute'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $managerOther->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]); }
    DB::table('access_grants')->insertOrIgnore(['user_id' => $manager->id, 'permission_code' => 'M03.customer_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    $today = now('Africa/Cairo')->toDateString(); $tomorrow = CarbonImmutable::parse($today, 'Africa/Cairo')->addDay()->toDateString();
    Carbon::setTestNow(CarbonImmutable::parse($today, 'Africa/Cairo')->startOfDay()->addHours(12));
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'active' => true, 'starts_on' => $today]); $uuidFn = static fn (): string => (string) Str::uuid();
    $code = static fn (string $purpose): string => 'ST-'.substr(hash('sha256', $tag.':'.$purpose), 0, 24);
    $setup = app(FinancialActivation::class); $accounts = app(AccountingActions::class); $mappings = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) { $mappings[$purpose] = $accounts->account((int) $maker->id, null, 0,
        ['code' => $code($purpose), 'name' => 'Statement fixture '.$label, 'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]); }
    $version = (int) $activationBefore->version; $setup->save((int) $maker->id, $version, ['go_live_date' => $today, 'mappings' => $mappings]); $version++;
    foreach ([['sale', null, 'SALE', 'percentage'], ['purchase', null, 'PURCHASE', 'percentage'], ['sale', $service->id, 'SERVICE', 'exempt']] as [$type, $productId, $suffix, $treatment]) {
        $setup->addTaxRule((int) $maker->id, $version, ['code' => $tag.'-'.$suffix, 'name' => 'Synthetic statement '.$suffix, 'transaction_type' => $type,
            'product_id' => $productId, 'rate' => '0', 'starts_on' => $today, 'ends_on' => null, 'treatment' => $treatment, 'tax_scale' => 2,
            'legal_reference' => 'Rollback-only bank statement fixture', 'purchase_recoverable' => $type === 'purchase']); $version++; }
    $setup->validateTaxTreatment((int) $maker->id, $version, 'Synthetic source fixture uses explicit zero-rate/exempt rules.'); $version++;
    $accounts->period((int) $maker->id, ['name' => 'Synthetic statement '.$tag, 'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
    $opening = app(OpeningBalances::class); $batch = $opening->save((int) $maker->id, $version, $uuidFn(), [], true,
        'Explicit rollback-only synthetic no-opening declaration for bank statement acceptance.');
    $opening->advance((int) $maker->id, $batch, 1, 'submit'); $opening->advance((int) $reviewer->id, $batch, 2, 'approve');
    app(ActivateFinancialPosting::class)->execute((int) $reviewer->id, $batch, 3); $activationDuring = DB::table('accounting_activation')->where('id', 1)->first();
    foreach (['customer_advance' => 'Synthetic customer-advance control', 'service_contract_deferred' => 'Synthetic deferred revenue'] as $purpose => $name) {
        $id = $accounts->account((int) $maker->id, null, 0, ['code' => $code($purpose), 'name' => $name, 'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
        $setup->saveOptionalMapping((int) $maker->id, $purpose, $id); }
    $bankGl = (int) DB::table('accounting_account_mappings')->where('purpose', 'bank')->value('account_id');
    $bankAccount = app(TreasuryApi::class)->saveOperationalAccount((int) $maker->id, ['code' => 'STMT-'.substr(hash('sha256', $tag), 0, 20),
        'name' => 'Synthetic statement bank account', 'kind' => 'bank', 'gl_account_id' => $bankGl]);
    $terms = ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_employee_id' => $employee->id, 'title' => 'عقد اختبار كشف البنك',
        'starts_on' => $today, 'ends_on' => $tomorrow, 'value' => '80.00', 'service_product_id' => $service->id, 'billing_mode' => 'installments',
        'pricing_tax_mode' => 'exclusive', 'terms' => 'عقد اصطناعي لاختبار مطابقة كشف البنك.', 'sla_response_minutes' => 60, 'sla_resolution_minutes' => 120,
        'covered_labor_minutes' => 0, 'coverage' => [['equipment_ref' => $tag.'-EQ', 'site_id' => null, 'preventive_visits' => 0, 'corrective_visits' => 1]],
        'parts' => [], 'excluded_parts' => [], 'boq' => [], 'schedule' => [['code' => 'STMT-INV-01', 'due_date' => $today, 'amount' => '80.00', 'description' => 'فاتورة اختبار']]];
    $contracts = app(ServiceContractActions::class); $contract = $contracts->save((int) $maker->id, null, 0, $uuidFn(), $terms);
    $signature = app(ServiceContractDocuments::class)->store((int) $maker->id, $contract,
        UploadedFile::fake()->createWithContent('statement-signature.pdf', "%PDF-1.4\nSynthetic statement contract\n%%EOF"), 'signature', 'Rollback-only statement test');
    $contractRow = DB::table('service_contracts')->find($contract); $contracts->submit((int) $maker->id, $contract, (int) $contractRow->version);
    $contractRow = DB::table('service_contracts')->find($contract); $contracts->approve((int) $reviewer->id, $contract, (int) $contractRow->version);
    $contractRow = DB::table('service_contracts')->find($contract); $contracts->sign((int) $maker->id, $contract, (int) $contractRow->version, $signature, $today);
    $contractRow = DB::table('service_contracts')->find($contract); $contracts->activate((int) $reviewer->id, $contract, (int) $contractRow->version);
    $billing = app(ServiceContractBilling::class); $source = $billing->proposeInstallment((int) $maker->id, $contract, 'STMT-INV-01', $uuidFn(),
        ['invoice_date' => $today, 'due_date' => $today, 'tax_mode' => 'exclusive', 'reference' => 'Synthetic real M12 invoice for bank statement', 'amount' => '80.00']);
    $billing->approveSource((int) $reviewer->id, $source, 1); $invoiceId = $billing->invoice((int) $maker->id, $source, $uuidFn());
    $rp = app(ReceivablesPayablesApi::class); $invoice = DB::table('rp_customer_invoices')->find($invoiceId);
    $rp->validateCustomerInvoice((int) $reviewer->id, $invoiceId, (int) $invoice->version); $invoice = DB::table('rp_customer_invoices')->find($invoiceId);
    $invoicePost = $rp->postCustomerInvoice((int) $reviewer->id, $invoiceId, (int) $invoice->version);
    $openId = (int) DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoiceId)->value('id');
    if (! $invoicePost['journal_id'] || ! DB::table('rp_open_items')->where('id', $openId)->where('remaining_amount', '80.00')->exists()) {
        throw new RuntimeException('Real M10/M12 invoice did not create the synthetic bank-reconciliation receivable.'); }
    $treasury = app(TreasuryApi::class); $rpCollection = $treasury->executeCustomerCollection((int) $manager->id,
        ['idempotency_key' => $uuidFn(), 'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'account_id' => $bankAccount,
            'date' => $today, 'amount' => '100.00', 'reference' => 'Synthetic bank collection 100', 'allow_advance' => true], [['open_item_id' => $openId, 'amount' => '80.00']]);
    $mainAdvance = (int) DB::table('rp_customer_advances')->where('movement_id', $rpCollection['movement_id'])->value('id');
    if ($mainAdvance < 1 || ! DB::table('rp_customer_advances')->where('id', $mainAdvance)->where('original_amount', '20.00')->exists()) {
        throw new RuntimeException('Real M13 collection did not record its 20.00 advance excess.'); }
    $fortyCollection = $treasury->executeCustomerCollection((int) $manager->id,
        ['idempotency_key' => $uuidFn(), 'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'account_id' => $bankAccount,
            'date' => $today, 'amount' => '40.00', 'reference' => 'Synthetic additional bank source 40', 'allow_advance' => true], []);
    $sixtyCollection = $treasury->executeCustomerCollection((int) $manager->id,
        ['idempotency_key' => $uuidFn(), 'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'account_id' => $bankAccount,
            'date' => $today, 'amount' => '60.00', 'reference' => 'Synthetic additional bank source 60', 'allow_advance' => true], []);
    Carbon::setTestNow(CarbonImmutable::parse($tomorrow, 'Africa/Cairo')->startOfDay()->addHours(12));
    $mainRefund = $treasury->refundCustomerAdvance((int) $manager->id, $mainAdvance, $bankAccount, '20.00', $today, $uuidFn(), 'Synthetic bank advance refund 20');
    if (! DB::table('treasury_movements')->where('id', $mainRefund['movement_id'])->where('kind', 'customer_advance_refund')
        ->where('amount', '20.00')->where('account_id', $bankAccount)->exists()) { throw new RuntimeException('Real M13 refund source movement is missing.'); }
    $pass(DB::table('treasury_movements')->whereIn('id', [$rpCollection['movement_id'], $fortyCollection['movement_id'], $sixtyCollection['movement_id'], $mainRefund['movement_id']])
        ->where('branch_id', $branch->id)->where('account_id', $bankAccount)->where('movement_date', $today)->count() === 4,
        'All four collection/refund sources are actual movements dated to the statement bank account and branch.');
    $mainCsv = "external_id,date,amount,reference\r\nBANK-PART-40,{$today},40.00,Synthetic bank line for a partial movement\r\nBANK-PART-60,{$today},60.00,Synthetic bank line for remaining movement\r\nBANK-COMBINED-100,{$today},100.00,Synthetic bank line for two real collections\r\nBANK-REFUND-20,{$today},-20.00,Synthetic advance refund\r\n";
    $mainData = ['branch_id' => (int) $branch->id, 'account_id' => $bankAccount, 'external_reference' => 'SYNTH-STMT-MAIN-'.$tag,
        'starts_on' => $today, 'ends_on' => $today, 'opening_balance' => '0.00', 'closing_balance' => '180.00'];
    $statementActions = app(TreasuryStatementActions::class); $statementKey = $uuidFn();
    $mainStatement = $statementActions->import((int) $manager->id, $statementKey, UploadedFile::fake()->createWithContent('bank-main.csv', $mainCsv), $mainData);
    $mainReplay = $statementActions->import((int) $manager->id, $statementKey, UploadedFile::fake()->createWithContent('bank-main.csv', $mainCsv), $mainData);
    $pass($mainStatement === $mainReplay, 'Exact statement import replay returns the original immutable statement.');
    $countsAfterImport = $counts();
    $changedCsv = str_replace('Synthetic bank line for a partial movement', 'Changed source reference', $mainCsv);
    $expect(fn () => $statementActions->import((int) $manager->id, $statementKey, UploadedFile::fake()->createWithContent('bank-main.csv', $changedCsv), $mainData), 409,
        'Same import key with changed CSV payload is rejected as a conflict.');
    $expect(fn () => $statementActions->import((int) $manager->id, $uuidFn(), UploadedFile::fake()->createWithContent('bank-main-copy.csv', $mainCsv), $mainData), 409,
        'Duplicate account-period statement import under a changed key is rejected.');
    $badHeaderCsv = "wrong,date,amount,reference\r\nBAD,{$today},100.00,Malformed headers\r\n";
    $expect(fn () => $statementActions->import((int) $manager->id, $uuidFn(), UploadedFile::fake()->createWithContent('bad-header.csv', $badHeaderCsv),
        $mainData + ['external_reference' => 'SYNTH-STMT-HEADER-'.$tag]), 422, 'Unexpected CSV header is rejected.');
    $badAmountCsv = "external_id,date,amount,reference\r\nBAD-AMOUNT,{$today},10.005,Malformed amount\r\n";
    $expect(fn () => $statementActions->import((int) $manager->id, $uuidFn(), UploadedFile::fake()->createWithContent('bad-amount.csv', $badAmountCsv),
        $mainData + ['external_reference' => 'SYNTH-STMT-AMOUNT-'.$tag]), 422, 'CSV money amount with more than two decimal places is rejected.');
    $foreignData = $mainData; $foreignData['external_reference'] = 'SYNTH-STMT-CROSS-'.$tag;
    $expect(fn () => $statementActions->import((int) $managerOther->id, $uuidFn(), UploadedFile::fake()->createWithContent('cross-branch.csv', $mainCsv), $foreignData), 403,
        'Statement import cannot cross the manager branch scope.');
    $expect(fn () => $statementActions->import((int) $denied->id, $uuidFn(), UploadedFile::fake()->createWithContent('denied.csv', $mainCsv),
        $mainData + ['external_reference' => 'SYNTH-STMT-DENIED-'.$tag]), 403, 'Actor without statement_manage cannot import a bank statement.');
    $pass($countsAfterImport === $counts(), 'Changed-key duplicates, malformed CSV, and authorization denials create no extra statement, file source, or journal.');

    $mainView = $statementActions->view((int) $viewer->id, $mainStatement);
    $mainMeta = $mainView['statement']; $lineByExternal = collect($mainView['lines'])->keyBy('external_id');
    $collectionLine = $lineByExternal['BANK-PART-40']; $remainingMovementLine = $lineByExternal['BANK-PART-60'];
    $combinedCollectionLine = $lineByExternal['BANK-COMBINED-100']; $refundLine = $lineByExternal['BANK-REFUND-20'];
    $cashGl = (int) DB::table('accounting_account_mappings')->where('purpose', 'cash')->value('account_id');
    $cashAccount = $treasury->saveOperationalAccount((int) $maker->id, ['code' => 'STMT-CASH-'.substr(hash('sha256', $tag), 0, 18),
        'name' => 'Synthetic other account for SQL source guard', 'kind' => 'cash', 'gl_account_id' => $cashGl]);
    $wrongAccountCollection = $treasury->executeCustomerCollection((int) $manager->id,
        ['idempotency_key' => $uuidFn(), 'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'account_id' => $cashAccount,
            'date' => $today, 'amount' => '40.00', 'reference' => 'Synthetic wrong-account source', 'allow_advance' => true], []);
    $statementMatchState = static fn (): array => ['counts' => $counts(), 'matches' => DB::table('treasury_statement_matches')->where('statement_id', $mainStatement)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    $guardSqlReject(fn () => DB::table('treasury_statement_matches')->insert(['statement_id' => $mainStatement, 'line_id' => $collectionLine['id'],
        'movement_id' => $rpCollection['movement_id'], 'amount' => '40.00', 'reason' => 'Blocked inactive initial match', 'created_by' => $manager->id, 'created_at' => now(),
        'active' => false, 'cleared_by' => $manager->id, 'cleared_at' => now(), 'cleared_reason' => 'Cannot seed inactive match']),
        'New match must be active', 'Direct SQL cannot create an inactive initial match.', $statementMatchState);
    $guardSqlReject(fn () => DB::table('treasury_statement_matches')->insert(['statement_id' => $mainStatement, 'line_id' => $collectionLine['id'],
        'movement_id' => $wrongAccountCollection['movement_id'], 'amount' => '40.00', 'reason' => 'Wrong treasury account source', 'created_by' => $manager->id, 'created_at' => now()]),
        'Statement match must preserve actual line, account, branch, period and signed amount', 'Direct SQL cannot match a movement from the wrong account.', $statementMatchState);
    $download = $statementActions->download((int) $viewer->id, $mainStatement);
    if (! $download instanceof BinaryFileResponse) { throw new RuntimeException('Statement download did not return a private binary response.'); }
    $downloadPath = realpath($download->getFile()->getPathname()); $privatePath = realpath($privateRoot).DIRECTORY_SEPARATOR;
    $downloadBytes = $downloadPath ? file_get_contents($downloadPath) : false;
    $pass(is_string($downloadBytes) && $downloadPath !== false && str_starts_with($downloadPath, $privatePath)
        && ! str_starts_with(strtolower($downloadPath), strtolower(public_path().DIRECTORY_SEPARATOR))
        && hash_equals((string) $mainMeta['file_sha256'], hash('sha256', $downloadBytes)) && $downloadBytes === $mainCsv
        && str_contains(strtolower((string) $download->headers->get('Cache-Control')), 'no-store')
        && strtolower((string) $download->headers->get('X-Content-Type-Options')) === 'nosniff',
        'Uploaded CSV is private, hash-verified, and downloaded with no-store/nosniff headers.');
    $expect(fn () => $statementActions->view((int) $denied->id, $mainStatement), 403, 'M10 report-only actor cannot view bank statement lines or matches.');
    $expect(fn () => $statementActions->download((int) $denied->id, $mainStatement), 403, 'Actor without statement_view cannot download the private CSV.');
    $expect(fn () => $statementActions->view((int) $managerOther->id, $mainStatement), 403, 'Statement line view cannot cross branch scope.');
    $expect(fn () => $statementActions->download((int) $managerOther->id, $mainStatement), 403, 'Private statement download cannot cross branch scope.');

    $beforeMatchCounts = $counts(); $journalCount = (int) DB::table('accounting_journals')->count();
    $expect(fn () => $statementActions->match((int) $manager->id, $mainStatement, (int) $collectionLine['id'], (int) $mainRefund['movement_id'],
        (int) $mainMeta['version'], 'Wrong directional source', '40.00'), 422, 'Signed amount/direction mismatch cannot match a bank line to the refund movement.');
    $pass($beforeMatchCounts === $counts(), 'Rejected cross-direction match creates no reconciliation row.');
    $collectionMatch = $statementActions->match((int) $manager->id, $mainStatement, (int) $collectionLine['id'], (int) $rpCollection['movement_id'],
        (int) $mainMeta['version'], 'Match first part of actual bank receipt', '30.00');
    $mainView = $statementActions->view((int) $viewer->id, $mainStatement);
    $partialLine = collect($mainView['lines'])->keyBy('external_id')['BANK-PART-40'];
    $partialMovement = collect($mainView['movements'])->keyBy('id')[$rpCollection['movement_id']];
    $pass($partialLine['amount'] === '40.00' && $partialLine['matched_amount'] === '30.00' && $partialLine['remaining_amount'] === '10.00'
        && $partialMovement['amount'] === '100.00' && $partialMovement['matched_amount'] === '30.00' && $partialMovement['remaining_amount'] === '70.00'
        && count($mainView['matches']) === 1 && $mainView['matches'][0]['amount'] === '30.00',
        'Partial matching stores positive allocation 30.00 and reports the unchanged 100.00 movement with 70.00 remaining.');
    $beforeCapRows = ['counts' => $counts(), 'statement' => (array) DB::table('treasury_statements')->find($mainStatement),
        'matches' => DB::table('treasury_statement_matches')->where('statement_id', $mainStatement)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    $expect(fn () => $statementActions->match((int) $manager->id, $mainStatement, (int) $combinedCollectionLine['id'], (int) $rpCollection['movement_id'],
        (int) $mainView['statement']['version'], 'Over movement residual capacity', '71.00'), 422, 'Partial allocation cannot exceed remaining movement capacity.');
    $expect(fn () => $statementActions->match((int) $manager->id, $mainStatement, (int) $collectionLine['id'], (int) $fortyCollection['movement_id'],
        (int) $mainView['statement']['version'], 'Over bank line residual capacity', '11.00'), 422, 'Partial allocation cannot exceed remaining bank-line capacity.');
    $pass($beforeCapRows === ['counts' => $counts(), 'statement' => (array) DB::table('treasury_statements')->find($mainStatement),
        'matches' => DB::table('treasury_statement_matches')->where('statement_id', $mainStatement)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()],
        'Over-line and over-movement rejections leave partial match amounts, versions and rows unchanged.');
    $remainingSeventyMatch = $statementActions->match((int) $manager->id, $mainStatement, (int) $combinedCollectionLine['id'], (int) $rpCollection['movement_id'],
        (int) $mainView['statement']['version'], 'Allocate the exact 70.00 remaining movement balance', '70.00');
    $completedMovementView = $statementActions->view((int) $viewer->id, $mainStatement);
    $completedMovement = collect($completedMovementView['movements'])->keyBy('id')[$rpCollection['movement_id']];
    $pass($remainingSeventyMatch > 0 && $completedMovement['amount'] === '100.00' && $completedMovement['matched_amount'] === '100.00'
        && $completedMovement['remaining_amount'] === '0.00' && collect($completedMovementView['lines'])->keyBy('external_id')['BANK-COMBINED-100']['matched_amount'] === '70.00',
        'A 70.00 allocation consumes the exact movement residual after the earlier 30.00 match.');
    $statementActions->unmatch((int) $manager->id, $mainStatement, $remainingSeventyMatch, (int) $completedMovementView['statement']['version'],
        'Restore the movement residual for independent residual review');
    $mainView = $statementActions->view((int) $viewer->id, $mainStatement);
    $restoredPartialMovement = collect($mainView['movements'])->keyBy('id')[$rpCollection['movement_id']];
    $restoredPartialLine = collect($mainView['lines'])->keyBy('external_id')['BANK-PART-40'];
    $pass($restoredPartialMovement['matched_amount'] === '30.00' && $restoredPartialMovement['remaining_amount'] === '70.00'
        && $restoredPartialLine['matched_amount'] === '30.00' && $restoredPartialLine['remaining_amount'] === '10.00',
        'Unmatching the exact residual allocation restores the original partial 30.00/70.00 evidence before review.');
    $partialStatement = $mainView['statement'];
    $partialReviewVersion = (int) $partialStatement['version'];
    $expect(fn () => $statementActions->review((int) $manager->id, $mainStatement, $partialReviewVersion, 'Importer cannot review own statement'), 403,
        'Statement review requires an independent actor from its importer.');
    $statementActions->review((int) $reviewer->id, $mainStatement, $partialReviewVersion, 'Review incomplete bank statement');
    $partialReview = $statementActions->view((int) $viewer->id, $mainStatement);
    $pass($partialReview['statement']['status'] === 'reviewed_with_differences'
        && $partialReview['reconciliation']['unmatched_lines'] === 4 && $partialReview['reconciliation']['unmatched_movements'] === 4
        && $partialReview['matches'][0]['amount'] === '30.00' && $partialReview['lines'][0]['remaining_amount'] === '10.00'
        && $partialReview['movements'][0]['remaining_amount'] === '70.00',
        'Independent review counts partial line/movement residuals as unmatched and preserves the partial allocation.');
    $partialReviewEvidence = array_map(static fn ($review) => [(int) $review['id'], (int) $review['statement_id'], (int) $review['version'],
        (int) $review['reviewer_id'], (string) $review['reviewed_at'], (string) $review['snapshot_sha256'], $review['snapshot']], $partialReview['reviews']);
    $pass(count($partialReviewEvidence) === 1 && (int) $partialReview['reviews'][0]['reviewer_id'] === (int) $reviewer->id
        && preg_match('/^[a-f0-9]{64}$/', (string) $partialReview['reviews'][0]['snapshot_sha256']) === 1,
        'Independent reviewer creates immutable review evidence with a SHA-256 snapshot digest.');
    $reviewedEvidenceState = static fn (): array => ['counts' => $counts(), 'statement' => (array) DB::table('treasury_statements')->find($mainStatement),
        'lines' => DB::table('treasury_statement_lines')->where('statement_id', $mainStatement)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'matches' => DB::table('treasury_statement_matches')->where('statement_id', $mainStatement)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    $guardSqlReject(fn () => DB::table('treasury_statement_lines')->insert(['statement_id' => $mainStatement, 'external_id' => 'SQL-AFTER-REVIEW-LINE',
        'transaction_date' => $today, 'amount' => '1.00', 'reference' => 'Unauthorized post-review line']), 'Reopen statement before changing its evidence',
        'Direct SQL cannot append statement lines after independent review.', $reviewedEvidenceState);
    $guardSqlReject(fn () => DB::table('treasury_statement_matches')->insert(['statement_id' => $mainStatement, 'line_id' => $refundLine['id'],
        'movement_id' => $mainRefund['movement_id'], 'amount' => '20.00', 'reason' => 'Unauthorized post-review match', 'created_by' => $manager->id, 'created_at' => now()]),
        'Reopen statement before changing its evidence', 'Direct SQL cannot append statement matches after independent review.', $reviewedEvidenceState);
    $reviewedStatement = $partialReview['statement'];
    $statementActions->reopen((int) $manager->id, $mainStatement, (int) $reviewedStatement['version'], 'Reopen to complete the missing match');
    $reopenedView = $statementActions->view((int) $viewer->id, $mainStatement);
    $reopenedReviewEvidence = array_map(static fn ($review) => [(int) $review['id'], (int) $review['statement_id'], (int) $review['version'],
        (int) $review['reviewer_id'], (string) $review['reviewed_at'], (string) $review['snapshot_sha256'], $review['snapshot']], $reopenedView['reviews']);
    $pass($reopenedView['statement']['status'] === 'imported' && count($reopenedView['matches']) >= 1
        && $reopenedView['reconciliation']['unmatched_lines'] === 4 && $reopenedView['reconciliation']['unmatched_movements'] === 4
        && $partialReviewEvidence === $reopenedReviewEvidence,
        'Reopening preserves byte-identical review evidence and leaves partial line and movement residuals visible.');
    $currentStatement = $reopenedView['statement'];
    $statementActions->unmatch((int) $manager->id, $mainStatement, $collectionMatch, (int) $currentStatement['version'], 'Clear partial allocation to rematch full line');
    $currentView = $statementActions->view((int) $viewer->id, $mainStatement);
    $afterPartialUnmatchLine = collect($currentView['lines'])->keyBy('external_id')['BANK-PART-40'];
    $afterPartialUnmatchMovement = collect($currentView['movements'])->keyBy('id')[$rpCollection['movement_id']];
    $pass($afterPartialUnmatchLine['amount'] === '40.00' && $afterPartialUnmatchLine['matched_amount'] === '0.00' && $afterPartialUnmatchLine['remaining_amount'] === '40.00'
        && $afterPartialUnmatchMovement['amount'] === '100.00' && $afterPartialUnmatchMovement['matched_amount'] === '0.00' && $afterPartialUnmatchMovement['remaining_amount'] === '100.00'
        && DB::table('treasury_statement_matches')->where('id', $collectionMatch)->where('active', false)->where('amount', '30.00')->exists(),
        'Unmatching restores both original capacities and preserves the immutable 30.00 partial allocation record.');
    $fortyPartMatch = $statementActions->match((int) $manager->id, $mainStatement, (int) $collectionLine['id'], (int) $rpCollection['movement_id'],
        (int) $currentView['statement']['version'], 'Match 40.00 first bank portion from original movement', '40.00');
    $currentView = $statementActions->view((int) $viewer->id, $mainStatement);
    $sixtyPartMatch = $statementActions->match((int) $manager->id, $mainStatement, (int) $remainingMovementLine['id'], (int) $rpCollection['movement_id'],
        (int) $currentView['statement']['version'], 'Match 60.00 remaining bank portion from original movement', '60.00');
    $currentView = $statementActions->view((int) $viewer->id, $mainStatement);
    $fortyCollectionMatch = $statementActions->match((int) $manager->id, $mainStatement, (int) $combinedCollectionLine['id'], (int) $fortyCollection['movement_id'],
        (int) $currentView['statement']['version'], 'First source for combined bank line', '40.00');
    $currentView = $statementActions->view((int) $viewer->id, $mainStatement);
    $sixtyCollectionMatch = $statementActions->match((int) $manager->id, $mainStatement, (int) $combinedCollectionLine['id'], (int) $sixtyCollection['movement_id'],
        (int) $currentView['statement']['version'], 'Second source for combined bank line', '60.00');
    $currentView = $statementActions->view((int) $viewer->id, $mainStatement);
    $refundMatch = $statementActions->match((int) $manager->id, $mainStatement, (int) $refundLine['id'], (int) $mainRefund['movement_id'],
        (int) $currentView['statement']['version'], 'Match actual bank advance refund', '20.00');
    $currentView = $statementActions->view((int) $viewer->id, $mainStatement);
    $statementActions->review((int) $reviewer->id, $mainStatement, (int) $currentView['statement']['version'], 'Review fully matched bank statement');
    $mainFinal = $statementActions->view((int) $viewer->id, $mainStatement);
    $finalLines = collect($mainFinal['lines'])->keyBy('external_id'); $finalMovements = collect($mainFinal['movements'])->keyBy('id');
    $matchTotal = array_reduce($mainFinal['matches'], static fn (string $sum, array $row): string => bcadd($sum, $row['amount'], 2), '0.00');
    $pass($fortyPartMatch > 0 && $sixtyPartMatch > 0 && $fortyCollectionMatch > 0 && $sixtyCollectionMatch > 0 && $refundMatch > 0
        && $mainFinal['statement']['status'] === 'reconciled'
        && $mainFinal['reconciliation']['external_net'] === '180.00' && $mainFinal['reconciliation']['treasury_net'] === '180.00'
        && $mainFinal['reconciliation']['difference'] === '0.00' && $mainFinal['reconciliation']['unmatched_lines'] === 0
        && $mainFinal['reconciliation']['unmatched_movements'] === 0
        && $finalLines['BANK-PART-40']['matched_amount'] === '40.00' && $finalLines['BANK-PART-40']['remaining_amount'] === '0.00'
        && $finalLines['BANK-PART-60']['matched_amount'] === '60.00' && $finalLines['BANK-PART-60']['remaining_amount'] === '0.00'
        && $finalLines['BANK-COMBINED-100']['matched_amount'] === '100.00' && $finalLines['BANK-COMBINED-100']['remaining_amount'] === '0.00'
        && $finalMovements[$rpCollection['movement_id']]['amount'] === '100.00' && $finalMovements[$rpCollection['movement_id']]['matched_amount'] === '100.00'
        && $finalMovements[$fortyCollection['movement_id']]['matched_amount'] === '40.00' && $finalMovements[$sixtyCollection['movement_id']]['matched_amount'] === '60.00'
        && $matchTotal === '220.00'
        && bcadd($mainFinal['statement']['opening_balance'], $mainFinal['reconciliation']['external_net'], 2) === $mainFinal['statement']['closing_balance'],
        'One 100.00 movement splits across 40.00/60.00 bank lines while one 100.00 line combines real 40.00/60.00 collections.');
    $pass(($mainFinal['reconciliation']['scope'] ?? null) === 'branch_cash_flow_only',
        'Statement reconciliation scope is explicitly limited to branch cash flow, not a company-wide account or GL opening balance.');
    $pass($mainFinal['statement']['opening_balance'] === '0.00' && $mainFinal['statement']['closing_balance'] === '180.00'
        && (int) DB::table('accounting_journals')->count() === $journalCount,
        'Captured external opening/closing inputs create no GL opening allocation or statement-generated journal.');

    $stage = 'statement unmatch and final source reconciliation';
    $mainView = $statementActions->view((int) $viewer->id, $mainStatement);
    $statementActions->reopen((int) $manager->id, $mainStatement, (int) $mainView['statement']['version'], 'Reopen for audited unmatch acceptance');
    $mainView = $statementActions->view((int) $viewer->id, $mainStatement);
    $statementActions->unmatch((int) $manager->id, $mainStatement, $refundMatch, (int) $mainView['statement']['version'], 'Undo duplicate bank assignment');
    $unmatchedMain = $statementActions->view((int) $viewer->id, $mainStatement);
    $pass($unmatchedMain['reconciliation']['unmatched_lines'] === 1 && $unmatchedMain['reconciliation']['unmatched_movements'] === 1,
        'Authorized unmatch restores both unmatched bank-line and Treasury-movement evidence.');

    $stage = 'missing statement line retains difference without ledger plugs';
    $branchTwoFirst = $treasury->executeCustomerCollection((int) $managerOther->id,
        ['idempotency_key' => $uuidFn(), 'branch_id' => (int) $otherBranch->id, 'customer_id' => (int) $customer->id, 'account_id' => $bankAccount,
            'date' => $today, 'amount' => '30.00', 'reference' => 'Synthetic other branch opening source', 'allow_advance' => true], []);
    $branchTwoAdvance = (int) DB::table('rp_customer_advances')->where('movement_id', $branchTwoFirst['movement_id'])->value('id');
    $branchTwoCollection = $treasury->executeCustomerCollection((int) $managerOther->id,
        ['idempotency_key' => $uuidFn(), 'branch_id' => (int) $otherBranch->id, 'customer_id' => (int) $customer->id, 'account_id' => $bankAccount,
            'date' => $tomorrow, 'amount' => '10.00', 'reference' => 'Synthetic other branch collection', 'allow_advance' => true], []);
    $branchTwoRefund = $treasury->refundCustomerAdvance((int) $managerOther->id, $branchTwoAdvance, $bankAccount, '20.00', $tomorrow, $uuidFn(), 'Synthetic other branch refund');
    $missingCsv = "external_id,date,amount,reference\r\nBRANCH2-COLLECTION-10,{$tomorrow},10.00,Synthetic other branch collection\r\n";
    $missingData = ['branch_id' => (int) $otherBranch->id, 'account_id' => $bankAccount, 'external_reference' => 'SYNTH-STMT-MISSING-'.$tag,
        'starts_on' => $tomorrow, 'ends_on' => $tomorrow, 'opening_balance' => '30.00', 'closing_balance' => '40.00'];
    $missingStatement = $statementActions->import((int) $managerOther->id, $uuidFn(), UploadedFile::fake()->createWithContent('bank-missing-line.csv', $missingCsv), $missingData);
    $missingView = $statementActions->view((int) $managerOther->id, $missingStatement);
    $missingLine = collect($missingView['lines'])->keyBy('external_id')['BRANCH2-COLLECTION-10'];
    $crossStatementState = static fn (): array => ['counts' => $counts(),
        'missing_matches' => DB::table('treasury_statement_matches')->where('statement_id', $missingStatement)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'main_lines' => DB::table('treasury_statement_lines')->where('statement_id', $mainStatement)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    $guardSqlReject(fn () => DB::table('treasury_statement_matches')->insert(['statement_id' => $missingStatement, 'line_id' => $refundLine['id'],
        'movement_id' => $mainRefund['movement_id'], 'amount' => '20.00', 'reason' => 'Cross-statement source line', 'created_by' => $managerOther->id, 'created_at' => now()]),
        'Statement match must preserve actual line, account, branch, period and signed amount', 'Direct SQL cannot attach a line owned by another statement.', $crossStatementState);
    $beforeReviewJournals = (int) DB::table('accounting_journals')->count();
    $statementActions->match((int) $managerOther->id, $missingStatement, (int) $missingLine['id'], (int) $branchTwoCollection['movement_id'],
        (int) $missingView['statement']['version'], 'Match actual partial-day collection');
    $missingView = $statementActions->view((int) $managerOther->id, $missingStatement);
    $statementActions->review((int) $reviewerOther->id, $missingStatement, (int) $missingView['statement']['version'], 'Review statement with omitted refund line');
    $missingReviewed = $statementActions->view((int) $managerOther->id, $missingStatement);
    $missingDifference = (string) $missingReviewed['reconciliation']['difference'];
    $pass($missingReviewed['statement']['status'] === 'reviewed_with_differences'
        && $missingReviewed['reconciliation']['external_net'] === '10.00' && $missingReviewed['reconciliation']['treasury_net'] === '-10.00'
        && in_array($missingDifference, ['20.00', '20', '20.00000000'], true)
        && $missingReviewed['reconciliation']['unmatched_movements'] === 1
        && ($missingReviewed['reconciliation']['scope'] ?? null) === 'branch_cash_flow_only',
        'Missing actual 20.00 refund line preserves a visible 20.00 difference and unmatched movement.');
    $statementActions->reopen((int) $managerOther->id, $missingStatement, (int) $missingReviewed['statement']['version'], 'Reopen missing-line statement for bank follow-up');
    $reopenedMissing = $statementActions->view((int) $managerOther->id, $missingStatement);
    $pass($reopenedMissing['statement']['status'] === 'imported' && $reopenedMissing['reconciliation']['difference'] === $missingDifference
        && $reopenedMissing['reconciliation']['unmatched_movements'] === 1 && (int) DB::table('accounting_journals')->count() === $beforeReviewJournals,
        'Reopen preserves the 20.00 review difference and never creates a balancing GL journal.');

    $crossReviewId = (int) $missingReviewed['reviews'][0]['id'];
    $crossReviewState = static fn (): array => ['counts' => $counts(), 'statement' => (array) DB::table('treasury_statements')->find($mainStatement),
        'reviews' => DB::table('treasury_statement_reviews')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    $mainUnmatchedRow = DB::table('treasury_statements')->find($mainStatement);
    $guardSqlReject(fn () => DB::table('treasury_statements')->where('id', $mainStatement)->update(['status' => 'reviewed_with_differences',
        'version' => (int) $mainUnmatchedRow->version + 1, 'last_review_id' => $crossReviewId]),
        'Statement status requires its own current independent review', 'Direct SQL cannot bind another statement review to this statement status.', $crossReviewState);
    $pass((int) DB::table('accounting_journals')->count() === $beforeReviewJournals,
        'Statement import, match, unmatch, review, and reopen create no GL journals or balancing plugs.');
    $bankBalance = $treasury->reconciliationValues((int) $manager->id, [(int) $branch->id], $tomorrow);
    $mainBankSourceIds = [$rpCollection['movement_id'], $fortyCollection['movement_id'], $sixtyCollection['movement_id'], $mainRefund['movement_id']];
    $pass($bankBalance['bank'] === '180.00' && DB::table('treasury_movements')->whereIn('id', $mainBankSourceIds)->count() === 4
        && DB::table('treasury_movements')->whereIn('id', $mainBankSourceIds)->where('account_id', $bankAccount)->count() === 4,
        'Statement review keeps the actual 180.00 Treasury bank balance from three collections and one refund.');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $during = $counts();
} catch (Throwable $error) {
    $failure = $error;
    foreach (array_slice($error->getTrace(), 0, 18) as $frame) {
        $file = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : null;
        if ($file !== null && str_starts_with(strtolower($file), strtolower(str_replace('\\', '/', $root).'/'))) { $file = substr($file, strlen(str_replace('\\', '/', $root)) + 1); }
        elseif ($file !== null) { $file = basename($file); }
        $failureTrace[] = ['file' => $file, 'line' => $frame['line'] ?? null, 'class' => $frame['class'] ?? null, 'function' => $frame['function'] ?? null];
    }
} finally {
    if (DB::transactionLevel() > 0) { DB::rollBack(); }
    Carbon::setTestNow();
    foreach ([$privateRoot, $storage, $taskRoot.'/treasury-statement-cache-'.$tagRuntime, $taskRoot.'/treasury-statement-views-'.$tagRuntime] as $dir) {
        if (is_dir($dir) && realpath(dirname($dir)) === realpath($taskRoot) && str_starts_with(basename($dir), 'treasury-statement-')) {
            (new Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);
        }
    }
}
$after = $counts(); $activationAfter = DB::table('accounting_activation')->where('id', 1)->first(); $companyAfter = DB::table('companies')->where('id', 1)->first();
$companyRestored = ($companyBefore === null && $companyAfter === null) || (is_object($companyBefore) && is_object($companyAfter) && (array) $companyBefore === (array) $companyAfter);
$scratchRemoved = ! is_dir($privateRoot) && ! is_dir($storage) && ! is_dir($taskRoot.'/treasury-statement-cache-'.$tagRuntime) && ! is_dir($taskRoot.'/treasury-statement-views-'.$tagRuntime);
$rollbackVerified = $before === $after && $activationBefore == $activationAfter && $companyRestored && $scratchRemoved;
$assertions++; $checks[] = ['name' => 'M11 activation, synthetic M10/M12/M13 sources, statement rows, company snapshot, and private upload all roll back.',
    'result' => $rollbackVerified ? 'PASS' : 'FAIL'];
if (! $rollbackVerified && $failure === null) { $failure = new RuntimeException('Bank statement acceptance did not restore every source, company, activation, and private file.'); }
$receipt = ['all_pass' => $failure === null && $rollbackVerified, 'mode' => 'treasury-bank-statement-reconciliation', 'phase' => $stage,
    'assertions' => $assertions, 'rollback_verified' => $rollbackVerified, 'database' => ['name' => $identity->database, 'server_address' => $identity->server_address,
        'port' => (int) $identity->port, 'postgres_major' => intdiv((int) $identity->server_version_num, 10000)],
    'counts' => ['before' => $before, 'during_fixture' => $during, 'after_rollback' => $after], 'checks' => $checks,
    'failure' => $failure === null ? null : ['class' => $failure::class, 'message' => mb_substr((string) preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $failure->getMessage()), 0, 500),
        'trace_without_arguments' => $failureTrace], 'company_profile' => ['exact_baseline_restored' => $companyRestored],
    'm11_activation' => ['performed' => $activationDuring !== null, 'rolled_back' => $activationBefore == $activationAfter, 'go_live_date' => $activationDuring?->go_live_date]];
$path = $emit($receipt); fwrite($receipt['all_pass'] ? STDOUT : STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
fwrite($receipt['all_pass'] ? STDOUT : STDERR, 'RESULT all_pass='.($receipt['all_pass'] ? '1' : '0').' assertions='.$assertions.' rollback_verified='.($rollbackVerified ? '1' : '0').' receipt='.$path.PHP_EOL);
if (! $receipt['all_pass']) { exit(1); }
