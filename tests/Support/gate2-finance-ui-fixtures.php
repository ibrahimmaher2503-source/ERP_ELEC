<?php

declare(strict_types=1);

// Private Gate 2 financial browser fixtures. Only owner APIs/actions create business or ledger rows.
use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\SaveBranch;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractBilling;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = realpath(dirname(__DIR__, 2));
$privateRoot = $root.'/.tmp/master-remediation-20261101/ui-private';
$financeRoot = $privateRoot.'/finance';
$markerPath = $privateRoot.'/.codex-ui-owned.txt';
$marker = 'master-remediation-20261101 Gate2 UI private PostgreSQL marker';
$bootstrapPath = $financeRoot.'/gate2-finance-ui-bootstrap.json';
$setupPath = $financeRoot.'/gate2-finance-ui-fixture.json';
$resolvedPrivate = realpath($privateRoot);
$resolvedFinance = realpath($financeRoot);
$expectedPrivate = realpath($root).DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.'master-remediation-20261101'.DIRECTORY_SEPARATOR.'ui-private';
if ($resolvedPrivate !== $expectedPrivate || $resolvedFinance === false
    || ! str_starts_with($resolvedFinance, rtrim($resolvedPrivate, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Finance fixture paths must resolve beneath the exact private UI directory.');
}
foreach ([$bootstrapPath, $setupPath] as $privateFile) {
    if (is_file($privateFile) && (! realpath($privateFile) || ! str_starts_with(realpath($privateFile), $resolvedFinance.DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('A finance fixture receipt resolves outside its private runtime directory.');
    }
}
$bootstrap = is_file($bootstrapPath) ? json_decode((string) file_get_contents($bootstrapPath), true, 512, JSON_THROW_ON_ERROR) : null;
$database = (string) (getenv('DB_DATABASE') ?: '');
$nonce = (string) (getenv('E2E_FINANCE_DB_NONCE') ?: '');
if (! is_array($bootstrap) || ! is_file($markerPath) || trim((string) file_get_contents($markerPath)) !== $marker
    || ! preg_match('/^[a-f0-9]{64}$/D', $nonce) || ! hash_equals((string) ($bootstrap['ownership_nonce'] ?? ''), $nonce)
    || $database !== ($bootstrap['database'] ?? null) || $database !== 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32)
    || (int) ($bootstrap['port'] ?? 0) !== 49686) {
    throw new RuntimeException('Refusing finance fixtures without the exact private database receipt and nonce.');
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => $bootstrap['app_key'], 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '49686', 'DB_DATABASE' => $database, 'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '',
    'E2E_DB_PORT' => '49686', 'E2E_FINANCE_DB_NONCE' => $nonce,
    'APP_CONFIG_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/config.php',
    'APP_ROUTES_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/routes.php',
    'APP_EVENTS_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/events.php',
    'APP_SERVICES_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/services.php',
    'APP_PACKAGES_CACHE' => '.tmp/master-remediation-20261101/ui-private/finance/cache/packages.php',
    'APP_VIEW_COMPILED_PATH' => $financeRoot.'/views', 'VIEW_COMPILED_PATH' => $financeRoot.'/views',
    'LARAVEL_STORAGE_PATH' => $financeRoot.'/storage', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($financeRoot.'/storage');
$app->make(Kernel::class)->bootstrap();
config(['app.key' => $bootstrap['app_key'], 'database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 49686, 'database.connections.pgsql.database' => $database,
    'database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => '', 'database.connections.pgsql.url' => null,
    'filesystems.disks.local.root' => $financeRoot.'/storage/app/private', 'filesystems.disks.local.visibility' => 'private',
    'filesystems.disks.local.throw' => true, 'cache.default' => 'array', 'session.driver' => 'file']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select r.rolname from pg_database d join pg_roles r on r.oid=d.datdba where d.datname=current_database()) as database_owner");
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = OptimizationRegressionDatabaseGuard::target($root, ['APP_ENV' => 'testing', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '49686', 'E2E_DB_PORT' => '49686', 'DB_DATABASE' => $database, 'DB_USERNAME' => 'postgres', 'E2E_FINANCE_DB_NONCE' => $nonce]);
$nonceRow = DB::selectOne('select nonce from codex_test_guard.ownership_receipts where database = ?', [$database]);
OptimizationRegressionDatabaseGuard::verify($target, $identity, $nonceRow);
if (($identity->database_owner ?? null) !== 'postgres' || (int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
    throw new RuntimeException('Finance fixture writes require PostgreSQL 17 and the exact private target.');
}

$scopedGrants = static function (array $permissions, ?int $branchId): array {
    $rows = [];
    foreach ($permissions as $permission) {
        $scope = PermissionCatalog::DEFINITIONS[$permission][1] ?? null;
        if ($scope === 'company') {
            $rows[] = [$permission, null];
        } elseif ($scope === 'branch' && $branchId !== null) {
            $rows[] = [$permission, $branchId];
        } elseif ($scope !== 'branch') {
            throw new RuntimeException('Finance acceptance grant is missing a supported permission scope.');
        }
    }

    return $rows;
};
$grant = static function (User $actor, array $permissions, ?int $branchId = null) use ($scopedGrants): void {
    foreach ($scopedGrants($permissions, $branchId) as [$permission, $permissionBranchId]) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => $permission,
            'branch_id' => $permissionBranchId, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$scope = static function (User $actor, int $branchId): void {
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $actor->id, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
};
$writeJson = static function (string $path, array $data): void {
    $contents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist the private finance fixture receipt.');
    }
};
$readJson = static function (string $path): ?array {
    return is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
};
$verifyReceipt = static function (array $receipt) use ($nonce, $database): void {
    if (($receipt['database'] ?? null) !== $database || (int) ($receipt['port'] ?? 0) !== 49686
        || ! hash_equals((string) ($receipt['ownership_nonce'] ?? ''), $nonce)) {
        throw new RuntimeException('Finance fixture receipt does not match this exact database nonce.');
    }
};

$mode = (string) ($argv[1] ?? '');
if ($mode === '--setup') {
    $fixture = $readJson($setupPath);
    if (is_array($fixture)) {
        $verifyReceipt($fixture);
        $company = DB::table('companies')->where('id', 1)->where('code', strtoupper((string) ($fixture['company_code'] ?? '')))->first();
        $branch = Branch::query()->whereKey((int) ($fixture['branch_id'] ?? 0))->where('company_id', 1)->first();
        $actor = User::query()->whereKey((int) ($fixture['setup_actor']['id'] ?? 0))->where('email', $fixture['setup_actor']['email'] ?? '')->first();
        $approver = User::query()->whereKey((int) ($fixture['setup_approver']['id'] ?? 0))->where('email', $fixture['setup_approver']['email'] ?? '')->first();
        $accountsReady = DB::table('treasury_accounts')->whereIn('id', [(int) ($fixture['bank_account_id'] ?? 0), (int) ($fixture['cash_account_id'] ?? 0)])
            ->where('active', true)->count() === 2;
        if (! $company || ! $company->setup_complete || ! $branch || ! $branch->active || ! $actor || ! $actor->active || $actor->email_verified_at === null
            || ! $approver || ! $approver->active || $approver->email_verified_at === null || ! $accountsReady
            || ! DB::table('accounting_activation')->where('id', 1)->whereNotNull('activated_at')->exists()) {
            throw new RuntimeException('Existing private finance setup receipt no longer matches its company, branch, actor, or activation.');
        }
        echo 'READY finance base setup verified'.PHP_EOL;
        exit(0);
    }
    if (DB::table('companies')->exists() || DB::table('branches')->exists() || DB::table('users')->exists()) {
        throw new RuntimeException('Refusing to adopt non-empty organization tables in the nonce-named finance database.');
    }
    DB::beginTransaction();
    try {
        $suffix = strtoupper(substr($nonce, 0, 12));
        $password = Str::random(48);
        $actor = User::factory()->create(['name' => 'DEMO-EG Finance UI setup '.$suffix,
            'email' => 'g2ui-finance-setup-'.strtolower($suffix).'@example.test', 'password' => $password, 'active' => true, 'email_verified_at' => now()]);
        $approverPassword = Str::random(48);
        $setupApprover = User::factory()->create(['name' => 'DEMO-EG Finance UI setup approver '.$suffix,
            'email' => 'g2ui-finance-setup-approver-'.strtolower($suffix).'@example.test', 'password' => $approverPassword,
            'active' => true, 'email_verified_at' => now()]);
        $setupPermissions = ['M01.company_update', 'M01.branch_create', 'M01.branch_view', 'M01.employee_view', 'M03.customer_view',
            'M02.product_view', 'M10.contract_create', 'M10.contract_edit', 'M10.contract_sign', 'M10.contract_activate',
            'M10.contract_approve', 'M10.contract_view', 'M10.document_manage', 'M10.billing_manage', 'M10.billing_approve',
            'M11.account_manage', 'M11.setup_manage', 'M11.tax_manage', 'M11.period_open', 'M11.opening_manage',
            'M11.opening_approve', 'M11.financial_activate', 'M11.source_post', 'M11.journal_create', 'M11.journal_submit',
            'M11.journal_approve', 'M11.journal_post', 'M12.customer_invoice_manage', 'M12.customer_invoice_post',
            'M12.credit_note_manage', 'M12.credit_note_approve', 'M12.ar_view', 'M12.advance_apply', 'M12.allocation_manage',
            'M13.account_manage', 'M13.account_view', 'M13.collection_execute', 'M13.advance_refund', 'M13.credit_refund',
            'M13.cash_correct', 'M13.statement_view', 'M13.statement_manage', 'M13.statement_review'];
        $grant($actor, $setupPermissions);
        $grant($setupApprover, ['M11.opening_approve', 'M11.financial_activate']);
        $companyCode = strtoupper('G2FIN-'.substr($nonce, 0, 12));
        app(SaveCompany::class)->execute($actor, ['code' => $companyCode, 'name' => 'شركة قبول مالية اصطناعية معزولة',
            'legal_name' => 'Gate 2 Finance UI Synthetic Entity', 'tax_registration' => null, 'commercial_registration' => null,
            'address' => 'شركة اصطناعية داخل قاعدة قبول خاصة؛ لا تمثل كياناً أو عنواناً حقيقياً.', 'phone' => null, 'email' => null], null);
        $branch = app(SaveBranch::class)->execute($actor, ['code' => 'G2FIN-BR-'.$suffix, 'name' => 'فرع قبول مالي اصطناعي',
            'address' => 'فرع قبول محلي معزول.'], null, null);
        $scope($actor, (int) $branch->id);
        $today = now('Africa/Cairo')->toDateString();
        $customer = Customer::factory()->create(['status' => 'active']);
        $service = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
        $employee = Employee::factory()->create(['branch_id' => $branch->id, 'active' => true, 'starts_on' => $today]);

        $accounts = app(AccountingActions::class);
        $activation = app(FinancialActivation::class);
        $mappings = [];
        foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
            $mappings[$purpose] = $accounts->account((int) $actor->id, null, 0, ['code' => 'G2F-'.substr(hash('sha256', $nonce.':'.$purpose), 0, 24),
                'name' => 'حساب قبول اصطناعي '.$label, 'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]);
        }
        $activationRow = DB::table('accounting_activation')->where('id', 1)->first();
        $activation->save((int) $actor->id, (int) $activationRow->version, ['go_live_date' => $today, 'mappings' => $mappings]);
        $version = (int) $activationRow->version + 1;
        $activation->addTaxRule((int) $actor->id, $version, ['code' => 'G2F-SALE-'.$suffix, 'name' => 'ضريبة خدمة اصطناعية معفاة للاختبار',
            'transaction_type' => 'sale', 'product_id' => $service->id, 'rate' => '0', 'starts_on' => $today, 'ends_on' => null,
            'treatment' => 'exempt', 'tax_scale' => 2, 'legal_reference' => 'إعداد اصطناعي داخل شركة قبول غير حقيقية.', 'purchase_recoverable' => false]);
        $version++;
        $activation->addTaxRule((int) $actor->id, $version, ['code' => 'G2F-PURCHASE-'.$suffix, 'name' => 'ضريبة مشتريات صفرية للاختبار',
            'transaction_type' => 'purchase', 'product_id' => null, 'rate' => '0', 'starts_on' => $today, 'ends_on' => null,
            'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'إعداد اصطناعي داخل شركة قبول غير حقيقية.', 'purchase_recoverable' => true]);
        $version++;
        $activation->validateTaxTreatment((int) $actor->id, $version, 'فحص ضريبي اصطناعي في شركة قبول جديدة بلا بيانات سابقة.');
        $version++;
        $period = $accounts->period((int) $actor->id, ['name' => 'فترة قبول مالية '.$suffix,
            'starts_on' => substr($today, 0, 4).'-01-01', 'ends_on' => substr($today, 0, 4).'-12-31']);
        $opening = app(OpeningBalances::class);
        $batch = $opening->save((int) $actor->id, $version, (string) Str::uuid(), [], true,
            'إقرار عدم وجود أرصدة افتتاحية في كيان القبول المنشأ حديثاً والمعزول.');
        $opening->advance((int) $actor->id, $batch, 1, 'submit');
        $opening->advance((int) $setupApprover->id, $batch, 2, 'approve');
        app(ActivateFinancialPosting::class)->execute((int) $setupApprover->id, $batch, 3);
        foreach (['customer_advance' => ['التزامات مقدمات العملاء', 'liability'], 'service_contract_deferred' => ['إيراد العقود المؤجل', 'liability']] as $purpose => [$label, $kind]) {
            $account = $accounts->account((int) $actor->id, null, 0, ['code' => 'G2F-'.substr(hash('sha256', $nonce.':'.$purpose), 0, 24),
                'name' => $label, 'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]);
            $activation->saveOptionalMapping((int) $actor->id, $purpose, $account);
        }
        $cashGl = (int) DB::table('accounting_account_mappings')->where('purpose', 'cash')->value('account_id');
        $bankGl = (int) DB::table('accounting_account_mappings')->where('purpose', 'bank')->value('account_id');
        $cash = app(TreasuryApi::class)->saveOperationalAccount((int) $actor->id, ['code' => 'G2F-CASH-'.$suffix,
            'name' => 'نقدية قبول اصطناعية', 'kind' => 'cash', 'gl_account_id' => $cashGl]);
        $bank = app(TreasuryApi::class)->saveOperationalAccount((int) $actor->id, ['code' => 'G2F-BANK-'.$suffix,
            'name' => 'بنك قبول اصطناعي', 'kind' => 'bank', 'gl_account_id' => $bankGl]);
        $fixture = ['database' => $database, 'port' => 49686, 'ownership_nonce' => $nonce, 'company_code' => $companyCode,
            'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'service_product_id' => (int) $service->id,
            'employee_id' => (int) $employee->id, 'financial_period_id' => $period, 'opening_batch_id' => $batch,
            'cash_account_id' => (int) $cash, 'bank_account_id' => (int) $bank,
            'setup_actor' => ['id' => (int) $actor->id, 'email' => $actor->email, 'password' => $password],
            'setup_approver' => ['id' => (int) $setupApprover->id, 'email' => $setupApprover->email, 'password' => $approverPassword], 'created_at' => gmdate(DATE_ATOM)];
        DB::commit();
    } catch (Throwable $error) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        throw $error;
    }
    $writeJson($setupPath, $fixture);
    echo 'READY financial company, M11 activation, and private operational accounts created through owner actions.'.PHP_EOL;
    exit(0);
}

if (! preg_match('/^--prepare-run=([a-f0-9]{32})$/D', $mode, $runMatch)) {
    throw new RuntimeException('Specify --setup or --prepare-run=<32 lowercase hex characters>.');
}
$runNonce = $runMatch[1];
$runTag = strtoupper(substr($runNonce, 0, 12));
$base = $readJson($setupPath);
if (! is_array($base)) {
    throw new RuntimeException('Run finance setup first; no private base fixture receipt exists.');
}
$verifyReceipt($base);
$permissions = [
    'maker' => ['M03.customer_view', 'M02.product_view', 'M01.employee_view', 'M10.contract_create', 'M10.contract_edit', 'M10.contract_sign', 'M10.contract_view',
        'M10.document_manage', 'M10.billing_manage', 'M10.coverage_use', 'M11.tax_calculate', 'M12.customer_invoice_manage', 'M12.credit_note_manage', 'M12.ar_view'],
    'approver' => ['M03.customer_view', 'M02.product_view', 'M01.employee_view', 'M10.contract_approve', 'M10.contract_activate', 'M10.billing_approve',
        'M10.contract_view', 'M10.coverage_use', 'M11.tax_calculate', 'M12.customer_invoice_post', 'M12.credit_note_approve', 'M12.ar_view', 'M11.source_post', 'M11.journal_post', 'M11.report_view'],
    'cashier' => ['M03.customer_view', 'M12.ar_view', 'M12.advance_apply', 'M12.allocation_manage', 'M13.collection_execute',
        'M13.advance_refund', 'M13.credit_refund', 'M11.source_post', 'M11.journal_post', 'M13.account_view'],
    'manager' => ['M12.ar_view', 'M13.account_view', 'M13.statement_view', 'M13.statement_manage', 'M11.source_post', 'M11.journal_post'],
    'reviewer' => ['M12.ar_view', 'M13.account_view', 'M13.statement_view', 'M13.statement_review'],
    'corrector' => ['M12.ar_view', 'M13.account_view', 'M13.statement_view', 'M13.cash_correct', 'M11.source_post', 'M11.journal_post'],
    'readonly' => ['M12.ar_view', 'M13.account_view', 'M13.statement_view'],
    'denied' => ['M01.branch_view'],
];
$runPath = $financeRoot.'/gate2-finance-ui-run-'.$runNonce.'.json';
if (is_file($runPath)) {
    if (! realpath($runPath) || ! str_starts_with(realpath($runPath), $resolvedFinance.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Finance acceptance run receipt resolves outside its private runtime directory.');
    }
    $existingRun = $readJson($runPath);
    if (is_array($existingRun) && ($existingRun['state'] ?? null) === 'READY') {
        $verifyReceipt($existingRun);
        foreach ($permissions as $key => $grants) {
            $actorData = $existingRun['actors'][$key] ?? [];
            $actorId = (int) ($actorData['id'] ?? 0);
            $actor = User::query()->whereKey($actorId)->where('email', $actorData['email'] ?? '')
                ->where('name', $actorData['name'] ?? '')->where('active', true)->whereNotNull('email_verified_at')->first();
            $scopes = DB::table('user_branch_scopes')->where('user_id', $actorId)->orderBy('branch_id')->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
            $actual = DB::table('access_grants')->where('user_id', $actorId)->orderBy('permission_code')->get(['permission_code', 'branch_id'])
                ->map(fn ($row) => [$row->permission_code, $row->branch_id === null ? null : (int) $row->branch_id])->all();
            $expected = $scopedGrants($grants, (int) $existingRun['branch_id']);
            usort($actual, static fn (array $a, array $b): int => strcmp($a[0].'|'.(string) $a[1], $b[0].'|'.(string) $b[1]));
            usort($expected, static fn (array $a, array $b): int => strcmp($a[0].'|'.(string) $a[1], $b[0].'|'.(string) $b[1]));
            if (! $actor || $scopes !== [(int) $existingRun['branch_id']] || $actual !== $expected
                || DB::table('user_roles')->where('user_id', $actorId)->exists()) {
                throw new RuntimeException('Existing finance run actor receipt no longer matches its exact active identity, grants, and single branch scope.');
            }
        }
        $runBranch = Branch::query()->whereKey((int) ($existingRun['branch_id'] ?? 0))->where('company_id', 1)
            ->where('code', 'G2FIN-RUN-BR-'.strtoupper($runNonce))->where('active', true)->first();
        $runEmployee = Employee::query()->whereKey((int) ($existingRun['employee_id'] ?? 0))->where('branch_id', $runBranch?->id)
            ->where('code', 'G2FIN-RUN-EMP-'.strtoupper($runNonce))->where('active', true)->first();
        if (! $runBranch || ! $runEmployee) {
            throw new RuntimeException('Existing finance run receipt is not bound to its nonce-specific branch and employee.');
        }
        $credit = DB::table('rp_party_credits')->where('id', (int) ($existingRun['party_credit_id'] ?? 0))->first();
        $advance = DB::table('rp_customer_advances')->where('id', (int) ($existingRun['advance_id'] ?? 0))->first();
        $originalMovement = DB::table('treasury_movements')->where('id', (int) ($existingRun['correction_movement_id'] ?? 0))->first();
        if (! $credit || bccomp((string) $credit->remaining_amount, (string) ($existingRun['expected_party_credit'] ?? ''), 2) !== 0
            || ! $advance || bccomp((string) $advance->original_amount, '60.00', 2) !== 0
            || DB::table('rp_customer_advance_applications')->where('advance_id', $advance->id)->exists()
            || DB::table('rp_customer_advance_refunds')->where('advance_id', $advance->id)->exists()
            || ! $originalMovement || $originalMovement->original_movement_id !== null
            || DB::table('treasury_movements')->where('original_movement_id', $originalMovement->id)->exists()
            || DB::table('treasury_statements')->where('external_reference', $existingRun['statement_reference'] ?? '')->exists()
            || DB::table('rp_party_credit_refunds')->where('credit_id', $credit->id)->exists()) {
            throw new RuntimeException('Existing finance run has already been consumed or changed; preserve it and generate a new run nonce.');
        }
        echo 'READY existing nonce-bound financial acceptance run'.PHP_EOL;
        exit(0);
    }
    throw new RuntimeException('This acceptance run has an incomplete private receipt; preserve it and use a new run nonce.');
}
$run = ['database' => $database, 'port' => 49686, 'ownership_nonce' => $nonce, 'run_nonce' => $runNonce, 'state' => 'PREPARING',
    'branch_id' => null, 'base_branch_id' => (int) $base['branch_id'], 'customer_id' => (int) $base['customer_id'], 'service_product_id' => (int) $base['service_product_id'],
    'employee_id' => null, 'cash_account_id' => (int) $base['cash_account_id'], 'bank_account_id' => (int) $base['bank_account_id'],
    'actors' => [], 'created_at' => gmdate(DATE_ATOM)];
foreach ($permissions as $key => $grants) {
    $actorPassword = Str::random(48);
    $run['actors'][$key] = ['id' => null, 'key' => $key, 'name' => 'DEMO-EG Finance UI '.$key.' '.$runTag,
        'email' => 'g2ui-finance-'.$key.'-'.strtolower($runTag).'@example.test', 'password' => $actorPassword];
}
$writeJson($runPath, $run);
$setupActor = User::query()->whereKey((int) $base['setup_actor']['id'])->where('email', $base['setup_actor']['email'])->firstOrFail();
$runBranchCode = 'G2FIN-RUN-BR-'.strtoupper($runNonce);
$runEmployeeCode = 'G2FIN-RUN-EMP-'.strtoupper($runNonce);
$runBranch = app(SaveBranch::class)->execute($setupActor, ['code' => $runBranchCode,
    'name' => 'فرع تشغيل قبول مالي اصطناعي '.$runTag, 'address' => 'فرع محلي اصطناعي لعملية قبول مستقلة.']);
$run['branch_id'] = (int) $runBranch->id;
$run['branch_code'] = $runBranchCode;
$writeJson($runPath, $run);
$runEmployee = Employee::factory()->create(['code' => $runEmployeeCode, 'name' => 'فني قبول مالي اصطناعي '.$runTag,
    'branch_id' => (int) $runBranch->id, 'starts_on' => now('Africa/Cairo')->toDateString(), 'active' => true]);
$run['employee_id'] = (int) $runEmployee->id;
$run['employee_code'] = $runEmployeeCode;
$writeJson($runPath, $run);
foreach ($permissions as $key => $grants) {
    $actorData = $run['actors'][$key];
    $actor = User::query()->where('email', $actorData['email'])->first();
    if (! $actor) {
        $actor = User::factory()->create(['name' => $actorData['name'], 'email' => $actorData['email'], 'password' => $actorData['password'],
            'active' => true, 'email_verified_at' => now()]);
    } elseif ($actor->name !== $actorData['name'] || ! $actor->active || $actor->email_verified_at === null) {
        throw new RuntimeException('An unreceipted finance actor email already exists; refusing to adopt it.');
    }
    $scope($actor, (int) $run['branch_id']);
    $grant($actor, $grants, (int) $run['branch_id']);
    $run['actors'][$key]['id'] = (int) $actor->id;
    $writeJson($runPath, $run);
}

$today = now('Africa/Cairo')->toDateString();
$customer = Customer::query()->findOrFail((int) $base['customer_id']);
$employee = Employee::query()->findOrFail((int) $run['employee_id']);
$service = Product::query()->findOrFail((int) $base['service_product_id']);
$maker = (int) $run['actors']['maker']['id'];
$approver = (int) $run['actors']['approver']['id'];
$cashier = (int) $run['actors']['cashier']['id'];
$uuid = static fn (): string => (string) Str::uuid();
$contractActions = app(ServiceContractActions::class);
$contractTag = 'G2FIN-'.$runTag;
$contractTerms = ['branch_id' => (int) $run['branch_id'], 'customer_id' => (int) $customer->id,
    'responsible_employee_id' => (int) $employee->id, 'title' => 'عقد خدمة اصطناعي لقبول ردود الخزينة '.$runTag,
    'starts_on' => $today, 'ends_on' => CarbonImmutable::parse($today, 'Africa/Cairo')->addMonths(2)->toDateString(),
    'value' => '210.00', 'service_product_id' => (int) $service->id, 'billing_mode' => 'installments', 'pricing_tax_mode' => 'exclusive',
    'terms' => 'سجل اصطناعي محلي داخل قاعدة قبول معزولة؛ لا يمثل عقد عميل حقيقي.', 'sla_response_minutes' => 60,
    'sla_resolution_minutes' => 120, 'covered_labor_minutes' => 0,
    'coverage' => [['equipment_ref' => $contractTag.'-EQ', 'site_id' => null, 'preventive_visits' => 0, 'corrective_visits' => 0]],
    'preventive_schedule' => [], 'parts' => [], 'excluded_parts' => [], 'boq' => [],
    'schedule' => [['code' => $contractTag.'-INV-01', 'due_date' => $today, 'amount' => '150.00', 'description' => 'فاتورة مصدر الرصيد الدائن'],
        ['code' => $contractTag.'-INV-02', 'due_date' => $today, 'amount' => '60.00', 'description' => 'فاتورة مصدر تطبيق المقدم']]];
$contract = $contractActions->save($maker, null, 0, $uuid(), $contractTerms);
$signature = app(ServiceContractDocuments::class)->store($maker, $contract,
    UploadedFile::fake()->createWithContent('synthetic-finance-ui-signature.pdf', "%PDF-1.4\nSynthetic private acceptance evidence only.\n%%EOF"),
    'signature', 'مستند قبول اصطناعي غير صالح كدليل تجاري خارج بيئة الاختبار.');
$contractRow = DB::table('service_contracts')->find($contract);
$contractActions->submit($maker, $contract, (int) $contractRow->version);
$contractRow = DB::table('service_contracts')->find($contract);
$contractActions->approve($approver, $contract, (int) $contractRow->version);
$contractRow = DB::table('service_contracts')->find($contract);
$contractActions->sign($maker, $contract, (int) $contractRow->version, $signature, $today);
$contractRow = DB::table('service_contracts')->find($contract);
$contractActions->activate($approver, $contract, (int) $contractRow->version);
$billing = app(ServiceContractBilling::class);
$sourceCredit = $billing->proposeInstallment($maker, $contract, $contractTag.'-INV-01', $uuid(), ['invoice_date' => $today,
    'due_date' => $today, 'tax_mode' => 'exclusive', 'reference' => 'فاتورة قبول اصطناعية ضمن مصدر عقد مفعّل', 'amount' => '150.00']);
$billing->approveSource($approver, $sourceCredit, 1);
$creditInvoiceId = $billing->invoice($maker, $sourceCredit, $uuid());
$sourceAdvance = $billing->proposeInstallment($maker, $contract, $contractTag.'-INV-02', $uuid(), ['invoice_date' => $today,
    'due_date' => $today, 'tax_mode' => 'exclusive', 'reference' => 'فاتورة قبول اصطناعية مستقلة لتطبيق المقدم', 'amount' => '60.00']);
$billing->approveSource($approver, $sourceAdvance, 1);
$invoiceId = $billing->invoice($maker, $sourceAdvance, $uuid());
$rp = app(ReceivablesPayablesApi::class);
$creditInvoice = DB::table('rp_customer_invoices')->find($creditInvoiceId);
$rp->validateCustomerInvoice($maker, $creditInvoiceId, (int) $creditInvoice->version);
$creditInvoice = DB::table('rp_customer_invoices')->find($creditInvoiceId);
$rp->postCustomerInvoice($approver, $creditInvoiceId, (int) $creditInvoice->version);
$invoice = DB::table('rp_customer_invoices')->find($invoiceId);
$rp->validateCustomerInvoice($maker, $invoiceId, (int) $invoice->version);
$invoice = DB::table('rp_customer_invoices')->find($invoiceId);
$rp->postCustomerInvoice($approver, $invoiceId, (int) $invoice->version);
$creditOpenItem = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $creditInvoiceId)->first();
$openItem = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoiceId)->first();
if (! $creditOpenItem || bccomp((string) $creditOpenItem->remaining_amount, '150.00', 2) !== 0
    || ! $openItem || bccomp((string) $openItem->remaining_amount, '60.00', 2) !== 0) {
    throw new RuntimeException('The actual signed contract source did not create its full posted customer open item.');
}

$treasury = app(TreasuryApi::class);
$creditCollection = $treasury->executeCustomerCollection($cashier, ['idempotency_key' => $uuid(), 'branch_id' => (int) $run['branch_id'],
    'customer_id' => (int) $customer->id, 'account_id' => (int) $base['cash_account_id'], 'date' => $today,
    'amount' => '100.00', 'reference' => 'تحصيل فعلي جزئي من فاتورة الرصيد الدائن '.$runTag, 'allow_advance' => false],
    [['open_item_id' => (int) $creditOpenItem->id, 'amount' => '100.00']]);
$advanceCollection = $treasury->executeCustomerCollection($cashier, ['idempotency_key' => $uuid(), 'branch_id' => (int) $run['branch_id'],
    'customer_id' => (int) $customer->id, 'account_id' => (int) $base['cash_account_id'], 'date' => $today,
    'amount' => '60.00', 'reference' => 'تحصيل اصطناعي لإنشاء مقدم فعلي '.$runTag, 'allow_advance' => true], []);
$advanceId = (int) DB::table('rp_customer_advances')->where('movement_id', $advanceCollection['movement_id'])->value('id');
if ($advanceId < 1) {
    throw new RuntimeException('Real collection action did not create its permitted customer advance.');
}
$statementCollection = $treasury->executeCustomerCollection($cashier, ['idempotency_key' => $uuid(), 'branch_id' => (int) $run['branch_id'],
    'customer_id' => (int) $customer->id, 'account_id' => (int) $base['bank_account_id'], 'date' => $today,
    'amount' => '5.00', 'reference' => 'تحصيل بنك اصطناعي للمطابقة '.$runTag, 'allow_advance' => false], [['open_item_id' => (int) $openItem->id, 'amount' => '5.00']]);
$correctionCollection = $treasury->executeCustomerCollection($cashier, ['idempotency_key' => $uuid(), 'branch_id' => (int) $run['branch_id'],
    'customer_id' => (int) $customer->id, 'account_id' => (int) $base['cash_account_id'], 'date' => $today,
    'amount' => '7.00', 'reference' => 'تحصيل نقدي اصطناعي لتصحيح مستقل '.$runTag, 'allow_advance' => false], [['open_item_id' => (int) $openItem->id, 'amount' => '7.00']]);
$creditNote = $billing->correctContractBilling($maker, $sourceCredit, $uuid(), '100.00', $today,
    'إشعار قبول اصطناعي مصدره تصحيح فوترة العقد '.$runTag);
$rp->approveCreditNote($approver, $creditNote);
$rp->postCreditNote($approver, $creditNote);
$partyCredit = DB::table('rp_party_credits')->where('credit_note_id', $creditNote)->first();
$advanceRemaining = (string) DB::table('rp_customer_advances')->where('id', $advanceId)->value('original_amount');
if (! $partyCredit || bccomp((string) $partyCredit->original_amount, '50.00', 2) !== 0
    || bccomp($advanceRemaining, '60.00', 2) !== 0) {
    throw new RuntimeException('Actual invoice, collection, advance application, and credit note did not yield the expected refundable balances.');
}
$run = array_merge($run, ['state' => 'READY', 'customer_name' => $customer->name, 'invoice_id' => $invoiceId, 'open_item_id' => (int) $openItem->id,
    'advance_id' => $advanceId, 'advance_collection_movement_id' => (int) $advanceCollection['movement_id'],
    'statement_movement_id' => (int) $statementCollection['movement_id'], 'correction_movement_id' => (int) $correctionCollection['movement_id'],
    'credit_invoice_id' => (int) $creditInvoiceId, 'credit_note_id' => (int) $creditNote, 'party_credit_id' => (int) $partyCredit->id,
    'statement_external_id' => 'G2FIN-'.$runTag.'-BANK-01', 'statement_external_id_second' => 'G2FIN-'.$runTag.'-BANK-02',
    'statement_reference' => 'G2FIN-'.$runTag.'-STATEMENT',
    'statement_amount' => '5.00', 'statement_opening' => '0.00', 'statement_closing' => '5.00',
    'expected_advance_after_apply' => '40.00', 'expected_advance_after_refund' => '25.00', 'expected_party_credit' => '50.00',
    'expected_cash_correction_amount' => '7.00', 'contract_id' => (int) $contract, 'billing_source_id' => (int) $sourceAdvance,
    'signature_document_id' => (int) $signature, 'customer_invoice_journal' => (int) DB::table('rp_customer_invoices')->where('id', $invoiceId)->value('journal_id'),
    'credit_note_journal' => (int) DB::table('rp_credit_notes')->where('id', $creditNote)->value('journal_id'),
    'credit_invoice_collection_movement_id' => (int) $creditCollection['movement_id']]);
$writeJson($runPath, $run);
echo 'READY actual-action finance fixture; invoice, advance, credit, and movements are synthetic and nonce-scoped.'.PHP_EOL;
