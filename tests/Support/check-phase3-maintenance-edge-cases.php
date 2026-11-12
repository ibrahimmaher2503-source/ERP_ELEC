<?php

// Bounded M08 lifecycle/checklist/cost edge acceptance. All fixture writes roll back.
use App\Models\User;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceBilling;
use App\Modules\FieldMaintenance\Application\MaintenanceCostProposals;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
$port = getenv('PHASE3_EDGE_PORT') ?: '55443';
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT='.$port);
putenv('DB_DATABASE=erp_elec_new_test');
putenv('DB_USERNAME=postgres');
putenv('DB_PASSWORD=');
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = $port;
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
$_ENV['DB_USERNAME'] = $_SERVER['DB_USERNAME'] = 'postgres';
$_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = '';
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => (int) $port, 'database.connections.pgsql.database' => 'erp_elec_new_test', 'database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => '', 'database.connections.pgsql.url' => null]);
DB::purge('pgsql');
$conn = DB::selectOne('select current_database() db,inet_server_addr()::text address,inet_server_port() port');
if (DB::connection()->getDriverName() !== 'pgsql' || $conn->db !== 'erp_elec_new_test' || ! in_array($conn->address, ['127.0.0.1', '127.0.0.1/32'], true) || (int) $conn->port !== (int) $port || $port !== '55443') {
    throw new RuntimeException('ABORT before writes: require isolated loopback erp_elec_new_test PostgreSQL port 55443.');
}
$passed = [];
$pass = static function (bool $ok, string $msg) use (&$passed): void {
    if (! $ok) {
        throw new RuntimeException($msg);
    }$passed[] = $msg;
    fwrite(STDOUT, "PASS {$msg}\n");
};
$reject = static function (callable $fn, string $msg, int $status) use ($pass): void {
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === $status) {
            $pass(true, $msg);

            return;
        }throw $e;
    }throw new RuntimeException('Expected denial: '.$msg);
};
$grant = static function (User $u, array $codes, ?int $branch): void {
    foreach ($codes as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $u->id, 'permission_code' => 'M08.'.$code, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$uuid = static fn () => (string) Str::uuid();
DB::beginTransaction();
$failure = null;
try {
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $tech = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $otherTech = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $conflictTech = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create();
    }
    $branch = Branch::factory()->create(['company_id' => 1, 'code' => 'M08EDGE-'.strtoupper(Str::random(7))]);
    $customer = Customer::factory()->create(['status' => 'active']);
    foreach ([$maker, $tech, $otherTech, $conflictTech] as $u) {
        DB::table('user_branch_scopes')->insert(['user_id' => $u->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant($maker, ['view', 'standalone_create', 'workorder_manage', 'assign', 'schedule_override', 'checklist_manage', 'visit_record', 'labor_record', 'rate_approve', 'verify', 'close', 'reopen', 'cost_view'], (int) $branch->id);
    $grant($tech, ['view', 'workorder_manage', 'visit_record', 'labor_record'], (int) $branch->id);
    $grant($otherTech, ['view', 'workorder_manage', 'visit_record', 'labor_record'], (int) $branch->id);
    $grant($conflictTech, ['view', 'workorder_manage', 'visit_record', 'labor_record'], (int) $branch->id);
    foreach (['M01.branch_view', 'M03.customer_view'] as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $maker->id, 'permission_code' => $code, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $actions = app(MaintenanceActions::class);
    $exec = app(MaintenanceExecution::class);
    $billing = app(MaintenanceBilling::class);
    $makeWork = static fn (User $responsible, string $description) => $actions->createWorkOrder((int) $maker->id, null, 0, $uuid(), ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $responsible->id, 'description' => $description, 'priority' => 'normal', 'billable' => false]);
    $start = CarbonImmutable::now('UTC')->startOfMinute()->subHours(2);
    $end = $start->addHours(5);
    $templateItems = [['key' => 'continuity', 'label' => 'Continuity test complete', 'required' => true]];
    $template = $exec->saveTemplate((int) $maker->id, null, 0, ['branch_id' => $branch->id, 'code' => 'M08EDGE-'.strtoupper(Str::random(5)), 'title' => 'Edge checklist frozen snapshot', 'active' => true, 'items' => $templateItems]);
    $work = $makeWork($tech, 'Canceled visit requires same-snapshot completed replacement');
    $assignment = $exec->assign((int) $maker->id, $work, 1, ['technician_id' => $tech->id, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()]);
    $w = DB::table('maintenance_work_orders')->find($work);
    $cancelVisit = $exec->schedule((int) $maker->id, $work, $w->version, $uuid(), ['assignment_id' => $assignment, 'scheduled_at' => $start->addMinutes(10)->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
    $w = DB::table('maintenance_work_orders')->find($work);
    $replacement = $exec->schedule((int) $maker->id, $work, $w->version, $uuid(), ['assignment_id' => $assignment, 'scheduled_at' => $start->addMinutes(20)->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
    $frozen = json_decode(DB::table('maintenance_visits')->where('id', $cancelVisit)->value('template_snapshot'), true);
    $exec->saveTemplate((int) $maker->id, $template, 1, ['branch_id' => $branch->id, 'code' => DB::table('maintenance_checklist_templates')->where('id', $template)->value('code'), 'title' => 'Updated template after scheduling', 'active' => true, 'items' => [['key' => 'continuity', 'label' => 'Updated continuity protocol', 'required' => true], ['key' => 'earth', 'label' => 'Earth test', 'required' => false]]]);
    $pass(json_decode(DB::table('maintenance_visits')->whereIn('id', [$cancelVisit, $replacement])->orderBy('id')->value('template_snapshot'), true) === $frozen && $frozen === $templateItems, 'scheduled visit checklist snapshots stay frozen when the source template changes');
    $exec->recordVisit((int) $tech->id, $work, $cancelVisit, 1, ['outcome' => 'cancelled', 'cancellation_reason' => 'Technician could not access site'], []);
    $cancelRow = DB::table('maintenance_visits')->find($cancelVisit);
    $pass($cancelRow->outcome === 'cancelled' && $cancelRow->cancellation_reason === 'Technician could not access site' && $cancelRow->arrival_at === null && $cancelRow->departure_at === null && ! DB::table('maintenance_labor_entries')->where('visit_id', $cancelVisit)->exists(), 'planned visit cancellation needs only a reason and records no actual time or labor');
    $actions->state((int) $tech->id, $work, (int) DB::table('maintenance_work_orders')->find($work)->version, 'start', 'Start only after canceled visit is recorded.');
    $reject(fn () => $actions->state((int) $tech->id, $work, (int) DB::table('maintenance_work_orders')->find($work)->version, 'complete', 'Cannot complete before replacement visit.'), 'canceled mandatory visit blocks completion until a same-snapshot completed replacement', 409);
    $arrival = $start->addMinutes(30);
    $departure = $arrival->addMinutes(30);
    usleep(1100000); // The replacement must be recorded strictly after cancellation at database timestamp precision.
    $exec->recordVisit((int) $tech->id, $work, $replacement, 1, ['arrival_at' => $arrival->toIso8601String(), 'departure_at' => $departure->toIso8601String(), 'work_performed' => 'Completed replacement inspection', 'outcome' => 'completed', 'acknowledgment' => 'Site contact confirms'], ['continuity' => ['result' => 'complete']]);
    $exec->labor((int) $tech->id, $work, $uuid(), ['visit_id' => $replacement, 'starts_at' => $arrival->toIso8601String(), 'ends_at' => $departure->toIso8601String(), 'work_type' => 'Replacement inspection']);
    $pass(json_decode(DB::table('maintenance_visits')->where('id', $replacement)->value('template_snapshot'), true) === $frozen, 'replacement visit retained the identical frozen inspection snapshot');
    $actions->state((int) $tech->id, $work, (int) DB::table('maintenance_work_orders')->find($work)->version, 'complete', 'Replacement inspection resolves the canceled mandatory check.');
    $pass(DB::table('maintenance_work_orders')->where('id', $work)->where('status', 'work_completed')->exists(), 'same-snapshot completed replacement satisfies cancellation completion guard');

    $cancelWork = $makeWork($otherTech, 'Cancel before any actual execution');
    $cancelAssign = $exec->assign((int) $maker->id, $cancelWork, 1, ['technician_id' => $otherTech->id, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()]);
    $cw = DB::table('maintenance_work_orders')->find($cancelWork);
    $planned = $exec->schedule((int) $maker->id, $cancelWork, $cw->version, $uuid(), ['assignment_id' => $cancelAssign, 'scheduled_at' => $start->addMinutes(5)->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
    $actions->state((int) $maker->id, $cancelWork, (int) DB::table('maintenance_work_orders')->find($cancelWork)->version, 'cancel', 'Customer canceled before field execution.');
    $actions->state((int) $maker->id, $cancelWork, (int) DB::table('maintenance_work_orders')->find($cancelWork)->version, 'reopen', 'Customer rescheduled and authorized reopening.');
    $plannedRow = DB::table('maintenance_visits')->find($planned);
    $pass(DB::table('maintenance_work_orders')->where('id', $cancelWork)->where('status', 'draft')->exists() && $plannedRow->status === 'recorded' && $plannedRow->outcome === 'cancelled' && $plannedRow->cancellation_reason === 'Customer canceled before field execution.' && $plannedRow->arrival_at === null && $plannedRow->departure_at === null && DB::table('maintenance_activities')->where('entity_type', 'maintenance_work_order')->where('entity_id', $cancelWork)->whereIn('action', ['workorder.cancel', 'workorder.reopen'])->count() === 2, 'cancel-before-execution records planned-visit cancellations and authorized reopen returns to draft with history preserved');

    $conflictWork = $makeWork($conflictTech, 'Scheduling override contract');
    $conflictAssign = $exec->assign((int) $maker->id, $conflictWork, 1, ['technician_id' => $conflictTech->id, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()]);
    $otherWork = $makeWork($otherTech, 'Conflict request without override authority');
    $reject(fn () => $exec->assign((int) $outsider->id, $otherWork, 1, ['technician_id' => $conflictTech->id, 'starts_at' => $start->addHour()->toIso8601String(), 'ends_at' => $end->addHour()->toIso8601String()]), 'unscoped user cannot schedule a conflicting technician', 403);
    $reject(fn () => $exec->assign((int) $maker->id, $otherWork, 1, ['technician_id' => $conflictTech->id, 'starts_at' => $start->addHour()->toIso8601String(), 'ends_at' => $end->addHour()->toIso8601String()]), 'conflict override requires a recorded reason', 422);
    $override = $exec->assign((int) $maker->id, $otherWork, 1, ['technician_id' => $conflictTech->id, 'starts_at' => $start->addHour()->toIso8601String(), 'ends_at' => $end->addHour()->toIso8601String(), 'override_reason' => 'Urgent same-day outage response']);
    $pass(DB::table('maintenance_assignments')->where('id', $override)->where('override_reason', 'Urgent same-day outage response')->exists(), 'authorized scheduling override records its conflict reason');

    $mixed = $makeWork($tech, 'Mixed rated and unrated actual labor cost');
    $rateDate = now('Africa/Cairo')->subDay()->toDateString();
    $rateProposal = ['branch_id' => $branch->id, 'technician_id' => $tech->id, 'hourly_cost' => '45.00', 'effective_from' => $rateDate, 'effective_to' => $rateDate, 'reference' => 'Synthetic approved edge-case labor rate'];
    app(MaintenanceCostProposals::class)->propose((int) $tech->id, 'labor_rate', null, $rateProposal);
    $rate = app(MaintenanceExecution::class)->approveRate((int) $maker->id, (int) $tech->id, $rateProposal);
    $mixA = $start->subDays(1);
    $mixB = $mixA->addHours(4);
    $ratedAssignment = $exec->assign((int) $maker->id, $mixed, 1, ['technician_id' => $tech->id, 'starts_at' => $mixA->toIso8601String(), 'ends_at' => $mixB->toIso8601String()]);
    $unratedAssignment = $exec->assign((int) $maker->id, $mixed, 2, ['technician_id' => $otherTech->id, 'starts_at' => $mixA->toIso8601String(), 'ends_at' => $mixB->toIso8601String()]);
    $mw = DB::table('maintenance_work_orders')->find($mixed);
    $ratedVisit = $exec->schedule((int) $maker->id, $mixed, $mw->version, $uuid(), ['assignment_id' => $ratedAssignment, 'scheduled_at' => $mixA->addMinutes(5)->toIso8601String(), 'template_id' => $template, 'mandatory' => false]);
    $mw = DB::table('maintenance_work_orders')->find($mixed);
    $unratedVisit = $exec->schedule((int) $maker->id, $mixed, $mw->version, $uuid(), ['assignment_id' => $unratedAssignment, 'scheduled_at' => $mixA->addMinutes(10)->toIso8601String(), 'template_id' => $template, 'mandatory' => false]);
    $exec->recordVisit((int) $tech->id, $mixed, $ratedVisit, 1, ['arrival_at' => $mixA->addMinutes(20)->toIso8601String(), 'departure_at' => $mixA->addMinutes(50)->toIso8601String(), 'work_performed' => 'Rated repair time', 'outcome' => 'completed'], ['continuity' => ['result' => 'complete']]);
    $exec->labor((int) $tech->id, $mixed, $uuid(), ['visit_id' => $ratedVisit, 'starts_at' => $mixA->addMinutes(20)->toIso8601String(), 'ends_at' => $mixA->addMinutes(50)->toIso8601String(), 'work_type' => 'Rated repair']);
    $exec->recordVisit((int) $otherTech->id, $mixed, $unratedVisit, 1, ['arrival_at' => $mixA->addMinutes(60)->toIso8601String(), 'departure_at' => $mixA->addMinutes(90)->toIso8601String(), 'work_performed' => 'Unrated repair time', 'outcome' => 'completed'], ['continuity' => ['result' => 'complete']]);
    $exec->labor((int) $otherTech->id, $mixed, $uuid(), ['visit_id' => $unratedVisit, 'starts_at' => $mixA->addMinutes(60)->toIso8601String(), 'ends_at' => $mixA->addMinutes(90)->toIso8601String(), 'work_type' => 'Unrated repair']);
    $cost = $billing->costs((int) $maker->id, $mixed);
    $pass($cost['labor'] === null && $cost['complete'] === false && $cost['supported_labor'] === '22.50' && $cost['supported_total'] === '22.50', 'mixed rated/unrated labor stays incomplete while preserving known supported labor and total');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $evidence = ['all_pass' => true, 'database' => $conn->db, 'postgres_port' => (int) $conn->port, 'rollback_only' => true, 'assertions' => $passed, 'edge_fixture_ids' => ['branch' => (int) $branch->id, 'work_order' => (int) $work, 'cancel_before_execution_work_order' => (int) $cancelWork, 'mixed_cost_work_order' => (int) $mixed, 'labor_rate' => (int) $rate]];
} catch (Throwable $e) {
    $failure = $e;
    $evidence = ['all_pass' => false, 'database' => $conn->db, 'postgres_port' => (int) $conn->port, 'rollback_only' => true, 'assertions' => $passed, 'error_class' => $e::class, 'error' => $e->getMessage()];
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }$path = $root.'/docs/qa/evidence/phase3-maintenance-edge-cases.json';
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0770, true);
    }file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
}
if ($failure) {
    fwrite(STDERR, 'FAIL '.$failure::class.': '.$failure->getMessage().' at '.$failure->getFile().':'.$failure->getLine()."\n".$failure->getTraceAsString()."\n");
    exit(1);
}
fwrite(STDOUT, 'RESULT all_pass=true assertions='.count($passed).' database='.$conn->db.' port='.$conn->port.' fixture_rolled_back=true'."\n");
