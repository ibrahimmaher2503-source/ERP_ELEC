<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use App\Modules\WorkshopRepairs\Application\WorkshopEstimates;
use App\Modules\WorkshopRepairs\Application\WorkshopExecution;
use App\Modules\WorkshopRepairs\Application\WorkshopFinance;
use App\Modules\ReceivablesPayables\Application\ReceivablesPayablesService;
use App\Modules\Products\Domain\Product;
use App\Modules\Accounting\Application\FinancialActivation;
use Carbon\Carbon;
use App\Modules\WorkshopRepairs\Domain\Workshop;
use App\Modules\WorkshopRepairs\Domain\WorkshopIntake;
use App\Modules\WorkshopRepairs\Domain\WorkshopJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Database\Seeders\WorkshopDemoSeeder;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_HOST=127.0.0.1');
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
putenv('DB_PORT=55448');
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = '55448';
putenv('DB_DATABASE=erp_elec_new_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
putenv('DB_USERNAME=postgres');
$_ENV['DB_USERNAME'] = $_SERVER['DB_USERNAME'] = 'postgres';
putenv('DB_PASSWORD=');
$_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = '';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
Carbon::setTestNow(CarbonImmutable::now('Africa/Cairo')->addYear()->startOfDay()->subDay()->addHours(12));

$identity = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || $identity->db !== 'erp_elec_new_test'
    || ! in_array($identity->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || (int) $identity->port !== 55448
    || ! DB::table('migrations')->where('migration', '2026_10_30_188000_create_workshop_repairs')->exists()) {
    throw new RuntimeException('ABORT before writes: requires disposable PostgreSQL erp_elec_new_test on 127.0.0.1:55448 with migration 188 applied.');
}

$assertions = 0;
$pass = static function (bool $condition, string $message) use (&$assertions): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
    fwrite(STDOUT, "PASS {$message}\n");
};
$reject = static function (callable $action, string $message, int $status) use ($pass): void {
    try {
        $action();
    } catch (Throwable $error) {
        if ($error instanceof HttpExceptionInterface && $error->getStatusCode() === $status) {
            $pass(true, $message);

            return;
        }
        if ($error instanceof QueryException && $status === 409 && str_contains($error->getMessage(), 'workshop_one_active_job')) {
            $pass(true, $message);

            return;
        }
        if ($error instanceof HttpExceptionInterface) {
            throw new RuntimeException($message.' expected HTTP '.$status.' but got '.$error->getStatusCode().': '.$error->getMessage(), previous: $error);
        }
        throw $error;
    }
    throw new RuntimeException($message.' was unexpectedly accepted.');
};

DB::beginTransaction();
try {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create(['id' => 1]);
    }

    $tag = 'DEMO-EG-M09-'.Str::upper(Str::random(8));
    $branch = Branch::factory()->create(['code' => $tag, 'company_id' => 1]);
    $customer = Customer::factory()->create(['code' => $tag, 'name' => 'Ø¹Ù…ÙŠÙ„ ÙˆØ±Ø´Ø© Ø§Ø®ØªØ¨Ø§Ø± Ø§Ù„Ù‚Ø¨ÙˆÙ„']);
    $dispatcher = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $technician = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    User::factory()->disabledDemo()->create();

    foreach ([$dispatcher, $technician, $outsider] as $user) {
        DB::table('user_branch_scopes')->insert([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    foreach (['manage', 'assign', 'intake_manage', 'custody', 'job_manage', 'verify', 'deliver'] as $permission) {
        DB::table('access_grants')->insertOrIgnore([
            'user_id' => $dispatcher->id,
            'permission_code' => 'M09.'.$permission,
            'branch_id' => $branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    foreach (['execute', 'assign', 'labor_record'] as $permission) {
        DB::table('access_grants')->insertOrIgnore([
            'user_id' => $technician->id,
            'permission_code' => 'M09.'.$permission,
            'branch_id' => $branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    foreach (['estimate_manage', 'billing_request', 'labor_record', 'rate_approve', 'execute', 'verify', 'deliver', 'cost_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => 'M09.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['estimate_approve', 'rate_approve', 'verify', 'cost_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $outsider->id, 'permission_code' => 'M09.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M02.product_view', 'M11.tax_calculate', 'M11.source_post', 'M11.journal_post', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ar_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M11.source_post', 'M11.journal_post', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ar_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $outsider->id, 'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M02.product_view', 'M11.tax_calculate', 'M03.customer_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('access_grants')->insertOrIgnore(['user_id' => $outsider->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['estimate_manage', 'billing_request', 'labor_record', 'rate_approve', 'execute', 'verify', 'deliver', 'cost_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => 'M09.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['estimate_approve', 'rate_approve', 'verify', 'cost_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $outsider->id, 'permission_code' => 'M09.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M11.source_post', 'M11.journal_post', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ar_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ar_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $outsider->id, 'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('access_grants')->insertOrIgnore([
        'user_id' => $dispatcher->id,
        'permission_code' => 'M03.customer_view',
        'branch_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $actions = app(WorkshopActions::class);
    $workshopInput = [
        'code' => $tag,
        'name' => 'ÙˆØ±Ø´Ø© Ø§Ø®ØªØ¨Ø§Ø± Ø§Ù„Ù‚Ø¨ÙˆÙ„',
        'specialty' => 'ÙØ­Øµ ÙˆØ¥ØµÙ„Ø§Ø­ Ø§Ù„Ù‚ÙˆØ§Ø·Ø¹',
        'branch_id' => $branch->id,
        'supervisor_id' => $dispatcher->id,
        'warehouse_id' => null,
        'capacity' => 4,
        'schedule' => [['day' => 0, 'start' => '08:00', 'end' => '16:00']],
        'active' => true,
    ];
    $workshopId = $actions->saveWorkshop($dispatcher->id, null, 0, $workshopInput);
    $workshop = DB::table('workshops')->find($workshopId);
    $pass($workshopId > 0 && $workshop->version === 1 && $workshop->branch_id === $branch->id,
        'authorized workshop configuration persists at version one in its branch');

    $staleWorkshopInput = $workshopInput;
    $staleWorkshopInput['name'] = 'ØªØ¹Ø¯ÙŠÙ„ Ù‚Ø¯ÙŠÙ…';
    $reject(fn () => $actions->saveWorkshop($dispatcher->id, $workshopId, 0, $staleWorkshopInput),
        'stale workshop configuration version is rejected', 409);

    $actions->member($dispatcher->id, $workshopId, 1, $technician->id, true);
    $pass(DB::table('workshop_members')->where('workshop_id', $workshopId)->where('technician_id', $technician->id)->where('active', true)->exists()
        && DB::table('workshops')->where('id', $workshopId)->where('version', 2)->exists(),
        'authorized scoped technician membership is persisted and advances workshop version');
    $reject(fn () => $actions->member($dispatcher->id, $workshopId, 1, $technician->id, true),
        'stale workshop version cannot overwrite membership', 409);

    $stockBefore = [
        'movements' => DB::table('inventory_movements')->count(),
        'fifo_layers' => DB::table('inventory_fifo_layers')->count(),
        'balances' => DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];

    $intakeKey = (string) Str::uuid();
    $intakeInput = [
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'equipment_description' => 'Ù‚Ø§Ø·Ø¹ Ø±Ø¦ÙŠØ³ÙŠ ÙˆØ§Ø±Ø¯ Ù„Ù„ÙØ­Øµ Ø§Ù„ØªØ¬Ø±ÙŠØ¨ÙŠ',
        'model' => 'DEMO-M09-BREAKER',
        'serial_number' => 'DEMO-M09-SERIAL-'.Str::upper(Str::random(6)),
        'accessories' => 'Ù…Ù„Ø­Ù‚ Ø§Ø®ØªØ¨Ø§Ø± ÙÙ‚Ø·',
        'reported_fault' => 'ÙØµÙ„ Ù…ØªÙƒØ±Ø± ÙˆÙÙ‚ Ø¨Ù„Ø§Øº ØªØ¬Ø±ÙŠØ¨ÙŠ.',
        'arrival_condition' => 'Ø§Ù„Ù…Ù„ØµÙ‚ Ø³Ù„ÙŠÙ… ÙˆØ§Ù„Ø¬Ø³Ù… Ø¯ÙˆÙ† ØªÙ„Ù Ø¸Ø§Ù‡Ø±.',
        'customer_owned' => true,
        'location' => 'Ø§Ø³ØªÙ„Ø§Ù… Ø§Ù„ÙˆØ±Ø´Ø©',
    ];
    $intakeId = $actions->saveIntake($dispatcher->id, null, 0, $intakeKey, $intakeInput);
    $pass($intakeId > 0 && $actions->saveIntake($dispatcher->id, null, 0, $intakeKey, $intakeInput) === $intakeId,
        'intake creation key safely replays the same draft after a lost response');
    $intake = DB::table('workshop_intakes')->find($intakeId);
    $pass($intake->status === 'draft' && $intake->version === 1 && $intake->customer_owned === true,
        'customer property intake remains a draft without receiving or company-stock effect');

    $updatedInput = $intakeInput;
    $updatedInput['accessories'] = 'Ù…Ù„Ø­Ù‚ Ø§Ù„Ø§Ø®ØªØ¨Ø§Ø± Ø§Ù„ØªØ¬Ø±ÙŠØ¨ÙŠ ÙˆÙƒØªÙŠØ¨ Ø§Ù„Ø¬Ù‡Ø§Ø²';
    $actions->saveIntake($dispatcher->id, $intakeId, 1, (string) Str::uuid(), $updatedInput);
    $pass(DB::table('workshop_intakes')->where('id', $intakeId)->where('version', 2)->where('accessories', $updatedInput['accessories'])->exists(),
        'draft intake can be saved with its current version while preserving the customer property snapshot');
    $reject(fn () => $actions->saveIntake($dispatcher->id, $intakeId, 1, (string) Str::uuid(), $intakeInput),
        'stale intake version is rejected', 409);

    $receiptKey = (string) Str::uuid();
    $actions->receive($dispatcher->id, $intakeId, 2, $receiptKey, CarbonImmutable::now('UTC')->subMinute()->toIso8601String(), 'ØªÙ… Ø§Ø³ØªÙ„Ø§Ù… Ø§Ù„Ù‚Ø§Ø·Ø¹ ÙˆØ§Ù„Ù…Ù„Ø­Ù‚Ø§Øª Ù„Ù„ØªØ¬Ø±Ø¨Ø©.');
    $actions->receive($dispatcher->id, $intakeId, 2, $receiptKey, CarbonImmutable::now('UTC')->subMinute()->toIso8601String(), 'ØªÙ… Ø§Ø³ØªÙ„Ø§Ù… Ø§Ù„Ù‚Ø§Ø·Ø¹ ÙˆØ§Ù„Ù…Ù„Ø­Ù‚Ø§Øª Ù„Ù„ØªØ¬Ø±Ø¨Ø©.');
    $pass(DB::table('workshop_intakes')->where('id', $intakeId)->where('status', 'received')->where('version', 3)->exists()
        && DB::table('workshop_custody_events')->where('intake_id', $intakeId)->where('event_key', $receiptKey)->where('status', 'received')->count() === 1,
        'receipt replay with the same key records one immutable custody event');
    $reject(fn () => $actions->receive($dispatcher->id, $intakeId, 2, (string) Str::uuid(), CarbonImmutable::now('UTC')->subMinute()->toIso8601String(), 'Ù…Ø­Ø§ÙˆÙ„Ø© Ø§Ø³ØªÙ„Ø§Ù… Ù…ÙƒØ±Ø±Ø©.'),
        'second receipt with a new key cannot receive the same intake again', 409);

    $jobKey = (string) Str::uuid();
    $jobId = $actions->createJob($dispatcher->id, $intakeId, 3, $jobKey, $workshopId, 'normal', 'nonbillable');
    $pass($jobId > 0 && $actions->createJob($dispatcher->id, $intakeId, 3, $jobKey, $workshopId, 'normal', 'nonbillable') === $jobId,
        'received intake converts to one workshop job and lost-response retry returns that job');
    $job = DB::table('workshop_jobs')->find($jobId);
    $pass($job->status === 'inspection' && $job->intake_id === $intakeId
        && DB::table('workshop_intakes')->where('id', $intakeId)->where('status', 'inspection')->where('version', 4)->exists(),
        'job retains intake identity and enters inspection without fabricated repair execution');
    $reject(fn () => $actions->createJob($dispatcher->id, $intakeId, 3, (string) Str::uuid(), $workshopId, 'normal', 'nonbillable'),
        'stale intake version cannot create another job', 409);
    $reject(fn () => $actions->createJob($dispatcher->id, $intakeId, 4, (string) Str::uuid(), $workshopId, 'normal', 'contract'),
        'contract billing mode is blocked until approved coverage is connected', 409);

    $actions->assign($dispatcher->id, $jobId, 1, $workshopId, $technician->id, 'ØªØ¹ÙŠÙŠÙ† ÙÙ†ÙŠ Ø§Ù„ÙˆØ±Ø´Ø© Ù„Ù„ÙØ­Øµ Ø§Ù„Ø£ÙˆÙ„ÙŠ.');
    $pass(DB::table('workshop_jobs')->where('id', $jobId)->where('technician_id', $technician->id)->where('version', 2)->exists(),
        'dispatcher assigns only the active workshop member and advances job version');
    $reject(fn () => $actions->assign($dispatcher->id, $jobId, 1, $workshopId, $technician->id, 'Ø¥Ø¹Ø§Ø¯Ø© Ø¥Ø±Ø³Ø§Ù„ ØªØ¹ÙŠÙŠÙ† Ù‚Ø¯ÙŠÙ….'),
        'stale job version cannot repeat assignment', 409);
    $actions->diagnose($technician->id, $jobId, 2, [
        'diagnosis' => 'Ø§Ù„Ø¹Ø·Ù„ Ù…ØªÙ‚Ø·Ø¹ ÙˆÙŠØ­ØªØ§Ø¬ ÙØ­Øµ Ù†Ù‚Ø§Ø· Ø§Ù„ØªÙ„Ø§Ù…Ø³.',
        'planned_work' => 'ØªÙ†Ø¸ÙŠÙ Ù†Ù‚Ø§Ø· Ø§Ù„ØªÙ„Ø§Ù…Ø³ Ø«Ù… ÙØ­Øµ Ø§Ù„ØªØ´ØºÙŠÙ„ ÙˆÙÙ‚ Ø¥Ø¬Ø±Ø§Ø¡ Ø§Ù„Ù…ØµÙ†Ø¹.',
        'required_tests' => [['name' => 'ÙØ­Øµ Ø§Ù„ØªØ´ØºÙŠÙ„ ÙˆÙÙ‚ Ø¥Ø¬Ø±Ø§Ø¡ Ø§Ù„Ù…ØµÙ†Ø¹', 'required' => true]],
        'estimated_minutes' => 45,
    ]);
    $pass(DB::table('workshop_jobs')->where('id', $jobId)->where('status', 'inspection')->where('diagnosis', 'Ø§Ù„Ø¹Ø·Ù„ Ù…ØªÙ‚Ø·Ø¹ ÙˆÙŠØ­ØªØ§Ø¬ ÙØ­Øµ Ù†Ù‚Ø§Ø· Ø§Ù„ØªÙ„Ø§Ù…Ø³.')->where('planned_work', 'ØªÙ†Ø¸ÙŠÙ Ù†Ù‚Ø§Ø· Ø§Ù„ØªÙ„Ø§Ù…Ø³ Ø«Ù… ÙØ­Øµ Ø§Ù„ØªØ´ØºÙŠÙ„ ÙˆÙÙ‚ Ø¥Ø¬Ø±Ø§Ø¡ Ø§Ù„Ù…ØµÙ†Ø¹.')->where('version', 3)->exists(),
        'assigned technician records diagnosis, planned work and required tests without changing status to repaired or tested');
    $reject(fn () => $actions->diagnose($outsider->id, $jobId, 3, ['diagnosis' => 'Ù…Ø­Ø§ÙˆÙ„Ø© ØªØ´Ø®ÙŠØµ ØºÙŠØ± Ù…ØµØ±Ø­ Ø¨Ù‡Ø§.', 'planned_work' => 'ØªÙ†ÙÙŠØ° Ø¹Ù…Ù„ ØºÙŠØ± Ù…Ø¹ØªÙ…Ø¯.', 'required_tests' => [['name' => 'ÙØ­Øµ ØªØ¬Ø±ÙŠØ¨ÙŠ', 'required' => true]]]),
        'unscoped user cannot diagnose the workshop job', 403);

    $execution = app(WorkshopExecution::class);
    $reject(fn () => $execution->state($technician->id, $jobId, 3, 'start', 'Ù…Ø­Ø§ÙˆÙ„Ø© Ø¨Ø¯Ø¡ Ù‚Ø¨Ù„ Ø§Ù„Ù…ÙˆØ§ÙÙ‚Ø©.'),
        'repair cannot start while the estimate has no independent customer approval', 409);
    $startTime = CarbonImmutable::now('UTC')->startOfMinute()->subMinutes(30);
    $endTime = $startTime->addMinutes(15);
    $reject(fn () => $execution->record($technician->id, $jobId, 3, (string) Str::uuid(), [
        'started_at' => $startTime->toIso8601String(),
        'ended_at' => $endTime->toIso8601String(),
        'work_performed' => 'Ù…Ø­Ø§ÙˆÙ„Ø© ØªØ³Ø¬ÙŠÙ„ Ø¹Ù…Ù„ Ù‚Ø¨Ù„ Ø§Ù„Ù…ÙˆØ§ÙÙ‚Ø© ÙˆØ§Ù„ØªÙ†ÙÙŠØ°.',
        'observations' => 'Ù„Ø§ ØªÙˆØ¬Ø¯ Ù…Ù„Ø§Ø­Ø¸Ø© ØªÙ†ÙÙŠØ° ÙØ¹Ù„ÙŠØ©.',
        'work_type' => 'ÙØ­Øµ',
    ]), 'work record cannot be captured before the approved repair is started', 409);
    $reject(fn () => $execution->test($technician->id, $jobId, 3, [
        'name' => 'ÙØ­Øµ Ø§Ù„ØªØ´ØºÙŠÙ„ ÙˆÙÙ‚ Ø¥Ø¬Ø±Ø§Ø¡ Ø§Ù„Ù…ØµÙ†Ø¹',
        'outcome' => 'passed',
        'results' => 'Ù…Ø­Ø§ÙˆÙ„Ø© ØªØ³Ø¬ÙŠÙ„ Ù†ØªÙŠØ¬Ø© Ù‚Ø¨Ù„ Ø§Ù„Ø§Ø®ØªØ¨Ø§Ø± Ø§Ù„ÙØ¹Ù„ÙŠ.',
    ]), 'test evidence cannot be recorded before the job enters testing', 409);
    $reject(fn () => $execution->verify($dispatcher->id, $jobId, 3, 'Ø­Ø§Ù„Ø© Ø³Ù„ÙŠÙ…Ø©.', 'Ù„Ø§ ØªÙˆØ¬Ø¯ Ø£Ø¹Ø·Ø§Ù„ Ù…ØªØ¨Ù‚ÙŠØ©.'),
        'supervisor cannot verify a job before execution and required tests', 409);
    $pass(! DB::table('workshop_work_records')->where('job_id', $jobId)->exists()
        && ! DB::table('workshop_tests')->where('job_id', $jobId)->exists()
        && ! DB::table('workshop_verifications')->where('job_id', $jobId)->exists(),
        'rejected lifecycle attempts create no labor, test or verification evidence');

    $reject(fn () => $execution->unrepairable($technician->id, $jobId, 3, 'Ù…Ø­Ø§ÙˆÙ„Ø© Ø¥Ø±Ø¬Ø§Ø¹ Ø¯ÙˆÙ† ØµÙ„Ø§Ø­ÙŠØ© Ø§Ù„ØªØ­Ù‚Ù‚.'),
        'assigned technician cannot release equipment as unrepairable without verification authority', 403);
    $execution->unrepairable($dispatcher->id, $jobId, 3, 'Ø§Ù„Ù‚Ø·Ø¹Ø© ØºÙŠØ± Ù‚Ø§Ø¨Ù„Ø© Ù„Ù„Ø¥ØµÙ„Ø§Ø­ ÙˆÙÙ‚ Ù†ØªÙŠØ¬Ø© Ø§Ù„ÙØ­Øµ Ø§Ù„Ø£ÙˆÙ„ÙŠ.');
    $pass(DB::table('workshop_jobs')->where('id', $jobId)->where('status', 'rejected')->where('version', 4)->exists()
        && DB::table('workshop_intakes')->where('id', $intakeId)->where('status', 'not_repairable')->exists()
        && DB::table('workshop_custody_events')->where('intake_id', $intakeId)->where('status', 'not_repairable')->exists(),
        'authorized unrepairable decision keeps a separate repair result and an auditable custody event');
    $deliveryKey = (string) Str::uuid();
    $deliveryInput = [
        'recipient' => 'Ù…Ø­Ù…ÙˆØ¯ Ø­Ø³Ù†ØŒ Ù…Ù†Ø¯ÙˆØ¨ Ø§Ù„Ø¹Ù…ÙŠÙ„',
        'accessories' => $updatedInput['accessories'],
        'final_condition' => 'Ø§Ù„Ù‚Ø§Ø·Ø¹ ØºÙŠØ± Ù‚Ø§Ø¨Ù„ Ù„Ù„Ø¥ØµÙ„Ø§Ø­ ÙˆÙŠØ¹Ø§Ø¯ Ø¨Ø§Ù„Ø­Ø§Ù„Ø© Ø§Ù„Ù…Ø³Ø¬Ù„Ø©.',
        'acknowledgment' => 'Ø§Ø³ØªÙ„Ù… Ø§Ù„Ù…Ù†Ø¯ÙˆØ¨ Ø§Ù„Ù‚Ø§Ø·Ø¹ ÙˆØ§Ù„Ù…Ù„Ø­Ù‚Ø§Øª Ø¯ÙˆÙ† Ø¥ØµÙ„Ø§Ø­.',
        'delivered_at' => CarbonImmutable::now('UTC')->subSeconds(20)->toIso8601String(),
        'unrepaired' => true,
    ];
    $deliveryId = $execution->deliver($dispatcher->id, $jobId, 4, $deliveryKey, $deliveryInput);
    $pass($deliveryId > 0 && $execution->deliver($dispatcher->id, $jobId, 4, $deliveryKey, $deliveryInput) === $deliveryId
        && DB::table('workshop_intakes')->where('id', $intakeId)->where('status', 'returned_unrepaired')->where('accessories', $updatedInput['accessories'])->exists()
        && DB::table('workshop_deliveries')->where('id', $deliveryId)->where('unrepaired', true)->where('recipient', $deliveryInput['recipient'])->exists(),
        'unrepaired return records its authorized recipient and original accessories and safely replays delivery');

    $companyIntakeKey = (string) Str::uuid();
    $companyIntakeInput = $intakeInput;
    $companyIntakeInput['serial_number'] = 'DEMO-M09-COMPANY-'.Str::upper(Str::random(6));
    $companyIntakeInput['customer_owned'] = false;
    $companyIntakeId = $actions->saveIntake($dispatcher->id, null, 0, $companyIntakeKey, $companyIntakeInput);
    $actions->receive($dispatcher->id, $companyIntakeId, 1, (string) Str::uuid(),
        CarbonImmutable::now('UTC')->subMinute()->toIso8601String(), 'Ø§Ø³ØªÙ„Ø§Ù… Ù…Ø¹Ø¯Ø© Ù…Ø³Ø¬Ù„Ø© ÙƒÙ…Ù„ÙƒÙŠØ© Ù„Ù„Ø´Ø±ÙƒØ© Ù„Ù„ÙØ­Øµ ÙÙ‚Ø·.');
    $companyIntake = DB::table('workshop_intakes')->find($companyIntakeId);
    $companyJobId = $actions->createJob($dispatcher->id, $companyIntakeId, $companyIntake->version, (string) Str::uuid(), $workshopId, 'normal', 'nonbillable');
    $pass(DB::table('workshop_intakes')->where('id', $companyIntakeId)->where('customer_owned', false)->where('status', 'inspection')->exists()
        && DB::table('workshop_jobs')->where('id', $companyJobId)->exists(),
        'company-owned equipment may be explicitly tracked for repair without converting its custody into stock');

    // A separate, billable repair exercises the financial APIs while the preceding
    // lifecycle fixture remains available for its unrepaired-custody assertions.
    $billableInput = $intakeInput;
    $billableInput['serial_number'] = 'DEMO-M09-BILLABLE-'.Str::upper(Str::random(6));
    $billableIntake = $actions->saveIntake($dispatcher->id, null, 0, (string) Str::uuid(), $billableInput);
    fwrite(STDOUT, "STEP billable-intake\n");
    $billableReceivedAt = CarbonImmutable::now('UTC')->subHours(7)->startOfMinute();
    $actions->receive($dispatcher->id, $billableIntake, 1, (string) Str::uuid(), $billableReceivedAt->toIso8601String(), 'Synthetic billable equipment intake accepted for isolated financial QA.');
    $billableJob = $actions->createJob($dispatcher->id, $billableIntake, 2, (string) Str::uuid(), $workshopId, 'normal', 'billable');
    fwrite(STDOUT, "STEP billable-job\n");
    $actions->assign($dispatcher->id, $billableJob, 1, $workshopId, $technician->id, 'Assign independent billable repair acceptance fixture.');
    $actions->diagnose($technician->id, $billableJob, 2, [
        'required_tests' => [['name' => 'M09 QA functional test', 'required' => true]],
        'diagnosis' => 'Synthetic intermittent contact fault found during acceptance diagnosis.',
        'planned_work' => 'Clean contacts, replace no parts, and perform an electrical function test.',
        'estimated_minutes' => 30,
    ]);
    fwrite(STDOUT, "STEP billable-diagnosis\n");
    $service = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $taxDate = CarbonImmutable::now('Africa/Cairo')->toDateString();
    $estimates = app(WorkshopEstimates::class);
    app(App\Modules\WorkshopRepairs\Application\WorkshopAccess::class)->authorize($dispatcher->id, $branch->id, 'estimate_manage');
    fwrite(STDOUT, "STEP permission-estimate\n");
    app(App\Modules\Products\Application\ProductAccess::class)->authorize($dispatcher->id, 'M02.product_view');
    fwrite(STDOUT, "STEP permission-product\n");
    app(App\Modules\Accounting\Application\AccountingAccess::class)->authorize($dispatcher->id, 'tax_calculate');
    fwrite(STDOUT, "STEP permission-tax\n");
    $estimateId = $estimates->propose($dispatcher->id, $billableJob, 3, [
        'product_id' => $service->id, 'date' => $taxDate, 'pricing_reference' => 'Synthetic customer-approved repair pricing basis.',
        'parts_amount' => '0.00', 'labor_amount' => '0.00', 'other_amount' => '0.00', 'billable_amount' => '100.00',
    ]);
    fwrite(STDOUT, "STEP estimate\n");
    $estimate = DB::table('workshop_estimates')->find($estimateId);
    $pass($estimate && $estimate->revision === 1 && $estimate->billable_amount === '100.00'
        && is_array(json_decode($estimate->tax_snapshot, true)) && $estimate->tax_amount === '14.00',
        'M09 estimate captures configured M11 sale tax and exact billable decimal');
    $reject(fn () => $estimates->decide($dispatcher->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, $estimateId, true, 'Independent customer acceptance evidence.'),
        'estimate proposer cannot approve their own estimate', 403);
    $estimates->decide($outsider->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, $estimateId, true, 'Independent customer acceptance evidence.');
    $pass(DB::table('workshop_estimate_approvals')->where('estimate_id', $estimateId)->where('approved_by', $outsider->id)->where('customer_accepted', true)->exists(),
        'independent approver records customer acceptance before repair execution');

    // Reuse a real, previously accepted M08 technician with a posted labor interval.
    // This verifies the cross-owner time lock without manufacturing an M08 record.
    $overlapTechnician = User::query()->findOrFail(643);
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $overlapTechnician->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['execute', 'assign', 'labor_record'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $overlapTechnician->id, 'permission_code' => 'M09.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $actions->member($dispatcher->id, $workshopId, (int) DB::table('workshops')->find($workshopId)->version, $overlapTechnician->id, true);
    fwrite(STDOUT, "STEP m08-member\n");
    $actions->assign($dispatcher->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, $workshopId, $overlapTechnician->id, 'Temporarily assign the existing M08 technician to test overlap guard.');
    fwrite(STDOUT, "STEP m08-assignment\n");
    $execution = app(WorkshopExecution::class);
    $execution->state($overlapTechnician->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, 'start', 'Begin isolated overlap guard attempt.');
    fwrite(STDOUT, "STEP m08-start\n");
    $overlapStart = CarbonImmutable::parse('2027-10-30T11:15:00+02:00');
    $overlapEnd = CarbonImmutable::parse('2027-10-30T11:45:00+02:00');
    $reject(fn () => $execution->record($overlapTechnician->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, (string) Str::uuid(), [
        'started_at' => $overlapStart->toIso8601String(), 'ended_at' => $overlapEnd->toIso8601String(),
        'work_performed' => 'Overlap guard attempt against existing M08 actual labor.', 'observations' => 'No M09 work should be recorded.', 'work_type' => 'repair',
    ]), 'M09 rejects time overlapping the existing accepted M08 technician entry', 409);
    $pass(! DB::table('workshop_work_records')->where('job_id', $billableJob)->exists(),
        'M08 overlap denial leaves no M09 work record or labor entry');
    $actions->assign($dispatcher->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, $workshopId, $technician->id, 'Restore the assigned synthetic M09 technician after overlap rejection.');

    $rates = $estimates;
    $rateId = $rates->proposeRate($dispatcher->id, ['branch_id' => $branch->id, 'technician_id' => $technician->id, 'hourly_cost' => '60.00', 'effective_from' => $taxDate, 'effective_to' => $taxDate, 'reference' => 'Synthetic M09 hourly labor acceptance rate.']);
    $rates->approveRate($outsider->id, $rateId);
    $execution = app(WorkshopExecution::class);
    $execution->state($technician->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, 'start', 'Accepted estimate permits repair execution.');
    $laborStart = CarbonImmutable::now('UTC')->subMinutes(45)->startOfMinute();
    $laborEnd = CarbonImmutable::now('UTC')->subMinutes(15)->startOfMinute();
    $recordId = $execution->record($technician->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, (string) Str::uuid(), [
        'started_at' => $laborStart->toIso8601String(), 'ended_at' => $laborEnd->toIso8601String(),
        'work_performed' => 'Synthetic contact cleaning and electrical continuity work.', 'observations' => 'No abnormal heat observed during acceptance.', 'work_type' => 'repair',
    ]);
    $labor = DB::table('workshop_labor_entries')->where('job_id', $billableJob)->where('work_record_id', $recordId)->first();
    $pass($labor && $labor->rate_id === $rateId && $labor->cost === '30.00' && $labor->duration_minutes === 30,
        'work record snapshots approved rate into exact 30-minute labor cost');
    $finance = app(WorkshopFinance::class);
    $laborJournal = $finance->recognizeLabor($outsider->id, $billableJob, $labor->id);
    $pass($laborJournal > 0 && $finance->recognizeLabor($outsider->id, $billableJob, $labor->id) === $laborJournal
        && DB::table('workshop_labor_recognitions')->where('labor_entry_id', $labor->id)->where('journal_id', $laborJournal)->count() === 1,
        'approved labor posts one M11 source journal and replay returns the original journal');
    $execution->state($technician->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, 'testing', 'Repair work is complete and ready for its required test.');
    $execution->test($technician->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, ['name' => 'M09 QA functional test', 'outcome' => 'passed', 'results' => 'Synthetic functional check passed.']);
    $execution->verify($outsider->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, 'Functional acceptance passed.', 'No unresolved electrical fault observed.');
    $pass(DB::table('workshop_jobs')->where('id', $billableJob)->where('status', 'verified')->exists()
        && DB::table('workshop_verifications')->where('job_id', $billableJob)->exists(),
        'independent verification records test/report before M12 billing');

    $invoiceKey = (string) Str::uuid();
    $invoiceDate = $taxDate;
    $dueDate = CarbonImmutable::parse($invoiceDate)->addDays(30)->toDateString();
    $invoiceId = $finance->requestInvoice($dispatcher->id, $billableJob, $invoiceKey, $invoiceDate, $dueDate);
    $pass($invoiceId > 0 && $finance->requestInvoice($dispatcher->id, $billableJob, $invoiceKey, $invoiceDate, $dueDate) === $invoiceId,
        'M12 invoice request replays by creation key for the same verified source');
    $invoice = DB::table('rp_customer_invoices')->find($invoiceId);
    $approvalId = DB::table('workshop_estimate_approvals')->where('estimate_id', $estimateId)->value('id');
    $pass($invoice && $invoice->workshop_job_id === $billableJob && $invoice->net_amount === '100.00' && $invoice->tax_amount === '14.00'
        && DB::table('rp_customer_invoice_lines')->where('invoice_id', $invoiceId)->where('workshop_approval_id', $approvalId)->exists(),
        'M12 invoice line preserves exact M09 customer-accepted estimate and tax source');
    $receivables = app(ReceivablesPayablesService::class);
    $receivables->validateCustomerInvoice($dispatcher->id, $invoiceId, (int) $invoice->version);
    $validated = DB::table('rp_customer_invoices')->find($invoiceId);
    $postResult = $receivables->postCustomerInvoice($dispatcher->id, $invoiceId, (int) $validated->version);
    $postReplay = $receivables->postCustomerInvoice($dispatcher->id, $invoiceId, (int) DB::table('rp_customer_invoices')->find($invoiceId)->version);
    $pass($postResult['journal_id'] === $postReplay['journal_id'] && DB::table('rp_customer_invoices')->where('id', $invoiceId)->where('status', 'posted')->exists()
        && DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoiceId)->where('original_amount', '114.00')->exists(),
        'M12 validates and posts the accepted M09 source exactly once to GL and customer AR');

    $stockAfter = [
        'movements' => DB::table('inventory_movements')->count(),
        'fifo_layers' => DB::table('inventory_fifo_layers')->count(),
        'balances' => DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
    $pass($stockAfter === $stockBefore,
        'customer-owned and company-owned repair intake, receipt, job creation, diagnosis and unrepaired return do not create inventory movements, FIFO or balance changes');

    $factoryWorkshop = Workshop::factory()->create(['branch_id' => $branch->id, 'supervisor_id' => $dispatcher->id, 'created_by' => $dispatcher->id]);
    $factoryIntake = WorkshopIntake::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'created_by' => $dispatcher->id]);
    $factoryReceiptKey = (string) Str::uuid();
    $actions->receive($dispatcher->id, $factoryIntake->id, 1, $factoryReceiptKey,
        CarbonImmutable::now('UTC')->subMinutes(10)->toIso8601String(), 'Ø§Ø³ØªÙ„Ø§Ù… Ø­Ù‚ÙŠÙ‚ÙŠ Ø¶Ù…Ù† ØªØ¬Ù‡ÙŠØ² Ø§Ø®ØªØ¨Ø§Ø± Ø§Ù„Ù…ØµÙ†Ø¹.');
    $factoryJob = WorkshopJob::factory()->create(['workshop_id' => $factoryWorkshop->id, 'created_by' => $dispatcher->id]);
    $pass($factoryWorkshop->exists && $factoryIntake->exists && $factoryJob->exists
        && DB::table('workshop_intakes')->where('id', $factoryIntake->id)->where('status', 'inspection')->where('workshop_id', $factoryWorkshop->id)->exists()
        && DB::table('workshop_custody_events')->where('intake_id', $factoryIntake->id)->where('status', 'inspection')->where('event_key', $factoryJob->creation_key)->exists()
        && ! DB::table('workshop_estimates')->where('job_id', $factoryJob->id)->exists()
        && ! DB::table('workshop_work_records')->where('job_id', $factoryJob->id)->exists(),
        'Workshop factories persist configuration, draft intake and a valid received-to-inspection job graph without estimates or performed work');

    fwrite(STDOUT, "PASS {$assertions} WorkshopRepairs financial assertions completed on isolated PostgreSQL 55448.\n");
} finally {
    DB::rollBack();
}
