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
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Products\Domain\Product;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
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
putenv('DB_PORT='.(getenv('M09_AUDIT_PORT') ?: '55462'));
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = (string) (getenv('M09_AUDIT_PORT') ?: '55462');
putenv('DB_DATABASE=erp_elec_new_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
putenv('DB_USERNAME=postgres');
$_ENV['DB_USERNAME'] = $_SERVER['DB_USERNAME'] = 'postgres';
putenv('DB_PASSWORD=');
$_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = '';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';

$root = 'C:/projects/ERP_ELEC_NEW';
$auditPort = (int) (getenv('M09_AUDIT_PORT') ?: '55462');
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
    || (int) $identity->port !== $auditPort
    || ! DB::table('migrations')->where('migration', '2026_10_31_192000_complete_workshop_offers_and_custody')->exists()) {
    throw new RuntimeException('ABORT before writes: requires disposable PostgreSQL erp_elec_new_test on the configured loopback audit port with migration 192 applied.');
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
$runRaceWorkers = static function (array $arguments, ?array $secondArguments = null) use ($root, $auditPort): array {
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $auditPort,
        'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '', 'DB_URL' => '',
        'LARAVEL_STORAGE_PATH' => (string) getenv('LARAVEL_STORAGE_PATH'),
    ];
    $workers = [];
    foreach ([$arguments, $secondArguments ?? $arguments] as $workerArguments) {
        $process = new Symfony\Component\Process\Process([PHP_BINARY, $root.'/docs/release/evidence/final-20261030/m09-two-worker.php', ...$workerArguments], $root, $environment);
        $process->setTimeout(45);
        $process->start();
        $workers[] = $process;
    }
    $results = [];
    foreach ($workers as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Isolated concurrent worker failed: '.trim($process->getErrorOutput().' '.$process->getOutput()));
        }
        try {
            $results[] = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Isolated concurrent worker returned non-JSON output. stdout='.trim($process->getOutput()).' stderr='.trim($process->getErrorOutput()), previous: $error);
        }
    }

    return $results;
};

DB::beginTransaction();
fwrite(STDOUT, 'STEP race-mode='.((string) getenv('M09_TWO_WORKER_RACE') ?: 'off')."\n");
try {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create(['id' => 1]);
    }

    $tag = 'DEMO-EG-M09-'.Str::upper(Str::random(8));
    // Use the isolated clone's real stocked branch so the M09 parts path can
    // exercise existing M06 FIFO layers without creating stock or balances.
    $branch = Branch::query()->findOrFail(1);
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
    foreach (['view', 'estimate_manage', 'billing_request', 'labor_record', 'rate_approve', 'execute', 'verify', 'deliver', 'cost_view', 'quote_issue', 'customer_acceptance'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => 'M09.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['estimate_approve', 'rate_approve', 'verify', 'cost_view', 'nonbillable_approve'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $outsider->id, 'permission_code' => 'M09.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M01.company_view', 'M02.product_view', 'M11.tax_calculate', 'M11.source_post', 'M11.journal_post', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ar_view', 'M13.collection_execute'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M11.source_post', 'M11.journal_post', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ar_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $outsider->id, 'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M01.company_view', 'M02.product_view', 'M11.tax_calculate', 'M03.customer_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('access_grants')->insertOrIgnore(['user_id' => $outsider->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M06.reservation_manage', 'M06.reservation_view', 'M06.balance_view', 'M06.issue_post', 'M06.movement_view', 'M06.correction_execute', 'M06.isolation_manage', 'M06.isolation_release'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => $permission, 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('user_warehouse_scopes')->insertOrIgnore(['user_id' => $dispatcher->id, 'warehouse_id' => 1, 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['parts_request', 'parts_issue', 'parts_return'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => 'M09.'.$permission, 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M09.parts_request', 'M09.parts_alternative_approve', 'M02.product_view', 'M06.balance_view'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $outsider->id, 'permission_code' => $permission, 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('user_warehouse_scopes')->insertOrIgnore(['user_id' => $outsider->id, 'warehouse_id' => 1, 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['estimate_manage', 'billing_request', 'labor_record', 'rate_approve', 'execute', 'verify', 'deliver', 'cost_view', 'quote_issue', 'customer_acceptance'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $dispatcher->id, 'permission_code' => 'M09.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['estimate_approve', 'rate_approve', 'verify', 'cost_view', 'nonbillable_approve'] as $permission) {
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
        'warehouse_id' => 1,
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

    $companyIntakeInput = $intakeInput;
    $companyIntakeInput['customer_id'] = null;
    $companyIntakeInput['customer_owned'] = false;
    $beforeCompanyIntakes = DB::table('workshop_intakes')->count();
    $reject(fn () => $actions->saveIntake($dispatcher->id, null, 0, (string) Str::uuid(), $companyIntakeInput),
        'company equipment cannot enter repair custody without a real Inventory serial or positive receipt-movement identity', 422);
    $pass(DB::table('workshop_intakes')->count() === $beforeCompanyIntakes,
        'invalid company-equipment intake leaves no synthetic custody record');
    $custodyStockAfter = [
        'movements' => DB::table('inventory_movements')->count(),
        'fifo_layers' => DB::table('inventory_fifo_layers')->count(),
        'balances' => DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
    $pass($custodyStockAfter === $stockBefore, 'customer repair custody actions leave inventory movements, FIFO layers and balances unchanged');

    $sourceMovement = DB::table('inventory_movements as movement')->where('warehouse_id', 1)->where('product_id', 1)->where('quantity', '>', 0)
        ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('inventory_isolations')->whereColumn('inventory_isolations.source_movement_id', 'movement.id')->whereColumn('inventory_isolations.released', '<', 'inventory_isolations.quantity'))
        ->orderBy('id')->first();
    if (! $sourceMovement) {
        throw new RuntimeException('The isolated branch-1 inventory fixture has no genuine positive movement for internal-equipment acceptance.');
    }
    $companyIntakeInput['company_movement_id'] = $sourceMovement->id;
    $companyIntakeInput['serial_number'] = 'DEMO-M09-INTERNAL-'.Str::upper(Str::random(8));
    $companyIntake = $actions->saveIntake($dispatcher->id, null, 0, (string) Str::uuid(), $companyIntakeInput);
    $actions->receive($dispatcher->id, $companyIntake, 1, (string) Str::uuid(), CarbonImmutable::now('UTC')->subMinutes(3)->toIso8601String(), 'Internal equipment received against genuine M06 stock movement.');
    $companyRow = DB::table('workshop_intakes')->find($companyIntake);
    $companyJob = $actions->createJob($dispatcher->id, $companyIntake, 2, (string) Str::uuid(), $workshopId, 'normal', 'nonbillable');
    $actions->assign($dispatcher->id, $companyJob, 1, $workshopId, $technician->id, 'Assign genuine internal equipment isolation check.');
    $actions->diagnose($technician->id, $companyJob, 2, [
        'required_tests' => [['name' => 'Internal repair acceptance inspection', 'required' => true]],
        'diagnosis' => 'Synthetic inspection of isolated company inventory item.',
        'planned_work' => 'Verify the fault before returning the company asset.', 'estimated_minutes' => 15,
    ]);
    $offers = app(App\Modules\WorkshopRepairs\Application\RepairOffers::class);
    $offers->nonbillable($outsider->id, $companyJob, 3, 'internal', null, 'Independent internal-maintenance authorization for company inventory equipment.');
    $execution->state($technician->id, $companyJob, 4, 'start', 'Begin authorized internal equipment inspection.');
    $companyRow = DB::table('workshop_intakes')->find($companyIntake);
    $pass($companyRow->company_movement_id === $sourceMovement->id && $companyRow->company_serial_id === null && $companyRow->isolation_id > 0
        && DB::table('workshop_nonbillable_authorizations')->where('job_id', $companyJob)->where('kind', 'internal')->exists()
        && DB::table('inventory_isolations')->where('id', $companyRow->isolation_id)->exists()
        && DB::table('workshop_jobs')->where('id', $companyJob)->where('status', 'repair')->exists(),
        'genuine positive M06 receipt movement creates isolated company-equipment custody and independently authorized internal repair');

    $warrantyInput = $intakeInput;
    $warrantyInput['serial_number'] = 'DEMO-M09-WARRANTY-'.Str::upper(Str::random(6));
    $warrantyIntake = $actions->saveIntake($dispatcher->id, null, 0, (string) Str::uuid(), $warrantyInput);
    $actions->receive($dispatcher->id, $warrantyIntake, 1, (string) Str::uuid(), CarbonImmutable::now('UTC')->subMinute()->toIso8601String(), 'Warranty test fixture received for isolated QA.');
    $warrantyJob = $actions->createJob($dispatcher->id, $warrantyIntake, 2, (string) Str::uuid(), $workshopId, 'normal', 'warranty');
    $actions->assign($dispatcher->id, $warrantyJob, 1, $workshopId, $technician->id, 'Assign warranty-path negative test.');
    $actions->diagnose($technician->id, $warrantyJob, 2, [
        'required_tests' => [['name' => 'Warranty inspection', 'required' => true]],
        'diagnosis' => 'Synthetic warranty inspection; no source repair matches this serial.',
        'planned_work' => 'Inspect equipment identity and prior accepted quote before approval.', 'estimated_minutes' => 15,
    ]);
    $secondWorkshopInput = $workshopInput;
    $secondWorkshopInput['code'] = $tag.'-DEST';
    $secondWorkshopInput['name'] = 'Synthetic M09 transfer destination';
    $secondWorkshop = $actions->saveWorkshop($dispatcher->id, null, 0, $secondWorkshopInput);
    $custody = app(App\Modules\WorkshopRepairs\Application\WorkshopCustody::class);
    $dispatchKey = (string) Str::uuid();
    $transferId = $custody->dispatch($dispatcher->id, $warrantyJob, 3, $secondWorkshop, $dispatchKey, 'Equipment sealed for internal movement.', 'Independent workshop capacity review.');
    $pass($transferId > 0 && $custody->dispatch($dispatcher->id, $warrantyJob, 3, $secondWorkshop, $dispatchKey, 'Equipment sealed for internal movement.', 'Independent workshop capacity review.') === $transferId,
        'custody transfer dispatch replays by matching immutable creation key');
    $warrantyVersion = (int) DB::table('workshop_jobs')->find($warrantyJob)->version;
    $receiveKey = (string) Str::uuid();
    $custody->receive($dispatcher->id, $warrantyJob, $warrantyVersion, $transferId, $receiveKey, 'Destination workshop received sealed equipment.');
    $custody->receive($dispatcher->id, $warrantyJob, $warrantyVersion, $transferId, $receiveKey, 'Destination workshop received sealed equipment.');
    $pass(DB::table('workshop_transfer_receipts')->where('transfer_id', $transferId)->count() === 1
        && DB::table('workshop_jobs')->where('id', $warrantyJob)->where('workshop_id', $secondWorkshop)->whereNull('pending_transfer_id')->exists(),
        'destination records one custody receipt and restores inspection status');
    $warrantyVersion = (int) DB::table('workshop_jobs')->find($warrantyJob)->version;
    $offersForWarranty = app(App\Modules\WorkshopRepairs\Application\RepairOffers::class);
    $reject(fn () => $offersForWarranty->nonbillable($outsider->id, $warrantyJob, $warrantyVersion, 'warranty', $jobId, 'Warranty denied: source repair has a different equipment identity.'),
        'warranty approval requires a prior delivered and accepted repair for the same equipment', 409);
    $reject(fn () => $offersForWarranty->nonbillable($outsider->id, $warrantyJob, $warrantyVersion, 'internal', null, 'Internal repair must reference Inventory isolation.'),
        'customer equipment cannot use the company-owned internal nonbillable basis', 422);

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
        'product_id' => $service->id, 'date' => $taxDate, 'valid_until' => CarbonImmutable::parse($taxDate)->addDays(14)->toDateString(), 'warranty_days' => 30,
        'warranty_conditions' => 'Warranty covers the accepted repair for thirty days.', 'pricing_reference' => 'Synthetic customer-approved repair pricing basis.',
        'parts_amount' => '0.00', 'labor_amount' => '0.00', 'other_amount' => '0.00',
        'parts_selling' => '100.00', 'labor_selling' => '0.00', 'other_selling' => '0.00', 'discount_amount' => '0.00', 'billable_amount' => '100.00',
    ]);
    fwrite(STDOUT, "STEP estimate\n");
    $estimate = DB::table('workshop_estimates')->find($estimateId);
    $pass($estimate && $estimate->revision === 1 && $estimate->billable_amount === '100.00'
        && is_array(json_decode($estimate->tax_snapshot, true)) && $estimate->tax_amount === '14.00',
        'M09 estimate captures configured M11 sale tax and exact billable decimal');
    $offers = app(App\Modules\WorkshopRepairs\Application\RepairOffers::class);
    $billableJobVersion = (int) DB::table('workshop_jobs')->find($billableJob)->version;
    $reject(fn () => $estimates->propose($dispatcher->id, $billableJob, $billableJobVersion, [
        'product_id' => $service->id, 'date' => $taxDate, 'valid_until' => CarbonImmutable::parse($taxDate)->addDays(14)->toDateString(), 'warranty_days' => 30,
        'warranty_conditions' => 'Warranty covers the accepted repair for thirty days.', 'pricing_reference' => 'Synthetic mismatched quote totals.',
        'parts_amount' => '0.00', 'labor_amount' => '0.00', 'other_amount' => '0.00',
        'parts_selling' => '100.00', 'labor_selling' => '0.00', 'other_selling' => '0.00', 'discount_amount' => '0.00', 'billable_amount' => '99.99',
    ]), 'quote selling lines must exactly equal net billable total', 422);
    $reject(fn () => $offers->issue($dispatcher->id, $billableJob, $billableJobVersion, $estimateId), 'quote issue is blocked before independent internal approval', 409);
    $reject(fn () => $offers->accept($dispatcher->id, $billableJob, $billableJobVersion, $estimateId, true, 'Premature acceptance attempt.'), 'customer acceptance is blocked before the official quote is issued', 409);
    $reject(fn () => $offers->approve($dispatcher->id, $billableJob, $billableJobVersion, $estimateId, 'Self approval must be denied.'), 'estimate proposer cannot independently approve the quote', 403);
    $offers->approve($outsider->id, $billableJob, $billableJobVersion, $estimateId, 'Independent repair price approval.');
    $pass(DB::table('workshop_quote_approvals')->where('estimate_id', $estimateId)->where('approved_by', $outsider->id)->exists(), 'independent price approval is stored separately from customer acceptance');
    $billableJobVersion = (int) DB::table('workshop_jobs')->find($billableJob)->version;
        try {
        $quoteId = $offers->issue($dispatcher->id, $billableJob, $billableJobVersion, $estimateId);
    } catch (Throwable $error) {
        fwrite(STDERR, "QUOTE_ISSUE_DIAGNOSTIC ".json_encode(['class' => $error::class, 'code' => $error->getCode(), 'status' => $error instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : null, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        throw $error;
    }
    $pass($quoteId > 0 && $offers->issue($dispatcher->id, $billableJob, $billableJobVersion, $estimateId) === $quoteId, 'approved customer quote issues one idempotent official document');
    $billableJobVersion = (int) DB::table('workshop_jobs')->find($billableJob)->version;
    $reject(fn () => $execution->state($technician->id, $billableJob, $billableJobVersion, 'start', 'Attempt before customer acceptance.'), 'repair execution is denied before independent customer acceptance', 409);
    $offers->accept($dispatcher->id, $billableJob, $billableJobVersion, $estimateId, true, 'Customer approved the issued quote in writing.');
    $pass(DB::table('workshop_estimate_approvals')->where('estimate_id', $estimateId)->where('approved_by', $dispatcher->id)->where('customer_accepted', true)->exists(), 'customer acceptance is separately persisted after quote issuance');
    $reject(fn () => $offers->accept($dispatcher->id, $billableJob, $billableJobVersion, $estimateId, true, 'Replayed stale customer acceptance.'), 'customer acceptance replay with stale version is rejected', 409);

    $parts = app(App\Modules\WorkshopRepairs\Application\WorkshopParts::class);
    $stockProduct = Product::query()->findOrFail(1);
    $originalRequirement = $parts->requirement($dispatcher->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, [
        'product_id' => $stockProduct->id, 'warehouse_id' => 1, 'quantity' => '0.25000000',
    ]);
    $alternateProduct = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true, 'base_unit_id' => $stockProduct->base_unit_id]);
    $reject(fn () => $parts->alternative($dispatcher->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, $originalRequirement, $alternateProduct->id, 'Requester cannot independently approve a different part.'),
        'requester cannot approve their own part alternative', 403);
    $alternativeRequirement = $parts->alternative($outsider->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, $originalRequirement, $alternateProduct->id, 'Independent approval substitutes a same-unit inventory item.');
    $pass(DB::table('workshop_part_alternatives')->where('original_requirement_id', $originalRequirement)->where('replacement_requirement_id', $alternativeRequirement)->where('approved_by', $outsider->id)->exists()
        && ! DB::table('workshop_part_reservations')->where('requirement_id', $originalRequirement)->exists(),
        'independent M09 parts approval supersedes an unallocated request before reservation');
    $stockBeforeParts = (string) DB::table('inventory_balances')->where('warehouse_id', 1)->where('product_id', $stockProduct->id)->value('on_hand');
    $stockRequirement = $parts->requirement($dispatcher->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, [
        'product_id' => $stockProduct->id, 'warehouse_id' => 1, 'quantity' => '1.00000000',
    ]);
    $stockReservation = $parts->reserve($dispatcher->id, $billableJob, $stockRequirement, '1.00000000', (string) Str::uuid());
    $pass($stockRequirement > 0 && $stockReservation['id'] > 0,
        'M09 parts requirement reserves actual available M06 stock in its branch warehouse');

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
    $reject(fn () => $execution->state($overlapTechnician->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, 'start', 'Duplicate start while already repairing.'),
        'repair cannot be started twice after its first transition', 409);
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
    $companyWorkStart = CarbonImmutable::now('UTC')->startOfMinute()->subMinutes(2);
    $companyWorkEnd = $companyWorkStart->addMinute();
    $companyWorkRecord = $execution->record($technician->id, $companyJob, (int) DB::table('workshop_jobs')->find($companyJob)->version, (string) Str::uuid(), [
        'started_at' => $companyWorkStart->toIso8601String(), 'ended_at' => $companyWorkEnd->toIso8601String(),
        'work_performed' => 'Internal company equipment repair under approved nonbillable authority.',
        'observations' => 'Equipment remained isolated from warehouse availability.', 'work_type' => 'repair',
    ]);
    $companyLabor = DB::table('workshop_labor_entries')->where('job_id', $companyJob)->where('work_record_id', $companyWorkRecord)->first();
    $execution->state($technician->id, $companyJob, (int) DB::table('workshop_jobs')->find($companyJob)->version, 'testing', 'Internal repair is ready for mandatory independent QC.');
    $reject(fn () => $execution->test($technician->id, $companyJob, (int) DB::table('workshop_jobs')->find($companyJob)->version, [
        'name' => 'Internal repair acceptance inspection', 'outcome' => 'waived', 'results' => 'Attempt to waive required company equipment QC.',
    ]), 'mandatory internal equipment QC cannot be waived', 422);
    $execution->test($technician->id, $companyJob, (int) DB::table('workshop_jobs')->find($companyJob)->version, [
        'name' => 'Internal repair acceptance inspection', 'outcome' => 'failed', 'results' => 'Initial check failed and requires corrective attention.',
    ]);
    $reject(fn () => $execution->verify($outsider->id, $companyJob, (int) DB::table('workshop_jobs')->find($companyJob)->version, 'Unverified internal equipment result.', 'Failed QC remains unresolved.'),
        'independent supervisor cannot verify a failed required internal QC test', 409);
    $execution->test($technician->id, $companyJob, (int) DB::table('workshop_jobs')->find($companyJob)->version, [
        'name' => 'Internal repair acceptance inspection', 'outcome' => 'passed', 'results' => 'Corrective work passed the mandatory functional test.',
    ]);
    $execution->verify($outsider->id, $companyJob, (int) DB::table('workshop_jobs')->find($companyJob)->version, 'Internal repair passed independent QC.', 'No outstanding electrical fault.');
    if (getenv('M09_TWO_WORKER_RACE') !== '1') {
        DB::statement("CREATE FUNCTION codex_m09_inject_labor_post_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.source_key LIKE 'workshop-labor:%' AND NEW.status = 'posted' THEN RAISE EXCEPTION 'codex injected M11 posting failure'; END IF; RETURN NEW; END; \$\$");
        DB::statement('CREATE TRIGGER codex_m09_inject_labor_post_failure BEFORE UPDATE ON accounting_journals FOR EACH ROW EXECUTE FUNCTION codex_m09_inject_labor_post_failure()');
        try {
            app(WorkshopFinance::class)->recognizeLabor($outsider->id, $companyJob, $companyLabor->id);
            throw new RuntimeException('Injected M11 posting failure was unexpectedly accepted.');
        } catch (QueryException $error) {
            if (! str_contains($error->getMessage(), 'codex injected M11 posting failure')) {
                throw $error;
            }
        } finally {
            DB::statement('DROP TRIGGER codex_m09_inject_labor_post_failure ON accounting_journals');
            DB::statement('DROP FUNCTION codex_m09_inject_labor_post_failure()');
        }
        $companyLaborSource = 'workshop-labor:'.$companyLabor->id;
        $pass(! DB::table('workshop_labor_recognitions')->where('labor_entry_id', $companyLabor->id)->exists()
            && ! DB::table('accounting_journals')->where('source_key', $companyLaborSource)->exists(),
            'injected M11 posting SQL failure rolls back journal and M09 labor-recognition source together');
    }
    $companyLaborJournal = app(WorkshopFinance::class)->recognizeLabor($outsider->id, $companyJob, $companyLabor->id);
    $companyOnHandBeforeRelease = (string) DB::table('inventory_balances')->where('warehouse_id', 1)->where('product_id', 1)->value('on_hand');
    $companyFifoBeforeRelease = DB::table('inventory_fifo_allocations')->where('movement_id', $sourceMovement->id)->count();
    $companyDeliveryKey = (string) Str::uuid();
    $companyDeliveryInput = [
        'recipient' => 'Synthetic inventory custodian', 'accessories' => $companyIntakeInput['accessories'],
        'final_condition' => 'Internal equipment repaired and passed QC.',
        'acknowledgment' => 'Custodian accepted the repaired company equipment.',
        'delivered_at' => CarbonImmutable::now('UTC')->subSeconds(30)->toIso8601String(), 'unrepaired' => false,
    ];
    if (getenv('M09_TWO_WORKER_RACE') === '1') {
        $companyDeliveryVersion = (int) DB::table('workshop_jobs')->find($companyJob)->version;
        DB::commit();
        $companyDeliveryRace = $runRaceWorkers(
            ['deliver', (string) $dispatcher->id, (string) $companyJob, $companyDeliveryKey, (string) $companyDeliveryVersion, base64_encode(json_encode($companyDeliveryInput, JSON_THROW_ON_ERROR))],
            ['deliver', (string) $dispatcher->id, (string) $companyJob, (string) Str::uuid(), (string) $companyDeliveryVersion, base64_encode(json_encode($companyDeliveryInput, JSON_THROW_ON_ERROR))]
        );
        DB::beginTransaction();
        $companyDeliveryResults = array_column($companyDeliveryRace, 'result');
        $companyDelivery = (int) DB::table('workshop_deliveries')->where('job_id', $companyJob)->value('id');
        $pass(count(array_filter($companyDeliveryResults, fn ($result) => $result === 'delivered')) === 1
            && count(array_filter($companyDeliveryResults, fn ($result) => $result === 'conflict')) === 1
            && DB::table('workshop_deliveries')->where('job_id', $companyJob)->count() === 1
            && DB::table('inventory_isolations')->where('id', $companyRow->isolation_id)->where('released', '1.00000000')->exists(),
            'competing internal-equipment deliveries produce one winner, one conflict and one isolation release');
    } else {
        $companyDelivery = $execution->deliver($dispatcher->id, $companyJob, (int) DB::table('workshop_jobs')->find($companyJob)->version, $companyDeliveryKey, $companyDeliveryInput);
    }
    $pass($companyLabor && $companyLabor->rate_id === $rateId && $companyLabor->cost === '1.00' && $companyLaborJournal > 0
        && $companyDelivery > 0 && DB::table('workshop_jobs')->where('id', $companyJob)->where('status', 'delivered')->exists()
        && DB::table('workshop_intakes')->where('id', $companyIntake)->where('status', 'delivered')->where('isolation_id', $companyRow->isolation_id)->exists()
        && DB::table('inventory_isolations')->where('id', $companyRow->isolation_id)->where('released', '1.00000000')->exists()
        && bccomp((string) DB::table('inventory_balances')->where('warehouse_id', 1)->where('product_id', 1)->value('on_hand'), $companyOnHandBeforeRelease, 8) === 0
        && DB::table('inventory_fifo_allocations')->where('movement_id', $sourceMovement->id)->count() === $companyFifoBeforeRelease,
        'internal equipment completes priced labor, mandatory independent QC and delivery; release restores availability without a purchase or FIFO movement');
    $pass(DB::table('workshop_jobs')->where('id', $billableJob)->where('status', 'repair')->exists(),
        'first repair start remains active after technician restoration');
    $laborStart = CarbonImmutable::now('UTC')->subMinutes(45)->startOfMinute();
    $laborEnd = CarbonImmutable::now('UTC')->subMinutes(15)->startOfMinute();
    $recordId = $execution->record($technician->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, (string) Str::uuid(), [
        'started_at' => $laborStart->toIso8601String(), 'ended_at' => $laborEnd->toIso8601String(),
        'work_performed' => 'Synthetic contact cleaning and electrical continuity work.', 'observations' => 'No abnormal heat observed during acceptance.', 'work_type' => 'repair',
    ]);
    $labor = DB::table('workshop_labor_entries')->where('job_id', $billableJob)->where('work_record_id', $recordId)->first();
    $pass($labor && $labor->rate_id === $rateId && $labor->cost === '30.00' && $labor->duration_minutes === 30,
        'work record snapshots approved rate into exact 30-minute labor cost');
    $issueKey = (string) Str::uuid();
    if (getenv('M09_TWO_WORKER_RACE') === '1') {
        DB::commit();
        $issueRace = $runRaceWorkers(['issue', (string) $dispatcher->id, (string) $billableJob, $issueKey, (string) $stockReservation['id'], '0.75000000']);
        DB::beginTransaction();
        $pass($issueRace[0]['movement_id'] === $issueRace[1]['movement_id']
            && DB::table('workshop_part_issues')->where('issue_key', $issueKey)->count() === 1
            && DB::table('inventory_movements')->where('idempotency_key', 'reservation:'.$issueKey)->count() === 1,
            'two independent workers racing the same workshop part issue converge on one inventory movement');
    }
    $issueEvidence = $parts->issue($dispatcher->id, $billableJob, (int) $stockReservation['id'], '0.75000000', $issueKey);
    $parts->usage($technician->id, $billableJob, $recordId, $stockRequirement, '0.50000000');
    $parts->release($dispatcher->id, $billableJob, (int) $stockReservation['id'], (string) Str::uuid());
    $returnEvidence = $parts->returnUnused($dispatcher->id, $billableJob, (int) DB::table('workshop_part_issues')->where('issue_key', $issueKey)->value('id'), '0.25000000', (string) Str::uuid(), 'Unused test quantity returned through the inventory correction workflow.');
    $partsTotals = $parts->totals($stockRequirement);
    $stockAfterParts = (string) DB::table('inventory_balances')->where('warehouse_id', 1)->where('product_id', $stockProduct->id)->value('on_hand');
    $pass($issueEvidence['movement_id'] > 0 && $returnEvidence['movement_id'] > 0
        && $partsTotals['issued'] === '0.75000000' && $partsTotals['used'] === '0.50000000' && $partsTotals['returned'] === '0.25000000'
        && bccomp(bcsub($stockBeforeParts, $stockAfterParts, 8), '0.50000000', 8) === 0
        && DB::table('inventory_fifo_allocations')->where('movement_id', $issueEvidence['movement_id'])->exists(),
        'M09 partial issue consumes real FIFO stock, records technician usage and returns only unused quantity');
    $stockBefore = [
        'movements' => DB::table('inventory_movements')->count(),
        'fifo_layers' => DB::table('inventory_fifo_layers')->count(),
        'balances' => DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
    $finance = app(WorkshopFinance::class);
    $laborJournal = $finance->recognizeLabor($outsider->id, $billableJob, $labor->id);
    $pass($laborJournal > 0 && $finance->recognizeLabor($outsider->id, $billableJob, $labor->id) === $laborJournal
        && DB::table('workshop_labor_recognitions')->where('labor_entry_id', $labor->id)->where('journal_id', $laborJournal)->count() === 1,
        'approved labor posts one M11 source journal and replay returns the original journal');
    $execution->state($technician->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, 'testing', 'Repair work is complete and ready for its required test.');
    $execution->test($technician->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, ['name' => 'M09 QA functional test', 'outcome' => 'passed', 'results' => 'Synthetic functional check passed.']);
    if (getenv('M09_TWO_WORKER_RACE') === '1') {
        $verifyVersion = (int) DB::table('workshop_jobs')->find($billableJob)->version;
        DB::commit();
        $verifyRace = $runRaceWorkers(['verify', (string) $outsider->id, (string) $billableJob, (string) $verifyVersion, 'Functional acceptance passed.', 'No unresolved electrical fault observed.']);
        DB::beginTransaction();
        $raceResults = array_column($verifyRace, 'result');
        $pass(count(array_filter($raceResults, fn ($result) => $result === 'verified')) === 1
            && count(array_filter($raceResults, fn ($result) => $result === 'conflict')) === 1
            && DB::table('workshop_verifications')->where('job_id', $billableJob)->count() === 1,
            'two independent QC workers produce one verification report and one stale-state conflict');
    } else {
        $execution->verify($outsider->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, 'Functional acceptance passed.', 'No unresolved electrical fault observed.');
    }
    $pass(DB::table('workshop_jobs')->where('id', $billableJob)->where('status', 'verified')->exists()
        && DB::table('workshop_verifications')->where('job_id', $billableJob)->exists(),
        'independent verification records test/report before M12 billing');
    $reject(fn () => $execution->verify($outsider->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, 'Duplicate verification attempt.', 'No remaining issue.'),
        'verified repair cannot be verified a second time with stale lifecycle state', 409);

    $invoiceKey = (string) Str::uuid();
    $invoiceDate = CarbonImmutable::parse($taxDate)->addDay()->toDateString();
    $dueDate = CarbonImmutable::parse($invoiceDate)->addDays(30)->toDateString();
    if (getenv('M09_TWO_WORKER_RACE') === '1') {
        DB::commit();
        $invoiceRace = $runRaceWorkers(['invoice', (string) $dispatcher->id, (string) $billableJob, $invoiceKey, $invoiceDate, $dueDate]);
        DB::beginTransaction();
        $pass($invoiceRace[0]['invoice_id'] === $invoiceRace[1]['invoice_id']
            && DB::table('rp_customer_invoices')->where('creation_key', $invoiceKey)->count() === 1,
            'two independent workers racing M12 invoice creation converge on one customer invoice');
    }
    $invoiceId = $finance->requestInvoice($dispatcher->id, $billableJob, $invoiceKey, $invoiceDate, $dueDate);
    $pass($invoiceId > 0 && $finance->requestInvoice($dispatcher->id, $billableJob, $invoiceKey, $invoiceDate, $dueDate) === $invoiceId,
        'M12 invoice request replays by creation key for the same verified source');
    $reject(fn () => $finance->requestInvoice($dispatcher->id, $billableJob, $invoiceKey, CarbonImmutable::parse($invoiceDate)->addDay()->toDateString(), $dueDate),
        'M12 rejects reuse of invoice creation key with a changed invoice date', 409);
    $invoice = DB::table('rp_customer_invoices')->find($invoiceId);
    $approvalId = DB::table('workshop_estimate_approvals')->where('estimate_id', $estimateId)->value('id');
    $invoiceSnapshot = json_decode($invoice->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
    $pass($invoice && $invoice->workshop_job_id === $billableJob && $invoice->net_amount === '100.00' && $invoice->tax_amount === '14.00'
        && $invoice->invoice_date === $invoiceDate && $invoiceSnapshot['quoted_tax']['approved_tax_date'] === $taxDate
        && $invoiceSnapshot['tax']['approved_tax_date'] === $invoiceDate
        && DB::table('rp_customer_invoice_lines')->where('invoice_id', $invoiceId)->where('workshop_approval_id', $approvalId)->exists(),
        'later-dated M12 invoice preserves the accepted M09 quote tax and records the matching actual invoice-date tax');
    $receivables = app(ReceivablesPayablesService::class);
    $receivables->validateCustomerInvoice($dispatcher->id, $invoiceId, (int) $invoice->version);
    $validated = DB::table('rp_customer_invoices')->find($invoiceId);
    $postResult = $receivables->postCustomerInvoice($dispatcher->id, $invoiceId, (int) $validated->version);
    $postReplay = $receivables->postCustomerInvoice($dispatcher->id, $invoiceId, (int) DB::table('rp_customer_invoices')->find($invoiceId)->version);
    $pass($postResult['journal_id'] === $postReplay['journal_id'] && DB::table('rp_customer_invoices')->where('id', $invoiceId)->where('status', 'posted')->exists()
        && DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoiceId)->where('original_amount', '114.00')->exists(),
        'M12 validates and posts the accepted M09 source exactly once to GL and customer AR');

    $invoiceStatusApi = app(ReceivablesPayablesApi::class);
    $treasury = app(TreasuryApi::class);
    $cashAccount = DB::table('treasury_accounts')->where('kind', 'cash')->where('active', true)->orderBy('id')->first();
    $openItem = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoiceId)->first();
    $statusBeforeCollection = $invoiceStatusApi->workshopInvoiceStatus($dispatcher->id, $billableJob, $branch->id);
    $pass($cashAccount !== null && $openItem !== null && $statusBeforeCollection['remaining'] === '114.00'
        && (int) $statusBeforeCollection['id'] === (int) $invoiceId && $statusBeforeCollection['status'] === 'posted',
        'M09 posted invoice status exposes its original AR balance to the explicitly scoped M12 reader');
    $collectionAmount = '57.00';
    $collectionKey = (string) Str::uuid();
    $collectionInput = ['idempotency_key' => $collectionKey, 'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id,
        'account_id' => (int) $cashAccount->id, 'date' => $invoiceDate, 'amount' => $collectionAmount, 'reference' => 'M09 partial customer repair collection'];
    $collectionAllocation = [['open_item_id' => (int) $openItem->id, 'amount' => $collectionAmount]];
    $partialCollection = $treasury->executeCustomerCollection((int) $dispatcher->id, $collectionInput, $collectionAllocation);
    $partialStatus = $invoiceStatusApi->workshopInvoiceStatus($dispatcher->id, $billableJob, $branch->id);
    $collectionCountsBeforeReplay = ['movements' => DB::table('treasury_movements')->where('idempotency_key', $collectionKey)->count(),
        'journals' => DB::table('accounting_journals')->where('source_key', 'customer-collection:'.$collectionKey)->count(),
        'allocations' => DB::table('rp_settlement_allocations')->where('movement_id', $partialCollection['movement_id'])->count()];
    $partialReplay = $treasury->executeCustomerCollection((int) $dispatcher->id, $collectionInput, $collectionAllocation);
    $collectionCountsAfterReplay = ['movements' => DB::table('treasury_movements')->where('idempotency_key', $collectionKey)->count(),
        'journals' => DB::table('accounting_journals')->where('source_key', 'customer-collection:'.$collectionKey)->count(),
        'allocations' => DB::table('rp_settlement_allocations')->where('movement_id', $partialCollection['movement_id'])->count()];
    $pass($partialCollection['movement_id'] === $partialReplay['movement_id'] && $partialCollection['journal_id'] === $partialReplay['journal_id']
        && $partialStatus['remaining'] === '57.00' && $partialStatus['status'] === 'partially_collected'
        && $collectionCountsBeforeReplay === ['movements' => 1, 'journals' => 1, 'allocations' => 1]
        && $collectionCountsAfterReplay === $collectionCountsBeforeReplay,
        'M13 partial cash collection updates M09 remaining AR and exact-key replay creates no extra movement, journal or allocation: '
            .json_encode(['status' => $partialStatus, 'before' => $collectionCountsBeforeReplay, 'after' => $collectionCountsAfterReplay,
                'first' => $partialCollection, 'replay' => $partialReplay], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $finalCollectionKey = (string) Str::uuid();
    $finalCollection = $treasury->executeCustomerCollection((int) $dispatcher->id, [
        'idempotency_key' => $finalCollectionKey, 'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id,
        'account_id' => (int) $cashAccount->id, 'date' => $invoiceDate, 'amount' => '57.00', 'reference' => 'M09 final customer repair collection',
    ], [['open_item_id' => (int) $openItem->id, 'amount' => '57.00']]);
    $finalStatus = $invoiceStatusApi->workshopInvoiceStatus($dispatcher->id, $billableJob, $branch->id);
    $collectionJournalIds = [$partialCollection['journal_id'], $finalCollection['journal_id']];
    $collectionJournalIntegrity = true;
    foreach ($collectionJournalIds as $journalId) {
        $journalLines = DB::table('accounting_journal_lines')->where('journal_id', $journalId)->get();
        $debits = '0.00'; $credits = '0.00';
        foreach ($journalLines as $journalLine) {
            $debits = bcadd($debits, (string) $journalLine->debit, 2);
            $credits = bcadd($credits, (string) $journalLine->credit, 2);
        }
        $collectionJournalIntegrity = $collectionJournalIntegrity && $journalLines->count() === 2
            && $debits === '57.00' && $credits === '57.00'
            && DB::table('accounting_journals')->where('id', $journalId)->where('status', 'posted')->exists();
    }
    $settlementTotals = DB::table('rp_settlement_allocations')->where('open_item_id', $openItem->id)
        ->selectRaw('count(*) as allocation_count, coalesce(sum(amount),0)::numeric(20,2)::text as amount')->first();
    $settledOpenItem = DB::table('rp_open_items')->find($openItem->id);
    $pass($finalCollection['movement_id'] > 0 && $finalStatus['remaining'] === '0.00' && $finalStatus['status'] === 'collected'
        && $settledOpenItem->settled_amount === '114.00' && $settledOpenItem->remaining_amount === '0.00'
        && (int) $settlementTotals->allocation_count === 2 && $settlementTotals->amount === '114.00'
        && DB::table('treasury_movements')->whereIn('idempotency_key', [$collectionKey, $finalCollectionKey])->where('kind', 'customer_collection')->count() === 2
        && $collectionJournalIntegrity && DB::table('accounting_journals')->where('source_key', 'customer-invoice:'.$invoiceId)->count() === 1,
        'M13 final cash collection closes M09 AR with two balanced cash journals and no duplicate M12 invoice GL');

    $billableDeliveryKey = (string) Str::uuid();
    $billableDeliveryInput = [
        'recipient' => 'Synthetic customer equipment owner', 'accessories' => $billableInput['accessories'],
        'final_condition' => 'Accepted repair completed and independently verified.',
        'acknowledgment' => 'Customer accepted repaired equipment and accessories.',
        'delivered_at' => CarbonImmutable::now('UTC')->subSeconds(20)->toIso8601String(), 'unrepaired' => false,
    ];
    fwrite(STDOUT, "STEP customer-delivery\n");
    if (getenv('M09_TWO_WORKER_RACE') === '1') {
        $deliveryVersion = (int) DB::table('workshop_jobs')->find($billableJob)->version;
        DB::commit();
        $deliveryRace = $runRaceWorkers(
            ['deliver', (string) $dispatcher->id, (string) $billableJob, $billableDeliveryKey, (string) $deliveryVersion, base64_encode(json_encode($billableDeliveryInput, JSON_THROW_ON_ERROR))],
            ['deliver', (string) $dispatcher->id, (string) $billableJob, (string) Str::uuid(), (string) $deliveryVersion, base64_encode(json_encode($billableDeliveryInput, JSON_THROW_ON_ERROR))]
        );
        DB::beginTransaction();
        $deliveryResults = array_column($deliveryRace, 'result');
        $billableDelivery = (int) DB::table('workshop_deliveries')->where('job_id', $billableJob)->value('id');
        $pass(count(array_filter($deliveryResults, fn ($result) => $result === 'delivered')) === 1
            && count(array_filter($deliveryResults, fn ($result) => $result === 'conflict')) === 1
            && $billableDelivery > 0 && DB::table('workshop_deliveries')->where('job_id', $billableJob)->count() === 1,
            'two independent delivery workers produce one immutable delivery and one stale-state conflict');
    } else {
        $billableDelivery = $execution->deliver($dispatcher->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, $billableDeliveryKey, $billableDeliveryInput);
        $pass($billableDelivery > 0 && $execution->deliver($dispatcher->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, $billableDeliveryKey, $billableDeliveryInput) === $billableDelivery,
            'customer delivery replays by its original key and exact immutable payload');
        $differentDeliveryInput = $billableDeliveryInput;
        $differentDeliveryInput['recipient'] = 'Different recipient must not reuse the delivery key';
        $reject(fn () => $execution->deliver($dispatcher->id, $billableJob, (int) DB::table('workshop_jobs')->find($billableJob)->version, $billableDeliveryKey, $differentDeliveryInput),
            'customer delivery rejects same key with a different recipient payload', 409);
    }
    $repeatWarrantyInput = $billableInput;
    fwrite(STDOUT, "STEP warranty-repeat-save\n");
    $repeatWarrantyIntake = $actions->saveIntake($dispatcher->id, null, 0, (string) Str::uuid(), $repeatWarrantyInput);
    fwrite(STDOUT, "STEP warranty-repeat-receive\n");
    $actions->receive($dispatcher->id, $repeatWarrantyIntake, 1, (string) Str::uuid(), CarbonImmutable::now('UTC')->subSeconds(10)->toIso8601String(), 'Repeat same customer equipment received inside approved repair warranty.');
    fwrite(STDOUT, "STEP warranty-repeat-job\n");
    $repeatWarrantyJob = $actions->createJob($dispatcher->id, $repeatWarrantyIntake, 2, (string) Str::uuid(), $workshopId, 'normal', 'warranty');
    $actions->assign($dispatcher->id, $repeatWarrantyJob, 1, $workshopId, $technician->id, 'Assign same-equipment warranty inspection.');
    $actions->diagnose($technician->id, $repeatWarrantyJob, 2, [
        'required_tests' => [['name' => 'Warranty repeat inspection', 'required' => true]],
        'diagnosis' => 'Repeat failure report for the exact previously delivered customer equipment.',
        'planned_work' => 'Inspect accepted repair warranty claim and verify current fault.', 'estimated_minutes' => 15,
    ]);
    fwrite(STDOUT, "STEP warranty-repeat-approve\n");
    $previousTestNow = Carbon::getTestNow();
    try {
        $deliveredAt = DB::table('workshop_deliveries')->where('job_id', $billableJob)->value('delivered_at');
        Carbon::setTestNow(CarbonImmutable::parse($deliveredAt, 'UTC')->addDays(31));
        $reject(fn () => app(App\Modules\WorkshopRepairs\Application\RepairOffers::class)->nonbillable($outsider->id, $repeatWarrantyJob, 3, 'warranty', $billableJob,
            'Denied because the exact prior accepted repair warranty expired before this claim.'),
            'expired same-equipment warranty is denied after its accepted quote warranty days', 409);
    } finally {
        Carbon::setTestNow($previousTestNow);
    }
    $pass(! DB::table('workshop_nonbillable_authorizations')->where('job_id', $repeatWarrantyJob)->exists(),
        'expired-warranty denial leaves the customer equipment claim without an authorization');
    app(App\Modules\WorkshopRepairs\Application\RepairOffers::class)->nonbillable($outsider->id, $repeatWarrantyJob, 3, 'warranty', $billableJob,
        'Independent approval under the still-active accepted repair warranty for the same equipment.');
    $pass($billableDelivery > 0 && DB::table('workshop_jobs')->where('id', $billableJob)->where('status', 'delivered')->exists()
        && DB::table('workshop_intakes')->where('id', $repeatWarrantyIntake)->where('equipment_identity', DB::table('workshop_intakes')->where('id', $billableIntake)->value('equipment_identity'))->exists()
        && DB::table('workshop_nonbillable_authorizations')->where('job_id', $repeatWarrantyJob)->where('kind', 'warranty')->where('source_job_id', $billableJob)->exists(),
        'repeat intake for the same delivered equipment uses its accepted in-date quote warranty authorization');

    $stockAfter = [
        'movements' => DB::table('inventory_movements')->count(),
        'fifo_layers' => DB::table('inventory_fifo_layers')->count(),
        'balances' => DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
    $pass($stockAfter === $stockBefore,
        'subsequent labor and invoice posting do not add inventory movements, FIFO layers or balance changes');

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

    fwrite(STDOUT, "PASS {$assertions} WorkshopRepairs financial assertions completed on isolated PostgreSQL {$auditPort}.\n");
} finally {
    DB::rollBack();
}


