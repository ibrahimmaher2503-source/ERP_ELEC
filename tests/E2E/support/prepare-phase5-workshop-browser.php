<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
putenv('DB_HOST=127.0.0.1');
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
$expectedPort = (int) (getenv('PHASE5_WORKSHOP_DB_PORT') ?: 55447);
$storageRoot = getenv('PHASE5_WORKSHOP_STORAGE_ROOT');
if ($expectedPort !== 55447 || ! $storageRoot
    || ! str_contains(str_replace('\\', '/', $storageRoot), '/.tmp/phase5-workshop-browser/storage-')) {
    throw new RuntimeException('ABORT: M09 browser fixture requires PostgreSQL 55447 and isolated private workspace storage.');
}
foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
    if (! is_dir($storageRoot.'/'.$directory) && ! mkdir($storageRoot.'/'.$directory, 0700, true) && ! is_dir($storageRoot.'/'.$directory)) {
        throw new RuntimeException('Unable to create isolated M09 browser storage.');
    }
}
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storageRoot);
$app->make(Kernel::class)->bootstrap();

$db = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || $db->db !== 'erp_elec_new_test' || ! in_array($db->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || (int) $db->port !== $expectedPort || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)
    || ! DB::table('migrations')->where('migration', '2026_10_30_190000_link_workshop_invoices')->exists()) {
    throw new RuntimeException('ABORT before M09 browser writes: expected isolated PostgreSQL erp_elec_new_test schema 190 on 127.0.0.1:55447.');
}

$mode = getenv('PHASE5_WORKSHOP_FIXTURE_MODE') ?: 'prepare';
if ($mode === 'readback') {
    $intakeId = (int) (getenv('PHASE5_WORKSHOP_INTAKE_ID') ?: 0);
    $jobId = (int) (getenv('PHASE5_WORKSHOP_JOB_ID') ?: 0);
    $workshopCode = (string) (getenv('PHASE5_WORKSHOP_CODE') ?: '');
    $intake = $intakeId ? DB::table('workshop_intakes')->find($intakeId) : null;
    $job = $jobId ? DB::table('workshop_jobs')->find($jobId) : null;
    $workshop = $workshopCode !== '' ? DB::table('workshops')->where('code', $workshopCode)->first() : null;
    echo json_encode([
        'database' => $db->db,
        'port' => (int) $db->port,
        'workshop' => $workshop ? ['id' => (int) $workshop->id, 'code' => $workshop->code, 'version' => (int) $workshop->version, 'active' => (bool) $workshop->active] : null,
        'intake' => $intake ? ['id' => (int) $intake->id, 'number' => $intake->number, 'status' => $intake->status, 'version' => (int) $intake->version, 'accessories' => $intake->accessories, 'customer_owned' => (bool) $intake->customer_owned, 'branch_id' => (int) $intake->branch_id] : null,
        'job' => $job ? ['id' => (int) $job->id, 'number' => $job->number, 'status' => $job->status, 'version' => (int) $job->version, 'diagnosis' => $job->diagnosis, 'planned_work' => $job->planned_work, 'required_tests' => json_decode($job->required_tests, true), 'technician_id' => $job->technician_id ? (int) $job->technician_id : null] : null,
        'estimates' => $job ? DB::table('workshop_estimates')->where('job_id', $jobId)->orderBy('revision')->get(['id', 'revision', 'billable_amount', 'tax_amount', 'proposed_by'])->map(fn ($row) => (array) $row)->all() : [],
        'approvals' => $job ? DB::table('workshop_estimate_approvals as a')->join('workshop_estimates as e', 'e.id', '=', 'a.estimate_id')->where('e.job_id', $jobId)->get(['a.approved_by', 'a.customer_accepted'])->map(fn ($row) => (array) $row)->all() : [],
        'work_records' => $job ? DB::table('workshop_work_records')->where('job_id', $jobId)->count() : 0,
        'tests' => $job ? DB::table('workshop_tests')->where('job_id', $jobId)->orderBy('id')->get(['name', 'required', 'outcome', 'performed_by'])->map(fn ($row) => (array) $row)->all() : [],
        'verification' => $job ? DB::table('workshop_verifications')->where('job_id', $jobId)->first(['verified_by', 'document_id']) : null,
        'delivery' => $job ? DB::table('workshop_deliveries')->where('job_id', $jobId)->first(['recipient', 'accessories', 'unrepaired', 'delivered_by']) : null,
        'documents' => $intake ? DB::table('workshop_documents')->where('intake_id', $intakeId)->get(['id', 'original_name', 'mime', 'sha256'])->map(fn ($row) => (array) $row)->all() : [],
        'custody_events' => $intake ? DB::table('workshop_custody_events')->where('intake_id', $intakeId)->orderBy('id')->pluck('status')->all() : [],
        'inventory' => [
            'movements' => DB::table('inventory_movements')->count(),
            'fifo_layers' => DB::table('inventory_fifo_layers')->count(),
            'balances' => DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($mode !== 'prepare') {
    throw new RuntimeException('Unsupported M09 browser fixture mode.');
}
if (DB::table('companies')->exists() || DB::table('users')->exists() || DB::table('workshop_intakes')->exists()
    || DB::table('workshops')->exists() || DB::table('inventory_movements')->exists()) {
    throw new RuntimeException('ABORT: M09 browser prepare requires a clean disposable clone with no company, user, workshop or stock rows.');
}
$password = (string) (getenv('PHASE5_WORKSHOP_PASSWORD') ?: '');
$tag = (string) (getenv('PHASE5_WORKSHOP_TAG') ?: '');
if (strlen($password) < 24 || ! preg_match('/^[A-F0-9]{10}$/', $tag)) {
    throw new RuntimeException('M09 browser fixture tag/password environment is missing.');
}

$company = Company::factory()->create([
    'code' => 'DEMO-EG-M09-'.$tag,
    'name' => 'DEMO-EG M09 acceptance company',
    'legal_name' => 'DEMO-EG M09 acceptance company (synthetic)',
    'address' => 'Synthetic test address; not a registered business address.',
    'setup_complete' => true,
]);
$branch = Branch::factory()->create(['code' => 'DEMO-EG-M09-'.$tag, 'name' => 'فرع اختبار الورش '.$tag, 'company_id' => $company->id, 'active' => true]);
$customer = Customer::factory()->create(['code' => 'DEMO-EG-M09-'.$tag, 'name' => 'عميل اختبار ورش '.$tag, 'status' => 'active']);
$actors = [];
foreach ([
    'dispatcher' => ['M09.view', 'M09.manage', 'M09.intake_manage', 'M09.custody', 'M09.job_manage', 'M09.assign', 'M09.estimate_manage', 'M09.billing_request', 'M09.deliver', 'M09.document_manage', 'M09.document_view', 'M09.cost_view', 'M09.reports_view'],
    'technician' => ['M09.view', 'M09.execute', 'M09.labor_record', 'M09.parts_request', 'M09.parts_issue', 'M09.parts_return'],
    'supervisor' => ['M09.view', 'M09.estimate_approve', 'M09.verify', 'M09.document_view', 'M09.cost_view'],
    'outsider' => [],
] as $role => $permissions) {
    $user = User::factory()->create([
        'name' => 'DEMO-EG M09 '.$role.' '.$tag,
        'email' => 'workshop-'.$role.'-'.$tag.'@example.test',
        'password' => Hash::make($password),
        'active' => true,
        'email_verified_at' => now(),
    ]);
    $actors[$role] = ['id' => (int) $user->id, 'email' => $user->email];
    if ($role !== 'outsider') {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($permissions as $permission) {
            DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
foreach ([['dispatcher', 'M03.customer_view'], ['dispatcher', 'M02.product_view'], ['dispatcher', 'M11.tax_manage'], ['dispatcher', 'M11.tax_calculate']] as [$role, $permission]) {
    DB::table('access_grants')->insert(['user_id' => $actors[$role]['id'], 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
}
foreach (['dispatcher', 'technician', 'supervisor'] as $role) {
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $actors[$role]['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
}

$service = Product::factory()->create([
    'sku' => 'DEMO-EG-M09-SVC-'.$tag,
    'name' => 'خدمة فحص وإصلاح قاطع (اختبار فقط)',
    'name_en' => 'Synthetic workshop repair service',
    'kind' => 'service',
    'tracking' => 'none',
    'active' => true,
]);
$activation = DB::table('accounting_activation')->find(1);
app(FinancialActivation::class)->addTaxRule($actors['dispatcher']['id'], (int) $activation->version, [
    'code' => 'M09QA_'.$tag,
    'name' => 'Synthetic M09 browser tax fixture (not statutory)',
    'transaction_type' => 'sale',
    'product_id' => $service->id,
    'rate' => '0',
    'starts_on' => now('Africa/Cairo')->toDateString(),
    'ends_on' => null,
    'treatment' => 'percentage',
    'tax_scale' => 2,
    'legal_reference' => 'SYNTHETIC TEST FIXTURE ONLY; NOT A TAX TREATMENT OR CUSTOMER INPUT.',
]);

$workshopActions = app(WorkshopActions::class);
$workshopId = $workshopActions->saveWorkshop($actors['dispatcher']['id'], null, 0, [
    'code' => 'DEMO-EG-M09-WORKSHOP-'.$tag,
    'name' => 'ورشة اختبار '.$tag,
    'specialty' => 'فحص وإصلاح قواطع كهربائية لأغراض الاختبار فقط.',
    'branch_id' => (int) $branch->id,
    'supervisor_id' => (int) $actors['dispatcher']['id'],
    'warehouse_id' => null,
    'capacity' => 2,
    'schedule' => [['day' => 0, 'start' => '08:00', 'end' => '16:00']],
    'active' => true,
]);
$workshopActions->member($actors['dispatcher']['id'], $workshopId, 1, $actors['technician']['id'], true);

echo json_encode([
    'database' => $db->db,
    'port' => (int) $db->port,
    'tag' => $tag,
    'branch_id' => (int) $branch->id,
    'customer_id' => (int) $customer->id,
    'customer_code' => $customer->code,
    'service_product_id' => (int) $service->id,
    'workshop_id' => $workshopId,
    'workshop_code' => 'DEMO-EG-M09-WORKSHOP-'.$tag,
    'actors' => $actors,
    'inventory_before' => ['movements' => DB::table('inventory_movements')->count(), 'fifo_layers' => DB::table('inventory_fifo_layers')->count(), 'balances' => DB::table('inventory_balances')->count()],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
