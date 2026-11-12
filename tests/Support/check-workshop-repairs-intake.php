<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use App\Modules\WorkshopRepairs\Application\WorkshopExecution;
use App\Modules\WorkshopRepairs\Domain\Workshop;
use App\Modules\WorkshopRepairs\Domain\WorkshopIntake;
use App\Modules\WorkshopRepairs\Domain\WorkshopJob;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkshopDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_HOST=127.0.0.1');
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
putenv('DB_PORT=55447');
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = '55447';
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

$identity = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || $identity->db !== 'erp_elec_new_test'
    || ! in_array($identity->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || (int) $identity->port !== 55447
    || ! DB::table('migrations')->where('migration', '2026_10_30_188000_create_workshop_repairs')->exists()) {
    throw new RuntimeException('ABORT before writes: requires disposable PostgreSQL erp_elec_new_test on 127.0.0.1:55447 with migration 188 applied.');
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
    $customer = Customer::factory()->create(['code' => $tag, 'name' => 'عميل ورشة اختبار القبول']);
    $dispatcher = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $technician = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    User::factory()->disabledDemo()->create(['email' => 'demo.user99@example.test']);

    foreach ([$dispatcher, $technician] as $user) {
        DB::table('user_branch_scopes')->insert([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    foreach (['manage', 'assign', 'intake_manage', 'custody', 'job_manage', 'verify', 'deliver'] as $permission) {
        DB::table('access_grants')->insert([
            'user_id' => $dispatcher->id,
            'permission_code' => 'M09.'.$permission,
            'branch_id' => $branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    foreach (['execute', 'assign', 'labor_record'] as $permission) {
        DB::table('access_grants')->insert([
            'user_id' => $technician->id,
            'permission_code' => 'M09.'.$permission,
            'branch_id' => $branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    DB::table('access_grants')->insert([
        'user_id' => $dispatcher->id,
        'permission_code' => 'M03.customer_view',
        'branch_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $actions = app(WorkshopActions::class);
    $workshopInput = [
        'code' => $tag,
        'name' => 'ورشة اختبار القبول',
        'specialty' => 'فحص وإصلاح القواطع',
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
    $staleWorkshopInput['name'] = 'تعديل قديم';
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
        'equipment_description' => 'قاطع رئيسي وارد للفحص التجريبي',
        'model' => 'DEMO-M09-BREAKER',
        'serial_number' => 'DEMO-M09-SERIAL-'.Str::upper(Str::random(6)),
        'accessories' => 'ملحق اختبار فقط',
        'reported_fault' => 'فصل متكرر وفق بلاغ تجريبي.',
        'arrival_condition' => 'الملصق سليم والجسم دون تلف ظاهر.',
        'customer_owned' => true,
        'location' => 'استلام الورشة',
    ];
    $intakeId = $actions->saveIntake($dispatcher->id, null, 0, $intakeKey, $intakeInput);
    $pass($intakeId > 0 && $actions->saveIntake($dispatcher->id, null, 0, $intakeKey, $intakeInput) === $intakeId,
        'intake creation key safely replays the same draft after a lost response');
    $intake = DB::table('workshop_intakes')->find($intakeId);
    $pass($intake->status === 'draft' && $intake->version === 1 && $intake->customer_owned === true,
        'customer property intake remains a draft without receiving or company-stock effect');

    $updatedInput = $intakeInput;
    $updatedInput['accessories'] = 'ملحق الاختبار التجريبي وكتيب الجهاز';
    $actions->saveIntake($dispatcher->id, $intakeId, 1, (string) Str::uuid(), $updatedInput);
    $pass(DB::table('workshop_intakes')->where('id', $intakeId)->where('version', 2)->where('accessories', $updatedInput['accessories'])->exists(),
        'draft intake can be saved with its current version while preserving the customer property snapshot');
    $reject(fn () => $actions->saveIntake($dispatcher->id, $intakeId, 1, (string) Str::uuid(), $intakeInput),
        'stale intake version is rejected', 409);

    $receiptKey = (string) Str::uuid();
    $actions->receive($dispatcher->id, $intakeId, 2, $receiptKey, CarbonImmutable::now('UTC')->subMinute()->toIso8601String(), 'تم استلام القاطع والملحقات للتجربة.');
    $actions->receive($dispatcher->id, $intakeId, 2, $receiptKey, CarbonImmutable::now('UTC')->subMinute()->toIso8601String(), 'تم استلام القاطع والملحقات للتجربة.');
    $pass(DB::table('workshop_intakes')->where('id', $intakeId)->where('status', 'received')->where('version', 3)->exists()
        && DB::table('workshop_custody_events')->where('intake_id', $intakeId)->where('event_key', $receiptKey)->where('status', 'received')->count() === 1,
        'receipt replay with the same key records one immutable custody event');
    $reject(fn () => $actions->receive($dispatcher->id, $intakeId, 2, (string) Str::uuid(), CarbonImmutable::now('UTC')->subMinute()->toIso8601String(), 'محاولة استلام مكررة.'),
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

    $actions->assign($dispatcher->id, $jobId, 1, $workshopId, $technician->id, 'تعيين فني الورشة للفحص الأولي.');
    $pass(DB::table('workshop_jobs')->where('id', $jobId)->where('technician_id', $technician->id)->where('version', 2)->exists(),
        'dispatcher assigns only the active workshop member and advances job version');
    $reject(fn () => $actions->assign($dispatcher->id, $jobId, 1, $workshopId, $technician->id, 'إعادة إرسال تعيين قديم.'),
        'stale job version cannot repeat assignment', 409);
    $actions->diagnose($technician->id, $jobId, 2, [
        'diagnosis' => 'العطل متقطع ويحتاج فحص نقاط التلامس.',
        'planned_work' => 'تنظيف نقاط التلامس ثم فحص التشغيل وفق إجراء المصنع.',
        'required_tests' => [['name' => 'فحص التشغيل وفق إجراء المصنع', 'required' => true]],
        'estimated_minutes' => 45,
    ]);
    $pass(DB::table('workshop_jobs')->where('id', $jobId)->where('status', 'inspection')->where('diagnosis', 'العطل متقطع ويحتاج فحص نقاط التلامس.')->where('planned_work', 'تنظيف نقاط التلامس ثم فحص التشغيل وفق إجراء المصنع.')->where('version', 3)->exists(),
        'assigned technician records diagnosis, planned work and required tests without changing status to repaired or tested');
    $reject(fn () => $actions->diagnose($outsider->id, $jobId, 3, ['diagnosis' => 'محاولة تشخيص غير مصرح بها.', 'planned_work' => 'تنفيذ عمل غير معتمد.', 'required_tests' => [['name' => 'فحص تجريبي', 'required' => true]]]),
        'unscoped user cannot diagnose the workshop job', 403);

    $execution = app(WorkshopExecution::class);
    $reject(fn () => $execution->state($technician->id, $jobId, 3, 'start', 'محاولة بدء قبل الموافقة.'),
        'repair cannot start while the estimate has no independent customer approval', 409);
    $startTime = CarbonImmutable::now('UTC')->startOfMinute()->subMinutes(30);
    $endTime = $startTime->addMinutes(15);
    $reject(fn () => $execution->record($technician->id, $jobId, 3, (string) Str::uuid(), [
        'started_at' => $startTime->toIso8601String(),
        'ended_at' => $endTime->toIso8601String(),
        'work_performed' => 'محاولة تسجيل عمل قبل الموافقة والتنفيذ.',
        'observations' => 'لا توجد ملاحظة تنفيذ فعلية.',
        'work_type' => 'فحص',
    ]), 'work record cannot be captured before the approved repair is started', 409);
    $reject(fn () => $execution->test($technician->id, $jobId, 3, [
        'name' => 'فحص التشغيل وفق إجراء المصنع',
        'outcome' => 'passed',
        'results' => 'محاولة تسجيل نتيجة قبل الاختبار الفعلي.',
    ]), 'test evidence cannot be recorded before the job enters testing', 409);
    $reject(fn () => $execution->verify($dispatcher->id, $jobId, 3, 'حالة سليمة.', 'لا توجد أعطال متبقية.'),
        'supervisor cannot verify a job before execution and required tests', 409);
    $pass(! DB::table('workshop_work_records')->where('job_id', $jobId)->exists()
        && ! DB::table('workshop_tests')->where('job_id', $jobId)->exists()
        && ! DB::table('workshop_verifications')->where('job_id', $jobId)->exists(),
        'rejected lifecycle attempts create no labor, test or verification evidence');
    $reject(fn () => $execution->unrepairable($technician->id, $jobId, 3, 'محاولة إرجاع دون صلاحية التحقق.'),
        'assigned technician cannot release equipment as unrepairable without verification authority', 403);
    $execution->unrepairable($dispatcher->id, $jobId, 3, 'القطعة غير قابلة للإصلاح وفق نتيجة الفحص الأولي.');
    $pass(DB::table('workshop_jobs')->where('id', $jobId)->where('status', 'rejected')->where('version', 4)->exists()
        && DB::table('workshop_intakes')->where('id', $intakeId)->where('status', 'not_repairable')->exists()
        && DB::table('workshop_custody_events')->where('intake_id', $intakeId)->where('status', 'not_repairable')->exists(),
        'authorized unrepairable decision keeps a separate repair result and an auditable custody event');
    $deliveryKey = (string) Str::uuid();
    $deliveryInput = [
        'recipient' => 'محمود حسن، مندوب العميل',
        'accessories' => $updatedInput['accessories'],
        'final_condition' => 'القاطع غير قابل للإصلاح ويعاد بالحالة المسجلة.',
        'acknowledgment' => 'استلم المندوب القاطع والملحقات دون إصلاح.',
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
        CarbonImmutable::now('UTC')->subMinute()->toIso8601String(), 'استلام معدة مسجلة كملكية للشركة للفحص فقط.');
    $companyIntake = DB::table('workshop_intakes')->find($companyIntakeId);
    $companyJobId = $actions->createJob($dispatcher->id, $companyIntakeId, $companyIntake->version, (string) Str::uuid(), $workshopId, 'normal', 'nonbillable');
    $pass(DB::table('workshop_intakes')->where('id', $companyIntakeId)->where('customer_owned', false)->where('status', 'inspection')->exists()
        && DB::table('workshop_jobs')->where('id', $companyJobId)->exists(),
        'company-owned equipment may be explicitly tracked for repair without converting its custody into stock');

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
        CarbonImmutable::now('UTC')->subMinutes(10)->toIso8601String(), 'استلام حقيقي ضمن تجهيز اختبار المصنع.');
    $factoryJob = WorkshopJob::factory()->create(['workshop_id' => $factoryWorkshop->id, 'created_by' => $dispatcher->id]);
    $pass($factoryWorkshop->exists && $factoryIntake->exists && $factoryJob->exists
        && DB::table('workshop_intakes')->where('id', $factoryIntake->id)->where('status', 'inspection')->where('workshop_id', $factoryWorkshop->id)->exists()
        && DB::table('workshop_custody_events')->where('intake_id', $factoryIntake->id)->where('status', 'inspection')->where('event_key', $factoryJob->creation_key)->exists()
        && ! DB::table('workshop_estimates')->where('job_id', $factoryJob->id)->exists()
        && ! DB::table('workshop_work_records')->where('job_id', $factoryJob->id)->exists(),
        'Workshop factories persist configuration, draft intake and a valid received-to-inspection job graph without estimates or performed work');

    app(WorkshopDemoSeeder::class)->run();
    app(WorkshopDemoSeeder::class)->run();
    $demoWorkshops = DB::table('workshops')->whereIn('code', ['DEMO-EG-WS-001', 'DEMO-EG-WS-002', 'DEMO-EG-WS-003', 'DEMO-EG-WS-004'])->orderBy('code')->get();
    $demoWorkshop = $demoWorkshops->first();
    $demoIntake = DB::table('workshop_intakes')->where('number', 'DEMO-EG-WI-001')->first();
    $pass($demoWorkshops->count() === 4 && $demoWorkshops->every(fn ($workshop) => ! $workshop->active)
        && DB::table('workshops')->where('code', 'DEMO-EG-WS-001')->count() === 1
        && DB::table('workshop_intakes')->where('number', 'DEMO-EG-WI-001')->count() === 1
        && $demoWorkshop && $demoIntake && $demoIntake->status === 'draft'
        && $demoIntake->received_at === null && $demoIntake->received_by === null
        && ! DB::table('workshop_members')->whereIn('workshop_id', $demoWorkshops->pluck('id'))->exists()
        && ! DB::table('workshop_rates')->where('branch_id', $demoWorkshop->branch_id)->exists()
        && ! DB::table('workshop_jobs')->where('intake_id', $demoIntake->id)->exists()
        && ! DB::table('workshop_custody_events')->where('intake_id', $demoIntake->id)->exists(),
        'explicit Workshop demo seeder is repeat-safe and creates four inactive workshop configurations plus one unreceived draft');

    fwrite(STDOUT, "PASS {$assertions} WorkshopRepairs focused assertions completed on isolated PostgreSQL 55447.\n");
} finally {
    DB::rollBack();
}
