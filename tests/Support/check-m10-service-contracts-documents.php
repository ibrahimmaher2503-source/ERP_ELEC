<?php

declare(strict_types=1);

// M10 -> M17 official issuance acceptance. All synthetic owner-action fixtures roll back.
use App\Models\User;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$root = dirname(__DIR__, 2);
$stage = 'source preflight';
$evidenceDirectory = $root.'/docs/release/remediation/evidence/gate2';
$emit = static function (array $receipt) use ($evidenceDirectory): string {
    if (! is_dir($evidenceDirectory) && ! mkdir($evidenceDirectory, 0770, true) && ! is_dir($evidenceDirectory)) {
        throw new RuntimeException('Unable to create Gate2 evidence directory.');
    }
    $path = $evidenceDirectory.'/m10-documents-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);

    return $path;
};
set_exception_handler(static function (Throwable $error) use (&$stage, $emit): void {
    $message = preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $error->getMessage());
    $receipt = ['all_pass' => false, 'mode' => 'm10-m17-official-documents', 'phase' => $stage, 'assertions' => 0,
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
    $root.'/database/migrations/2026_11_01_206000_link_tender_service_contracts.php',
    $root.'/app/Modules/ServiceContracts/Application/ServiceContractDocuments.php',
    $root.'/app/Modules/System/Application/IssueOfficialDocument.php',
];
foreach ($required as $file) {
    if (! is_file($file)) {
        throw new RuntimeException('Required issuance source is missing: '.basename($file));
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
$privateRoot = $taskRoot.'/m10-doc-private-'.bin2hex(random_bytes(8));
$runtimeTag = bin2hex(random_bytes(6));
$appStorage = $taskRoot.'/m10-doc-storage-'.$runtimeTag;
$cacheRelative = '.tmp/master-remediation-20261101/m10-doc-cache-'.$runtimeTag;
$viewsRelative = '.tmp/master-remediation-20261101/m10-doc-views-'.$runtimeTag;
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
foreach ([$privateRoot, $appStorage, $taskRoot.'/m10-doc-cache-'.$runtimeTag, $taskRoot.'/m10-doc-views-'.$runtimeTag] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create private M10 document-check runtime directory.');
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
register_shutdown_function(static function () use ($privateRoot, $taskRoot, $appStorage, $runtimeTag): void {
    if (is_dir($privateRoot) && realpath(dirname($privateRoot)) === realpath($taskRoot)
        && str_starts_with(basename($privateRoot), 'm10-doc-private-')) {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($privateRoot);
    }
    foreach ([$appStorage => 'm10-doc-storage-'.$runtimeTag, $taskRoot.'/m10-doc-cache-'.$runtimeTag => 'm10-doc-cache-'.$runtimeTag,
        $taskRoot.'/m10-doc-views-'.$runtimeTag => 'm10-doc-views-'.$runtimeTag] as $path => $prefix) {
        if (is_dir($path) && realpath(dirname($path)) === realpath($taskRoot) && str_starts_with(basename($path), $prefix)) {
            (new Illuminate\Filesystem\Filesystem)->deleteDirectory($path);
        }
    }
});
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000
    || ! DB::getSchemaBuilder()->hasTable('service_contract_official_documents')
    || ! DB::table('migrations')->where('migration', '2026_11_01_206000_link_tender_service_contracts')->exists()) {
    throw new RuntimeException('M10/M17 issuance requires the exact owned PostgreSQL17 target and migration206.');
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
$tables = ['companies', 'branches', 'users', 'customers', 'products', 'organization_employees', 'service_contracts',
    'service_contract_revisions', 'service_contract_official_documents', 'system_official_documents', 'system_official_sequences',
    'service_contract_activities', 'access_grants', 'user_branch_scopes', 'audit_entries'];
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
$today = now('Africa/Cairo')->toDateString();
DB::beginTransaction();
try {
    $stage = 'synthetic scoped service contract and issuance';
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create(['id' => 1, 'legal_name' => 'Synthetic M10 official document acceptance entity',
            'address' => 'Rollback-only synthetic acceptance address', 'setup_complete' => true]);
    }
    $tag = 'DEMO-EG-M10DOC-'.Str::upper(Str::random(6));
    $branch = Branch::factory()->create(['company_id' => 1, 'code' => $tag]);
    $customer = Customer::factory()->create(['status' => 'active']);
    $service = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $writer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $denied = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$writer, $reviewer, $denied] as $actor) {
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant = static function (User $actor, array $permissions, ?int $branchId): void {
        foreach ($permissions as $permission) {
            DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission, 'branch_id' => $branchId,
                'created_at' => now(), 'updated_at' => now()]);
        }
    };
    $grant($writer, ['M10.contract_create', 'M10.contract_edit', 'M10.contract_sign', 'M10.contract_activate', 'M10.document_manage', 'M10.document_issue'], $branch->id);
    $grant($writer, ['M01.employee_manage', 'M01.employee_view'], $branch->id);
    $grant($writer, ['M03.customer_view', 'M02.product_view'], null);
    $grant($reviewer, ['M10.contract_view', 'M10.contract_approve', 'M10.contract_activate', 'M01.employee_view'], $branch->id);
    $grant($reviewer, ['M03.customer_view', 'M02.product_view'], null);
    $grant($denied, ['M10.contract_view'], $branch->id);
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'active' => true, 'starts_on' => now('Africa/Cairo')->subDay()->toDateString()]);
    $employee->refresh();

    $terms = ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_employee_id' => $employee->id,
        'title' => 'عقد صيانة لإثبات مراجعة الوثيقة الرسمية', 'starts_on' => now('Africa/Cairo')->subDay()->toDateString(),
        'ends_on' => now('Africa/Cairo')->addMonths(2)->toDateString(), 'value' => '100.00', 'service_product_id' => $service->id,
        'billing_mode' => 'installments', 'terms' => 'شروط قبول اصطناعية ضمن معاملة متراجعة.',
        'sla_response_minutes' => 60, 'sla_resolution_minutes' => 240, 'covered_labor_minutes' => 60,
        'coverage' => [['equipment_ref' => 'M10DOC-001', 'site_id' => null, 'preventive_visits' => 1, 'corrective_visits' => 1]],
        'parts' => [], 'excluded_parts' => [], 'boq' => [],
        'schedule' => [['code' => 'DOC-01', 'due_date' => now('Africa/Cairo')->addDays(10)->toDateString(), 'amount' => '100.00', 'description' => 'قسط اختبار']]];
    $actions = app(ServiceContractActions::class);
    $id = $actions->save((int) $writer->id, null, 0, (string) Str::uuid(), $terms);
    $header = DB::table('service_contracts')->find($id);
    $actions->submit((int) $writer->id, $id, (int) $header->version);
    $header = DB::table('service_contracts')->find($id);
    $actions->approve((int) $reviewer->id, $id, (int) $header->version);
    $header = DB::table('service_contracts')->find($id);
    $signatureId = app(ServiceContractDocuments::class)->store((int) $writer->id, $id,
        UploadedFile::fake()->createWithContent('m10-official-signature.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n"), 'signature', 'توقيع اختبار رسمي');
    $header = DB::table('service_contracts')->find($id);
    $actions->sign((int) $writer->id, $id, (int) $header->version, $signatureId, $today);
    $header = DB::table('service_contracts')->find($id);
    $actions->activate((int) $writer->id, $id, (int) $header->version);
    $header = DB::table('service_contracts')->find($id);
    $activeRevisionId = (int) $header->active_revision_id;
    $pass($header->status === 'active' && $activeRevisionId > 0 && (int) $header->current_revision_id === $activeRevisionId,
        'M17 issuance fixture reaches an active M10 revision through independent approval and private signature actions');

    $documents = app(ServiceContractDocuments::class);
    $issued = $documents->issue((int) $writer->id, $id);
    $official = DB::table('system_official_documents')->find($issued['id']);
    $link = DB::table('service_contract_official_documents')->where('revision_id', $activeRevisionId)->first();
    $pass($issued['id'] === ($link->document_id ?? null) && $official && $official->document_type === 'service_contract'
        && $official->source_id === (string) $id && (int) $official->revision === (int) DB::table('service_contract_revisions')->where('id', $activeRevisionId)->value('revision')
        && hash_equals($official->sha256, hash('sha256', base64_decode($official->pdf_base64, true)))
        && $official->issued_by === $writer->id,
        'M17 official PDF is issued only for the exact active contract revision with persisted integrity metadata');
    $retry = $documents->issue((int) $writer->id, $id);
    $pass($retry === $issued && DB::table('system_official_documents')->where('document_type', 'service_contract')->where('source_id', (string) $id)->count() === 1
        && DB::table('service_contract_official_documents')->where('revision_id', $activeRevisionId)->count() === 1,
        'M17 issuance retry returns the same immutable document and does not duplicate its revision link');

    $beforeDenied = ['docs' => DB::table('system_official_documents')->count(), 'links' => DB::table('service_contract_official_documents')->count()];
    $reject(fn () => $documents->issue((int) $denied->id, $id), 'M10.document_issue permission is required independently of contract view', 403);
    $pass($beforeDenied === ['docs' => DB::table('system_official_documents')->count(), 'links' => DB::table('service_contract_official_documents')->count()],
        'Denied official issuance creates no M17 document or service-contract revision link');

    $header = DB::table('service_contracts')->find($id);
    $nextTerms = array_replace($terms, ['title' => 'مراجعة تعديل جديدة للعقد الرسمي']);
    $revision2 = $actions->amend((int) $writer->id, $id, (int) $header->version, (string) Str::uuid(), $nextTerms);
    $header = DB::table('service_contracts')->find($id);
    $beforeDraftIssue = ['docs' => DB::table('system_official_documents')->count(), 'links' => DB::table('service_contract_official_documents')->count()];
    $reject(fn () => $documents->issue((int) $writer->id, $id), 'M17 refuses official issuance while current revision is an unsigned draft', 409);
    $pass((int) $header->active_revision_id === $activeRevisionId && (int) $header->current_revision_id === $revision2
        && DB::table('service_contract_official_documents')->where('revision_id', $activeRevisionId)->exists()
        && ! DB::table('service_contract_official_documents')->where('revision_id', $revision2)->exists()
        && $beforeDraftIssue === ['docs' => DB::table('system_official_documents')->count(), 'links' => DB::table('service_contract_official_documents')->count()],
        'M17 preserves the issued PDF link to its old exact revision and creates nothing for a draft amendment');

    $header = DB::table('service_contracts')->find($id);
    $actions->submit((int) $writer->id, $id, (int) $header->version);
    $header = DB::table('service_contracts')->find($id);
    $actions->approve((int) $reviewer->id, $id, (int) $header->version);
    $header = DB::table('service_contracts')->find($id);
    $signature2 = app(ServiceContractDocuments::class)->store((int) $writer->id, $id,
        UploadedFile::fake()->createWithContent('m10-official-signature-r2.pdf', "%PDF-1.4\n2 0 obj << /Type /Catalog >> endobj\n%%EOF\n"), 'signature', 'توقيع مراجعة ثانية');
    $header = DB::table('service_contracts')->find($id);
    $actions->sign((int) $writer->id, $id, (int) $header->version, $signature2, $today);
    $header = DB::table('service_contracts')->find($id);
    $actions->activate((int) $writer->id, $id, (int) $header->version);
    $header = DB::table('service_contracts')->find($id);
    $issued2 = $documents->issue((int) $writer->id, $id);
    $row2 = DB::table('system_official_documents')->find($issued2['id']);
    $pass((int) $header->active_revision_id === $revision2 && $row2 && (int) $row2->revision === 2
        && DB::table('service_contract_official_documents')->where('revision_id', $revision2)->where('document_id', $issued2['id'])->exists()
        && DB::table('service_contract_official_documents')->where('revision_id', $activeRevisionId)->where('document_id', $issued['id'])->exists()
        && DB::table('system_official_documents')->where('document_type', 'service_contract')->where('source_id', (string) $id)->count() === 2,
        'M17 issues amendment as a new document bound to revision 2 while retaining revision 1 evidence');

    $fixtureCounts = $counts();
} catch (Throwable $error) {
    $failure = $error;
} finally {
    DB::rollBack();
    $after = $counts();
    foreach ([$privateRoot, $appStorage, $taskRoot.'/m10-doc-cache-'.$runtimeTag, $taskRoot.'/m10-doc-views-'.$runtimeTag] as $path) {
        if (is_dir($path) && realpath(dirname($path)) === realpath($taskRoot)
            && str_starts_with(basename($path), $path === $privateRoot ? 'm10-doc-private-' : 'm10-doc-')) {
            (new Illuminate\Filesystem\Filesystem)->deleteDirectory($path);
        }
    }
    $rollbackVerified = $before === $after;
}
$scratchClean = ! is_dir($privateRoot) && ! is_dir($appStorage)
    && ! is_dir($taskRoot.'/m10-doc-cache-'.$runtimeTag) && ! is_dir($taskRoot.'/m10-doc-views-'.$runtimeTag);
$rollbackVerified = $rollbackVerified && $scratchClean;
$passFinal = $failure === null && $rollbackVerified;
$checks[] = ['name' => 'All synthetic source fixtures and official issuance rows roll back; private scratch is removed.', 'result' => $rollbackVerified ? 'PASS' : 'FAIL'];
$assertions++;
if (! $rollbackVerified && $failure === null) {
    $failure = new RuntimeException('M10/M17 acceptance did not roll back all fixture rows.');
    $passFinal = false;
}
$receipt = ['all_pass' => $passFinal, 'mode' => 'm10-m17-official-documents', 'assertions' => $assertions,
    'rollback_verified' => $rollbackVerified, 'private_scratch_clean' => $scratchClean,
    'database' => ['name' => $identity->database, 'server_address' => $identity->server_address, 'port' => (int) $identity->port,
        'postgres_major' => intdiv((int) $identity->server_version_num, 10000)],
    'counts' => ['before' => $before, 'during_fixture' => $fixtureCounts, 'after_rollback' => $after], 'checks' => $checks,
    'failure' => $failure === null ? null : ['class' => $failure::class,
        'message' => mb_substr((string) preg_replace('/(password|secret|token)=\S+/i', '$1=[redacted]', $failure->getMessage()), 0, 500)]];
$receiptPath = $emit($receipt);
fwrite($failure === null ? STDOUT : STDERR, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
fwrite($failure === null ? STDOUT : STDERR, 'RESULT all_pass='.($failure === null && $rollbackVerified ? '1' : '0').' assertions='.$assertions
    .' rollback_verified='.($rollbackVerified ? '1' : '0').' receipt='.$receiptPath.PHP_EOL);
if ($failure !== null || ! $rollbackVerified) {
    exit(1);
}
