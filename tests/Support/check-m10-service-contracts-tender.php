<?php

declare(strict_types=1);

// Real M07 TenderAward -> M10 draft bridge acceptance. Every source fixture rolls back.
use App\Models\User;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\Projects\Application\TenderActions;
use App\Modules\Projects\Application\TenderAwards;
use App\Modules\Projects\Application\TenderBids;
use App\Modules\Projects\Application\TenderDocuments;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 2);
$stage = 'source preflight';
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate2';
$emit = static function (array $receipt) use ($evidenceDirectory): string {
    if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
        throw new RuntimeException('Unable to create Gate2 evidence directory.');
    }
    $path = $evidenceDirectory.'/m10-tender-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);

    return $path;
};
set_exception_handler(static function (Throwable $error) use (&$stage, $emit): void {
    $message = preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $error->getMessage());
    $receipt = ['all_pass' => false, 'mode' => 'm07-tender-to-m10-draft', 'phase' => $stage, 'assertions' => 0,
        'rollback_verified' => false, 'checks' => [], 'failure' => ['class' => $error::class, 'message' => mb_substr((string) $message, 0, 500)]];
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
    $root.'/app/Modules/Projects/Application/TenderAwards.php',
    $root.'/app/Modules/ServiceContracts/Application/ServiceContractsService.php',
    $root.'/database/migrations/2026_11_01_206000_link_tender_service_contracts.php',
    $root.'/database/migrations/2026_11_01_202000_create_service_contracts.php',
];
foreach ($required as $file) {
    if (! is_file($file)) {
        throw new RuntimeException('Required tender-to-contract source is missing: '.basename($file));
    }
}

$environment = ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = OptimizationRegressionDatabaseGuard::target($root, $environment);
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$runtimeTag = bin2hex(random_bytes(6));
$privateRoot = $taskRoot.'/m10-tender-private-'.$runtimeTag;
$appStorage = $taskRoot.'/m10-tender-storage-'.$runtimeTag;
$cacheRelative = '.tmp/master-remediation-20261101/m10-tender-cache-'.$runtimeTag;
$viewsRelative = '.tmp/master-remediation-20261101/m10-tender-views-'.$runtimeTag;
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
foreach ([$privateRoot, $appStorage, $taskRoot.'/m10-tender-cache-'.$runtimeTag, $taskRoot.'/m10-tender-views-'.$runtimeTag] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create isolated tender check runtime directory.');
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
Storage::forgetDisk('local');
DB::purge('pgsql');
register_shutdown_function(static function () use ($taskRoot, $privateRoot, $appStorage, $runtimeTag): void {
    foreach ([$privateRoot, $appStorage, $taskRoot.'/m10-tender-cache-'.$runtimeTag, $taskRoot.'/m10-tender-views-'.$runtimeTag] as $path) {
        if (is_dir($path) && realpath(dirname($path)) === realpath($taskRoot) && str_starts_with(basename($path), 'm10-tender-')) {
            (new Filesystem)->deleteDirectory($path);
        }
    }
});
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
$requiredMigrations = ['2026_10_31_196000_create_projects_tender_capability', '2026_10_31_197000_link_sales_tender_drafts',
    '2026_10_31_198000_make_tender_guard_refresh_safe', '2026_11_01_202000_create_service_contracts',
    '2026_11_01_206000_link_tender_service_contracts'];
if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000
    || DB::table('migrations')->whereIn('migration', $requiredMigrations)->count() !== count($requiredMigrations)) {
    throw new RuntimeException('Tender bridge acceptance requires the owned PostgreSQL17 target and M10/M07 handoff migrations.');
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
        if (! $error instanceof HttpExceptionInterface || ($status !== null && $error->getStatusCode() !== $status)) {
            throw $error;
        }
        $pass(true, $name);

        return;
    }
    $pass(false, $name.' unexpectedly succeeded');
};
$tables = ['companies', 'branches', 'users', 'customers', 'products', 'organization_employees', 'accounting_activation',
    'accounting_tax_rules', 'system_official_documents', 'system_official_sequences', 'project_tenders', 'project_tender_events',
    'project_tender_documents', 'project_tender_technical', 'project_tender_financial', 'project_tender_approvals',
    'project_tender_bid_documents', 'project_tender_submissions', 'project_tender_results', 'project_tender_handoff_plans',
    'project_tender_handoff_approvals', 'project_tender_handoffs', 'service_contracts', 'service_contract_revisions',
    'service_contract_tender_sources', 'service_contract_activities', 'service_contract_official_documents', 'access_grants',
    'user_branch_scopes', 'audit_entries'];
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
$fixtureCounts = $before;
$after = [];
$failure = null;
$rollbackVerified = false;
$scratchClean = false;
$today = now('Africa/Cairo')->toDateString();
$uuid = static fn (): string => (string) Str::uuid();
$grant = static function (User $user, array $codes, ?int $branch): void {
    foreach ($codes as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $user->id, 'permission_code' => $code, 'branch_id' => $branch,
            'created_at' => now(), 'updated_at' => now()]);
    }
};
DB::beginTransaction();
try {
    $stage = 'source-created M07 tender, independent approvals, and M10 draft handoff';
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create(['id' => 1, 'legal_name' => 'Synthetic M10 Tender acceptance entity',
            'address' => 'Rollback-only synthetic tender acceptance address', 'setup_complete' => true]);
    }
    $tag = 'DEMO-EG-M10T-'.Str::upper(Str::random(6));
    $branch = Branch::factory()->create(['company_id' => 1, 'code' => $tag]);
    $customer = Customer::factory()->create(['status' => 'active']);
    $service = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $checker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$maker, $checker] as $user) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant($maker, ['M07.tender_view', 'M07.tender_manage', 'M07.tender_technical_manage', 'M07.tender_financial_manage',
        'M07.tender_financial_view', 'M07.tender_submit', 'M07.tender_award', 'M07.tender_handoff', 'M07.tender_document_view',
        'M10.contract_create', 'M10.contract_view', 'M10.contract_edit', 'M01.employee_manage', 'M01.employee_view'], $branch->id);
    $grant($maker, ['M03.customer_view', 'M02.product_view', 'M11.tax_calculate', 'M11.tax_manage'], null);
    $grant($checker, ['M07.tender_view', 'M07.tender_technical_approve', 'M07.tender_financial_approve', 'M07.tender_financial_view',
        'M07.tender_handoff', 'M10.contract_view', 'M10.contract_approve', 'M01.employee_view'], $branch->id);
    $grant($checker, ['M03.customer_view', 'M02.product_view', 'M11.tax_calculate'], null);
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'active' => true, 'starts_on' => now('Africa/Cairo')->subDay()->toDateString()]);
    $employee->refresh();
    config(['release.tenders_enabled' => true]);

    // Only create a synthetic exemption when this isolated, unactivated test database has no applicable sale rule.
    $activation = DB::table('accounting_activation')->where('id', 1)->first();
    if (! $activation) {
        throw new RuntimeException('Accounting tax contract requires its seeded activation row before tender source acceptance.');
    }
    $saleRuleCount = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
        ->where('r.transaction_type', 'sale')->whereNull('r.product_id')->where('r.starts_on', '<=', $today)
        ->whereRaw('(coalesce(e.ends_on,r.ends_on) IS NULL OR coalesce(e.ends_on,r.ends_on)>=?::date)', [$today])
        ->whereRaw('coalesce(e.withdrawn,false)=false')->count();
    if ($saleRuleCount === 0 && $activation->activated_at === null) {
        app(FinancialActivation::class)->addTaxRule((int) $maker->id, (int) $activation->version, ['code' => $tag.'-EXEMPT',
            'name' => 'Rollback-only synthetic tender exemption', 'transaction_type' => 'sale', 'product_id' => null, 'rate' => '0',
            'starts_on' => $today, 'ends_on' => null, 'treatment' => 'exempt', 'tax_scale' => 2,
            'legal_reference' => 'Rollback-only explicit synthetic exemption for M07 to M10 test; not a company tax input.']);
    } elseif ($saleRuleCount !== 1) {
        throw new RuntimeException('Tender acceptance needs exactly one existing sale tax rule or an unactivated test setup for the explicit synthetic exemption.');
    }

    $start = $today;
    $end = now('Africa/Cairo')->addMonths(3)->toDateString();
    $contractMapping = ['branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'responsible_employee_id' => (int) $employee->id,
        'title' => 'عقد خدمة ناتج عن ترسية مناقصة', 'starts_on' => $start, 'ends_on' => $end, 'value' => '20.00',
        'service_product_id' => (int) $service->id, 'billing_mode' => 'installments', 'terms' => 'مسودة ناتجة من المصدر الفائز دون اعتماد تلقائي.',
        'sla_response_minutes' => 120, 'sla_resolution_minutes' => 480, 'covered_labor_minutes' => 180,
        'coverage' => [['equipment_ref' => $tag.'-CB-001', 'site_id' => null, 'preventive_visits' => 1, 'corrective_visits' => 1]],
        'parts' => [], 'excluded_parts' => [], 'schedule' => [['code' => 'TN-01', 'due_date' => now('Africa/Cairo')->addDays(10)->toDateString(),
            'amount' => '20.00', 'description' => 'قسط مسودة الترسية']], 'boq' => []];
    $actions = app(TenderActions::class);
    $tenderInput = ['external_reference' => $tag.'-REF', 'title' => 'خدمة صيانة لوحات كهربائية',
        'description' => 'مناقصة اصطناعية لقبول مصدر ترسية وربط مسودة العقد.', 'issuer' => 'جهة تجريبية للاختبار',
        'customer_id' => (int) $customer->id, 'category' => 'service', 'branch_id' => (int) $branch->id,
        'responsible_id' => (int) $maker->id, 'published_on' => now('UTC')->subDay()->toDateString(), 'requirements' => 'وثائق فنية وعرض مالي تجريبي.',
        'deadlines' => ['final_at' => now('UTC')->addDays(30)->toIso8601String(), 'technical_at' => null, 'financial_at' => null,
            'clarification_at' => null, 'site_visit_at' => null, 'opening_at' => null]];
    $tenderId = $actions->save((int) $maker->id, null, 0, $uuid(), $tenderInput);
    $actions->transition((int) $maker->id, $tenderId, 1, 'under_review', 'مراجعة مناقصة اختبار مصدر العقد.');
    $actions->transition((int) $maker->id, $tenderId, 2, 'preparing_technical', 'إعداد العرض الفني للاختبار.');
    $upload = static function (int $actor, int $version, string $classification, string $title) use ($tenderId, $uuid): int {
        return app(TenderDocuments::class)->upload($actor, $tenderId, $version, $uuid(), $title, $classification,
            UploadedFile::fake()->createWithContent('m10-tender-evidence.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer <<>>\n%%EOF\n"));
    };
    $technicalDocument = $upload((int) $maker->id, 3, 'technical', 'مواصفات خدمة الاختبار');
    $technicalId = app(TenderBids::class)->technical((int) $maker->id, $tenderId, 4, ['requirements' => 'صيانة لوحات توزيع ضمن حدود الخدمة.', 'documents' => [$technicalDocument],
        'lines' => [['description' => 'خدمة فحص كهربائي', 'quantity' => '1.00000000', 'unit' => 'خدمة',
            'specification' => 'تنفيذ فحص موثق للوحة.', 'compliance' => 'compliant', 'deviation' => '', 'alternative' => '',
            'product_id' => null, 'catalog_reference' => 'EXT-SERVICE-01']]]);
    $technicalRow = DB::table('project_tender_technical')->find($technicalId);
    $pass($technicalRow && (int) $technicalRow->prepared_by === (int) $maker->id
        && hash_equals($technicalRow->sha256, TenderActions::hash(json_decode($technicalRow->snapshot, true, 512, JSON_THROW_ON_ERROR)))
        && Storage::disk('local')->exists(DB::table('project_tender_documents')->find($technicalDocument)->path),
        'Real M07 technical action stores the tender-scoped private evidence and frozen hashed service BOQ');
    app(TenderBids::class)->approve((int) $checker->id, $tenderId, 5, 'technical', 'اعتماد فني مستقل لاختبار المصدر.');
    $financialId = app(TenderBids::class)->financial((int) $maker->id, $tenderId, 6, ['date' => $today, 'mode' => 'exclusive',
        'terms' => 'عرض صالح ضمن فترة المناقصة التجريبية.', 'reference' => 'مصدر تكلفة اصطناعي واضح للاختبار.',
        'labor' => '0.00000000', 'transport' => '0.00000000', 'other' => '0.00000000', 'contingency' => '0.00000000', 'discount' => '0.00',
        'lines' => [['unit_cost' => '10.00000000', 'selling_price' => '20.00000000', 'cost_reference' => 'تكلفة اصطناعية للاختبار.',
            'price_reference' => 'سعر اصطناعي للاختبار دون اعتماد سعر بيع.']]]);
    $financialRow = DB::table('project_tender_financial')->find($financialId);
    $financialSnapshot = json_decode($financialRow->snapshot, true, 512, JSON_THROW_ON_ERROR);
    $pass($financialRow && (int) $financialRow->technical_id === $technicalId && (int) $financialRow->prepared_by === (int) $maker->id
        && ($financialSnapshot['tax']['tax_totals'][0]['treatment'] ?? null) === 'exempt' && $financialSnapshot['tax']['total'] === $financialRow->total
        && DB::table('accounting_journals')->doesntExist(),
        'M07 financial action uses one explicit current test tax rule, keeps exact costs, and posts no journal');
    app(TenderBids::class)->approve((int) $checker->id, $tenderId, 7, 'financial', 'اعتماد مالي مستقل لاختبار المصدر.');
    $bid = app(TenderBids::class)->issue((int) $maker->id, $tenderId, 8);
    $pass(DB::table('project_tender_bid_documents')->where('financial_id', $financialId)->where('document_id', $bid['id'])->exists()
        && DB::table('system_official_documents')->where('id', $bid['id'])->where('document_type', 'tender_bid')->exists(),
        'M07 issues the approved bid as a real immutable official document before submission evidence');
    $submissionEvidence = $upload((int) $maker->id, 9, 'submission', 'إيصال تقديم تجريبي');
    $submittedAt = now('UTC')->subMinute()->toIso8601String();
    $submissionId = app(TenderBids::class)->submit((int) $maker->id, $tenderId, 10, $uuid(), ['method' => 'portal',
        'reference' => 'إيصال تسليم اختبار بوابة', 'submitted_at' => $submittedAt, 'evidence_id' => $submissionEvidence]);
    $pass(DB::table('project_tender_submissions')->where('id', $submissionId)->where('document_id', $bid['id'])->exists()
        && DB::table('project_tenders')->where('id', $tenderId)->where('status', 'submitted')->where('submitted_id', $submissionId)->exists(),
        'M07 submission links the issued bid and separately classified real submission receipt');
    $awardEvidence = $upload((int) $maker->id, 11, 'award', 'قرار ترسية تجريبي');
    $resultId = app(TenderAwards::class)->result((int) $maker->id, $tenderId, 12, ['outcome' => 'awarded',
        'decided_on' => $today, 'reference' => 'قرار ترسية اصطناعي للاختبار.', 'evidence_id' => $awardEvidence,
        'award_amount' => (string) $financialRow->total]);
    $pass(DB::table('project_tender_results')->where('id', $resultId)->where('submission_id', $submissionId)->where('outcome', 'awarded')->exists()
        && DB::table('project_tenders')->where('id', $tenderId)->where('status', 'awarded')->exists(),
        'M07 award outcome follows a submitted source and separate award-classified evidence');
    $destinations = ['contract' => $contractMapping];
    $planId = app(TenderAwards::class)->plan((int) $maker->id, $tenderId, 13, ['result_id' => $resultId,
        'reference' => 'خطة تحويل مسودة خدمة دون تفعيل.', 'destinations' => $destinations]);
    $pass(DB::table('project_tender_handoff_plans')->where('id', $planId)->where('proposed_by', $maker->id)->exists()
        && ! DB::table('project_tender_handoff_approvals')->where('plan_id', $planId)->exists(),
        'M07 proposes a contract destination as an unapproved handoff plan');
    $contractsApi = app(ServiceContractsApi::class);
    $beforeNoApproval = ['contracts' => DB::table('service_contracts')->count(), 'handoffs' => DB::table('project_tender_handoffs')->count()];
    $reject(fn () => $contractsApi->createTenderDraft((int) $maker->id, $tenderId, $resultId, $contractMapping),
        'M10 refuses tender draft creation until an independent handoff plan approval exists', 409);
    $pass($beforeNoApproval === ['contracts' => DB::table('service_contracts')->count(), 'handoffs' => DB::table('project_tender_handoffs')->count()],
        'Unapproved TenderAward creates no M10 contract or handoff row');
    app(TenderAwards::class)->approve((int) $checker->id, $tenderId, 14, $planId, 'اعتماد مستقل لخطة عقد الخدمة.');
    $beforeWrongMapping = DB::table('service_contracts')->count();
    $wrongMapping = array_replace($contractMapping, ['customer_id' => (int) $customer->id + 1]);
    $reject(fn () => $contractsApi->createTenderDraft((int) $maker->id, $tenderId, $resultId, $wrongMapping),
        'M10 rejects changed customer terms outside the exact approved TenderAward mapping', 409);
    $pass(DB::table('service_contracts')->count() === $beforeWrongMapping
        && ! DB::table('service_contract_tender_sources')->where('tender_id', $tenderId)->exists(),
        'Changed TenderAward mapping leaves no M10 draft or source-link row');
    $wrongBranchMapping = array_replace($contractMapping, ['branch_id' => (int) $branch->id + 1]);
    $reject(fn () => $contractsApi->createTenderDraft((int) $maker->id, $tenderId, $resultId, $wrongBranchMapping),
        'M10 rejects changed branch terms outside the exact approved TenderAward mapping', 409);
    $pass(DB::table('service_contracts')->count() === $beforeWrongMapping
        && ! DB::table('service_contract_tender_sources')->where('tender_id', $tenderId)->exists(),
        'Changed branch mapping leaves no M10 draft or source-link row');
    $targets = app(TenderAwards::class)->convert((int) $maker->id, $tenderId, 15, $planId);
    $contractId = (int) $targets['contract'];
    $contract = DB::table('service_contracts')->find($contractId);
    $revision = DB::table('service_contract_revisions')->find($contract?->current_revision_id);
    $source = DB::table('service_contract_tender_sources')->where('contract_id', $contractId)->first();
    $handoff = DB::table('project_tender_handoffs')->where('tender_id', $tenderId)->where('destination', 'contract')->first();
    $pass($handoff && (int) $handoff->plan_id === $planId && (int) $handoff->target_id === $contractId
        && $contract && $contract->status === 'draft' && (int) $contract->branch_id === (int) $branch->id
        && (int) $contract->customer_id === (int) $customer->id && $contract->active_revision_id === null
        && $revision && $revision->status === 'draft' && $revision->approved_by === null && $revision->signed_by === null
        && $revision->signature_document_id === null && $source && (int) $source->tender_id === $tenderId
        && (int) $source->result_id === $resultId && (int) $source->plan_id === $planId
        && DB::table('service_contract_official_documents')->where('revision_id', $revision->id)->doesntExist(),
        'Approved M07 TenderAward creates an exact-source-linked M10 draft without approving, signing, activating, or issuing it');
    $currentTenderVersion = (int) DB::table('project_tenders')->where('id', $tenderId)->value('version');
    $retry = app(TenderAwards::class)->convert((int) $maker->id, $tenderId, $currentTenderVersion, $planId);
    $pass((int) $retry['contract'] === $contractId && DB::table('project_tender_handoffs')->where('tender_id', $tenderId)->where('destination', 'contract')->count() === 1
        && DB::table('service_contracts')->where('id', $contractId)->count() === 1
        && DB::table('service_contract_revisions')->where('contract_id', $contractId)->count() === 1,
        'Repeated approved TenderAward conversion returns the same M10 draft without duplicate source, contract, revision, or handoff rows');
    $fixtureCounts = $counts();
} catch (Throwable $error) {
    $failure = $error;
} finally {
    DB::rollBack();
    $after = $counts();
    foreach ([$privateRoot, $appStorage, $taskRoot.'/m10-tender-cache-'.$runtimeTag, $taskRoot.'/m10-tender-views-'.$runtimeTag] as $path) {
        if (is_dir($path) && realpath(dirname($path)) === realpath($taskRoot) && str_starts_with(basename($path), 'm10-tender-')) {
            (new Filesystem)->deleteDirectory($path);
        }
    }
    $scratchClean = ! is_dir($privateRoot) && ! is_dir($appStorage)
        && ! is_dir($taskRoot.'/m10-tender-cache-'.$runtimeTag) && ! is_dir($taskRoot.'/m10-tender-views-'.$runtimeTag);
    $rollbackVerified = $before === $after && $scratchClean;
}
$checks[] = ['name' => 'Tender, accounting-rule, M10 source, official-document and private evidence fixtures roll back.', 'result' => $rollbackVerified ? 'PASS' : 'FAIL'];
$assertions++;
if (! $rollbackVerified && $failure === null) {
    $failure = new RuntimeException('Tender-to-M10 acceptance left database or private scratch changes after rollback.');
}
$receipt = ['all_pass' => $failure === null, 'mode' => 'm07-tender-to-m10-draft', 'assertions' => $assertions,
    'rollback_verified' => $rollbackVerified, 'private_scratch_clean' => $scratchClean,
    'database' => ['name' => $identity->database, 'server_address' => $identity->server_address, 'port' => (int) $identity->port,
        'postgres_major' => intdiv((int) $identity->server_version_num, 10000)],
    'counts' => ['before' => $before, 'during_fixture' => $fixtureCounts, 'after_rollback' => $after], 'checks' => $checks,
    'failure' => $failure === null ? null : ['class' => $failure::class,
        'message' => mb_substr((string) preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $failure->getMessage()), 0, 500)]];
$receiptPath = $emit($receipt);
fwrite($failure === null ? STDOUT : STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
fwrite($failure === null ? STDOUT : STDERR, 'RESULT all_pass='.($failure === null ? '1' : '0').' assertions='.$assertions
    .' rollback_verified='.($rollbackVerified ? '1' : '0').' receipt='.$receiptPath.PHP_EOL);
if ($failure !== null || ! $rollbackVerified) {
    exit(1);
}
