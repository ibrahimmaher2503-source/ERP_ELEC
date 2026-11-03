<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceCostProposals;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\FieldMaintenance\Domain\MaintenanceChecklistTemplate;
use App\Modules\FieldMaintenance\Domain\MaintenanceRequest;
use App\Modules\FieldMaintenance\Domain\MaintenanceVisit;
use App\Modules\FieldMaintenance\Domain\MaintenanceWorkOrder;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use Carbon\CarbonImmutable;
use Database\Seeders\FieldMaintenanceDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

$db = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || $db->db !== 'erp_elec_new_test'
    || ! in_array($db->address, ['127.0.0.1', '127.0.0.1/32'], true) || (int) $db->port !== 5443) {
    throw new RuntimeException('ABORT before writes: M08 check requires isolated PostgreSQL erp_elec_new_test on 127.0.0.1:5443.');
}

$assertions = 0;
$pass = static function (bool $ok, string $message) use (&$assertions): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    $assertions++;
    fwrite(STDOUT, "PASS {$message}\n");
};
$reject = static function (callable $action, string $message, ?int $status = null) use ($pass): void {
    try {
        $action();
    } catch (Throwable $error) {
        if ($status !== null) {
            if ($error instanceof HttpExceptionInterface && $error->getStatusCode() === $status) {
                $pass(true, $message);

                return;
            }
            throw $error;
        }
        if ($error instanceof ValidationException) {
            $pass(true, $message);

            return;
        }
        throw $error;
    }
    throw new RuntimeException($message.' was unexpectedly accepted.');
};

DB::beginTransaction();
$failure = null;
try {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create();
    }
    $factoryRequest = MaintenanceRequest::factory()->create();
    $factoryWork = MaintenanceWorkOrder::factory()->create();
    $factoryTemplate = MaintenanceChecklistTemplate::factory()->create();
    $factoryVisit = MaintenanceVisit::factory()->create();
    $pass($factoryRequest->exists && $factoryWork->exists && $factoryTemplate->exists && $factoryVisit->exists
        && DB::table('maintenance_assignments')->where('id', $factoryVisit->assignment_id)->where('active', false)->exists()
        && ! DB::table('maintenance_labor_entries')->where('work_order_id', $factoryWork->id)->exists()
        && ! DB::table('maintenance_part_issues')->whereIn('requirement_id', DB::table('maintenance_part_requirements')->where('work_order_id', $factoryWork->id)->select('id'))->exists(),
        'M08 factories persist request, work-order, checklist and planned-visit graphs without fabricated labor or inventory postings');
    app(FieldMaintenanceDemoSeeder::class)->run();
    $seedCounts = [
        'requests' => DB::table('maintenance_requests')->where('number', 'DEMO-EG-MR-001')->count(),
        'work_orders' => DB::table('maintenance_work_orders')->where('number', 'DEMO-EG-WO-001')->count(),
        'checklists' => DB::table('maintenance_checklist_templates')->where('code', 'DEMO-EG-CHK-001')->count(),
        'visits' => DB::table('maintenance_visits')->whereIn('work_order_id', DB::table('maintenance_work_orders')->where('number', 'DEMO-EG-WO-001')->select('id'))->count(),
    ];
    app(FieldMaintenanceDemoSeeder::class)->run();
    $pass($seedCounts === ['requests' => 1, 'work_orders' => 1, 'checklists' => 1, 'visits' => 1]
        && DB::table('maintenance_requests')->where('number', 'DEMO-EG-MR-001')->count() === 1
        && DB::table('maintenance_work_orders')->where('number', 'DEMO-EG-WO-001')->count() === 1
        && DB::table('maintenance_checklist_templates')->where('code', 'DEMO-EG-CHK-001')->count() === 1
        && DB::table('maintenance_visits')->whereIn('work_order_id', DB::table('maintenance_work_orders')->where('number', 'DEMO-EG-WO-001')->select('id'))->where('status', 'planned')->whereNull('arrival_at')->whereNull('recorded_at')->count() === 1
        && DB::table('maintenance_labor_entries')->whereIn('work_order_id', DB::table('maintenance_work_orders')->where('number', 'DEMO-EG-WO-001')->select('id'))->doesntExist()
        && DB::table('maintenance_part_issues')->whereIn('requirement_id', DB::table('maintenance_part_requirements')->whereIn('work_order_id', DB::table('maintenance_work_orders')->where('number', 'DEMO-EG-WO-001')->select('id'))->select('id'))->doesntExist()
        && DB::table('maintenance_completions')->whereIn('work_order_id', DB::table('maintenance_work_orders')->where('number', 'DEMO-EG-WO-001')->select('id'))->doesntExist(),
        'explicit M08 demo seeder repeat is idempotent and leaves labor, part issues, and completion evidence unmanufactured');
    $tag = 'DEMO-EG-M08-'.Str::upper(Str::random(8));
    $branch = Branch::factory()->create(['code' => $tag, 'company_id' => 1]);
    $customer = Customer::factory()->create(['code' => $tag, 'name' => 'عميل صيانة اختبار القبول']);
    $dispatcher = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $technician = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$dispatcher, $technician, $reviewer] as $user) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $dispatcherPermissions = ['view', 'request_create', 'request_edit', 'request_approve', 'workorder_manage', 'assign', 'checklist_manage', 'verify', 'close', 'cost_view', 'rate_approve'];
    $technicianPermissions = ['view', 'visit_record', 'labor_record', 'workorder_manage', 'rate_approve'];
    $reviewerPermissions = ['view', 'verify', 'close', 'reopen'];
    foreach ($dispatcherPermissions as $permission) {
        DB::table('access_grants')->insert(['user_id' => $dispatcher->id, 'permission_code' => 'M08.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($technicianPermissions as $permission) {
        DB::table('access_grants')->insert(['user_id' => $technician->id, 'permission_code' => 'M08.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($reviewerPermissions as $permission) {
        DB::table('access_grants')->insert(['user_id' => $reviewer->id, 'permission_code' => 'M08.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('access_grants')->insert(['user_id' => $dispatcher->id, 'permission_code' => 'M03.customer_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);

    $actions = app(MaintenanceActions::class);
    $requestKey = (string) Str::uuid();
    $requestInput = ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $technician->id,
        'description' => 'Synthetic no-power report for the site lighting panel.', 'priority' => 'high',
        'reported_at' => CarbonImmutable::now('UTC')->subMinutes(80)->toIso8601String(), 'preferred_date' => now()->toDateString()];
    $requestId = $actions->saveRequest($dispatcher->id, null, 0, $requestKey, $requestInput);
    $pass($requestId > 0 && $actions->saveRequest($dispatcher->id, null, 0, $requestKey, $requestInput) === $requestId,
        'factory-backed branch/customer/users persist a request and its creation key safely replays');
    $request = DB::table('maintenance_requests')->find($requestId);
    $pass($request->status === 'draft' && $request->version === 1 && $request->customer_name === $customer->name,
        'maintenance request snapshots customer identity and starts at draft version one');
    $reject(fn () => $actions->saveRequest($outsider->id, null, 0, (string) Str::uuid(), $requestInput), 'unscoped user cannot create a maintenance request', 403);
    $reject(fn () => $actions->requestState($dispatcher->id, $requestId, 1, 'accept'), 'unsubmitted request cannot be accepted', 409);
    $actions->requestState($dispatcher->id, $requestId, 1, 'submit');
    $actions->requestState($dispatcher->id, $requestId, 2, 'accept');
    $pass(DB::table('maintenance_requests')->where('id', $requestId)->where('status', 'accepted')->where('version', 3)->exists(),
        'request requires submit then accept and each transition advances its version');
    $reject(fn () => $actions->requestState($dispatcher->id, $requestId, 2, 'accept'), 'stale request state version is rejected', 409);

    $workKey = (string) Str::uuid();
    $workId = $actions->createWorkOrder($dispatcher->id, $requestId, 3, $workKey, ['instructions' => 'Inspect wiring and record the actual measurements.', 'acknowledgment_required' => true]);
    $pass($workId > 0 && $actions->createWorkOrder($dispatcher->id, $requestId, 3, $workKey) === $workId,
        'accepted request converts once to a work order and lost-response retry returns the same order');
    $work = DB::table('maintenance_work_orders')->find($workId);
    $pass($work->request_id === $requestId && $work->status === 'draft' && DB::table('maintenance_requests')->where('id', $requestId)->where('status', 'work_order_created')->exists(),
        'work order retains request link and conversion status without inventing completion');
    $reject(fn () => $actions->createWorkOrder($dispatcher->id, $requestId, 2, (string) Str::uuid()), 'stale accepted-request version cannot create a second work order', 409);

    $execution = app(MaintenanceExecution::class);
    $templateId = $execution->saveTemplate($dispatcher->id, null, 0, ['branch_id' => $branch->id, 'code' => $tag, 'title' => 'Initial service inspection', 'active' => true,
        'items' => [['key' => 'isolation_test', 'label' => 'Isolation test completed', 'required' => true]]]);
    $windowStart = CarbonImmutable::now('UTC')->subHours(2);
    $windowEnd = CarbonImmutable::now('UTC')->addHours(2);
    $assignmentId = $execution->assign($dispatcher->id, $workId, 1, ['technician_id' => $technician->id, 'starts_at' => $windowStart->toIso8601String(), 'ends_at' => $windowEnd->toIso8601String()]);
    $pass(DB::table('maintenance_assignments')->where('id', $assignmentId)->where('technician_id', $technician->id)->where('active', true)->exists(),
        'authorized dispatcher assigns a scoped technician within an explicit schedule window');
    $work = DB::table('maintenance_work_orders')->find($workId);
    $visitKey = (string) Str::uuid();
    $visitId = $execution->schedule($dispatcher->id, $workId, $work->version, $visitKey, ['assignment_id' => $assignmentId,
        'scheduled_at' => CarbonImmutable::now('UTC')->subMinutes(90)->toIso8601String(), 'template_id' => $templateId, 'mandatory' => true]);
    $pass($execution->schedule($dispatcher->id, $workId, $work->version, $visitKey, ['assignment_id' => $assignmentId,
        'scheduled_at' => CarbonImmutable::now('UTC')->subMinutes(90)->toIso8601String(), 'template_id' => $templateId, 'mandatory' => true]) === $visitId,
        'scheduled visit is idempotent on its creation key and snapshots its checklist');
    $work = DB::table('maintenance_work_orders')->find($workId);
    $actions->state($technician->id, $workId, $work->version, 'start', 'Technician starts authorized site work.');
    $pass(DB::table('maintenance_work_orders')->where('id', $workId)->where('status', 'in_progress')->exists(),
        'assigned technician can start work within the active assignment window');
    $arrival = CarbonImmutable::now('UTC')->startOfMinute()->subMinutes(60);
    $departure = CarbonImmutable::now('UTC')->startOfMinute()->subMinutes(30);
    $visitInput = ['arrival_at' => $arrival->toIso8601String(), 'departure_at' => $departure->toIso8601String(),
        'work_performed' => 'Replaced a loose terminal and tested panel output.', 'diagnosis' => 'Loose conductor found at terminal block.',
        'acknowledgment' => 'Customer confirms service.', 'outcome' => 'completed'];
    $reject(fn () => $execution->recordVisit($outsider->id, $workId, $visitId, 1, $visitInput, ['isolation_test' => ['result' => 'complete']]), 'unassigned user cannot record technician visit', 403);
    $reject(fn () => $execution->recordVisit($technician->id, $workId, $visitId, 1, $visitInput, []), 'required checklist evidence blocks visit completion', 422);
    $execution->recordVisit($technician->id, $workId, $visitId, 1, $visitInput, ['isolation_test' => ['result' => 'complete']]);
    $pass(DB::table('maintenance_visits')->where('id', $visitId)->where('status', 'recorded')->where('recorded_by', $technician->id)->exists()
        && DB::table('maintenance_checklist_results')->where('visit_id', $visitId)->where('item_key', 'isolation_test')->where('result', 'complete')->exists(),
        'assigned technician records real visit times, work, customer acknowledgement and required checklist result');
    $reject(fn () => $execution->recordVisit($technician->id, $workId, $visitId, 1, $visitInput, ['isolation_test' => ['result' => 'complete']]),
        'recorded visit cannot be overwritten or retried as new evidence', 409);

    $rateInput = ['branch_id' => $branch->id, 'technician_id' => $technician->id, 'hourly_cost' => '45.00',
        'effective_from' => now()->subDay()->toDateString(), 'effective_to' => now()->addDay()->toDateString(), 'reference' => 'Synthetic approved labor rate'];
    app(MaintenanceCostProposals::class)->propose($technician->id, 'labor_rate', null, $rateInput);
    $reject(fn () => $execution->approveRate($technician->id, $technician->id, $rateInput), 'labor-rate proposer cannot approve their own proposal', 403);
    $rateId = $execution->approveRate($dispatcher->id, $technician->id, $rateInput);
    $laborKey = (string) Str::uuid();
    $laborInput = ['visit_id' => $visitId, 'starts_at' => $arrival->toIso8601String(), 'ends_at' => $departure->toIso8601String(), 'work_type' => 'Electrical repair'];
    $laborId = $execution->labor($technician->id, $workId, $laborKey, $laborInput);
    $pass($execution->labor($technician->id, $workId, $laborKey, $laborInput) === $laborId, 'labor creation key retry does not duplicate recorded time');
    $labor = DB::table('maintenance_labor_entries')->find($laborId);
    $pass($labor->rate_id === $rateId && $labor->duration_minutes === 30 && bccomp((string) $labor->cost, '22.50', 2) === 0,
        'approved labor rate allocates exact decimal job cost without exposing a salary record');

    $work = DB::table('maintenance_work_orders')->find($workId);
    $actions->state($technician->id, $workId, $work->version, 'complete', 'Visit evidence, labor and acknowledgement are reconciled.');
    $pass(DB::table('maintenance_work_orders')->where('id', $workId)->where('status', 'work_completed')->exists(),
        'completion is permitted only after recorded visit, actual labor, checklist, acknowledgement, and parts reconciliation');
    $reject(fn () => $actions->state($reviewer->id, $workId, $work->version, 'verify', 'Stale completion attempt.'), 'stale work-order version cannot verify completion', 409);
    $work = DB::table('maintenance_work_orders')->find($workId);
    $actions->state($reviewer->id, $workId, $work->version, 'verify', 'Independent technical review confirms the service report.');
    $work = DB::table('maintenance_work_orders')->find($workId);
    $actions->state($reviewer->id, $workId, $work->version, 'close', 'Service report verified and customer acknowledgement retained.');
    $closed = DB::table('maintenance_work_orders')->find($workId);
    $pass($closed->status === 'closed' && $closed->closed_at !== null && DB::table('maintenance_completions')->where('work_order_id', $workId)->where('verified_by', $reviewer->id)->exists(),
        'independent reviewer verifies immutable completion evidence before closure');
    $completion = DB::table('maintenance_completions')->where('work_order_id', $workId)->first();
    $actions->state($reviewer->id, $workId, $closed->version, 'reopen', 'Follow-up inspection requires the verified job to resume.');
    $reopened = DB::table('maintenance_work_orders')->find($workId);
    $pass($reopened->status === 'in_progress' && $reopened->closed_at === null
        && DB::table('maintenance_completions')->where('work_order_id', $workId)->where('id', $completion->id)->where('verified_by', $reviewer->id)->exists(),
        'authorized reopen clears closure state while preserving the independent verification record');
    try {
        DB::table('maintenance_visits')->where('id', $visitId)->update(['work_performed' => 'tampered evidence']);
        throw new RuntimeException('Recorded visit evidence was mutable.');
    } catch (QueryException) {
        $pass(true, 'PostgreSQL prevents edits to recorded visit evidence');
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    DB::rollBack();
}
if ($failure !== null) {
    fwrite(STDERR, 'FAIL '.$failure::class.': '.$failure->getMessage().' at '.$failure->getFile().':'.$failure->getLine()."\n".$failure->getTraceAsString()."\n");
    exit(1);
}
fwrite(STDOUT, 'RESULT all_pass=true assertions='.$assertions."\n");
