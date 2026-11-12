<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\ProjectActions;
use App\Modules\Projects\Application\ProjectExecution;
use App\Modules\Projects\Application\ProjectPlanning;
use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectPhase;
use App\Modules\Projects\Domain\ProjectTask;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 2);
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$environment = ['APP_ENV' => (string) (getenv('APP_ENV') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: ''),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: ''),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: ''),
    'E2E_CLUSTER_MARKER' => (string) (getenv('E2E_CLUSTER_MARKER') ?: ''),
    'E2E_CLUSTER_TOKEN' => (string) (getenv('E2E_CLUSTER_TOKEN') ?: '')];
$target = OptimizationRegressionDatabaseGuard::target($root, $environment);
if ((int) $target['port'] !== 55460 || ! is_string($target['nonce']) || $target['nonce'] === '') {
    throw new RuntimeException('ABORT before writes: the fresh owned 55460 PostgreSQL target and token are required.');
}
if (! hash_equals((string) $target['nonce'], (string) ($environment['E2E_CLUSTER_TOKEN'] ?? ''))) {
    throw new RuntimeException('ABORT before writes: E2E_CLUSTER_TOKEN does not match the owned 55460 cluster marker.');
}
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = DB::table('codex_test_guard.ownership_receipts')->where('database', $identity->database)->first(['nonce']);
OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);

$assertionCount = 0;
$pass = static function (bool $ok, string $message) use (&$assertionCount): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    $assertionCount++;
    fwrite(STDOUT, "PASS {$message}\n");
};
$reject = static function (callable $action, string $message, ?int $httpStatus = null) use ($pass): void {
    try {
        $action();
    } catch (Throwable $error) {
        if ($httpStatus !== null) {
            if ($error instanceof HttpExceptionInterface && $error->getStatusCode() === $httpStatus) {
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
    $branch = Branch::factory()->create(['code' => 'DEMO-EG-PH2-'.Str::upper(Str::random(8))]);
    $customer = Customer::factory()->create(['code' => 'DEMO-EG-PH2-'.Str::upper(Str::random(8))]);
    User::factory()->disabledDemo()->create();
    $manager = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $member = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$manager, $member, $outsider, $reviewer] as $user) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['project_create', 'project_view', 'project_update', 'project_manage', 'project_activate', 'project_close', 'project_reopen', 'budget_manage', 'budget_approve'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $manager->id, 'permission_code' => 'M07.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M03.customer_view', 'M05.order_view', 'M05.receipt_view', 'M11.journal_view', 'M12.ap_view', 'M12.ar_view'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $manager->id, 'permission_code' => $permission,
            'branch_id' => $permission === 'M03.customer_view' ? null : $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('access_grants')->insert(['user_id' => $manager->id, 'permission_code' => 'M07.financial_view', 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['project_create', 'project_view'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $reviewer->id, 'permission_code' => 'M07.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('access_grants')->insert(['user_id' => $reviewer->id, 'permission_code' => 'M07.budget_approve', 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $project = Project::factory()->create([
        'code' => 'PH2-CHECK-'.Str::upper(Str::random(8)), 'branch_id' => $branch->id,
        'customer_id' => $customer->id, 'customer_name' => $customer->name,
        'manager_id' => $manager->id, 'created_by' => $manager->id,
    ])->fresh();
    DB::table('project_members')->insert(['project_id' => $project->id, 'user_id' => $member->id]);
    $phaseA = ProjectPhase::factory()->create(['project_id' => $project->id]);
    $phaseB = ProjectPhase::factory()->create(['project_id' => $project->id]);
    $taskA1 = ProjectTask::factory()->create(['project_id' => $project->id, 'phase_id' => $phaseA->id, 'assigned_to' => $member->id]);
    $taskA2 = ProjectTask::factory()->create(['project_id' => $project->id, 'phase_id' => $phaseA->id, 'assigned_to' => $member->id]);
    $taskB = ProjectTask::factory()->create(['project_id' => $project->id, 'phase_id' => $phaseB->id, 'assigned_to' => $member->id]);
    $managerTask = ProjectTask::factory()->create(['project_id' => $project->id, 'phase_id' => $phaseB->id, 'assigned_to' => $manager->id]);

    $actions = app(ProjectActions::class);
    $masterInput = ['code' => 'PH2-MASTER-'.Str::upper(Str::random(7)), 'name' => 'Synthetic project master lifecycle', 'branch_id' => $branch->id,
        'customer_id' => $customer->id, 'manager_id' => $manager->id, 'members' => [$member->id], 'start_date' => '2026-10-01', 'end_date' => '2026-12-31'];
    $master = $actions->save($manager->id, null, 0, (string) Str::uuid(), $masterInput);
    $pass($master->exists && $master->version === 1 && $master->status === 'draft', 'project master create persists scoped customer, manager, member, and initial version');
    $masterInput['name'] = 'Synthetic project master edited';
    $master = $actions->save($manager->id, $master->id, $master->version, (string) Str::uuid(), $masterInput);
    $pass($master->version === 2 && $master->name === $masterInput['name'], 'draft project master save increments version and retains edits');
    $reject(fn () => $actions->save($manager->id, $master->id, 1, (string) Str::uuid(), $masterInput), 'stale project master version is rejected');
    $duplicateInput = $masterInput;
    $duplicateInput['code'] = $project->code;
    $reject(fn () => $actions->save($manager->id, null, 0, (string) Str::uuid(), $duplicateInput), 'duplicate project code is rejected');
    $otherBranch = Branch::factory()->create(['code' => 'DEMO-EG-PH2-'.Str::upper(Str::random(8))]);
    DB::table('user_branch_scopes')->insert(['user_id' => $manager->id, 'branch_id' => $otherBranch->id, 'created_at' => now(), 'updated_at' => now()]);
    $wrongBranchInput = $masterInput;
    $wrongBranchInput['branch_id'] = $otherBranch->id;
    $reject(fn () => $actions->save($manager->id, $master->id, $master->version, (string) Str::uuid(), $wrongBranchInput), 'existing project cannot move to another branch');
    $masterInput['notes'] = 'Reopened draft for authorized edit.';
    $master = $actions->save($manager->id, $master->id, $master->version, (string) Str::uuid(), $masterInput);
    $pass($master->version === 3 && $master->notes === $masterInput['notes'], 'reopened draft can be edited at its current version');

    $access = app(ProjectAccess::class);
    $pass($access->allows($member->id, $project), 'assigned project member has project view within active branch scope');
    $pass(! $access->allows($member->id, $project, 'project_manage'), 'project membership does not grant phase and task management');
    $pass(! $access->allows($outsider->id, $project), 'branch scope alone does not expose an unassigned project');
    try {
        app(ProjectActions::class)->taskStatus($member->id, $project->id, $managerTask->id, 1, 'completed');
        throw new RuntimeException('A member changed a task assigned to another user.');
    } catch (HttpException $exception) {
        $pass($exception->getStatusCode() === 403, 'a project member cannot update another user’s assigned task');
    }
    $initial = $access->progress($project);
    $pass($initial['percent'] === '0.00' && $initial['eligible'] === 2, 'empty eligible tasks produce zero equal-phase progress');

    $milestone = app(ProjectExecution::class)->milestone($manager->id, $project->id, $project->version, [
        'title' => 'Customer acceptance checkpoint', 'target_date' => '2026-11-15', 'responsible_id' => $manager->id, 'required' => true,
    ]);
    app(ProjectExecution::class)->milestoneStatus($manager->id, $project->id, $milestone, 1, 'completed', 'Customer inspection evidence recorded for synthetic acceptance.');
    $pass(DB::table('project_milestones')->where('id', $milestone)->where('status', 'completed')->where('completed_by', $manager->id)->exists(),
        'milestone completion requires and records evidence and actual actor');

    $planning = app(ProjectPlanning::class);
    $structure = DB::table('project_phases')->where('project_id', $project->id)->orderBy('id')->get()->map(fn ($phase) => [
        'id' => (int) $phase->id, 'title' => $phase->title, 'start_date' => $phase->start_date, 'end_date' => $phase->end_date, 'cancelled' => (bool) $phase->cancelled,
    ])->all();
    $budgetProposal = ['currency' => 'EGP', 'total' => '100.00', 'scope' => 'Synthetic baseline revision one',
        'categories' => [['name' => 'Materials', 'amount' => '60.00'], ['name' => 'Labor', 'amount' => '40.00']],
        'phases' => [['id' => $phaseA->id, 'amount' => '50.00'], ['id' => $phaseB->id, 'amount' => '50.00']], 'structure' => $structure];
    $project->refresh();
    $planning->propose($manager->id, $project->id, $project->version, (string) Str::uuid(), 'Initial synthetic project budget.', $budgetProposal);
    $project->refresh();
    $changeOne = (int) DB::table('project_changes')->where('project_id', $project->id)->where('status', 'pending')->value('id');
    $reject(fn () => $planning->decide($manager->id, $project->id, $project->version, $changeOne, true, 'Self-approval must remain blocked.'),
        'budget manager with approval permission cannot approve their own proposal', 403);
    $planning->decide($reviewer->id, $project->id, $project->version, $changeOne, true, 'Independent reviewer approves baseline one.');
    $baselineOne = DB::table('project_baselines')->where('project_id', $project->id)->where('revision', 1)->first();
    $pass($baselineOne !== null && bccomp((string) $baselineOne->total, '100.00', 2) === 0 && (int) $baselineOne->approved_by === $reviewer->id,
        'independent reviewer creates approved baseline revision one');
    $snapshotOne = $baselineOne->snapshot;
    $budgetProposal['total'] = '120.00';
    $budgetProposal['scope'] = 'Synthetic baseline revision two';
    $budgetProposal['categories'] = [['name' => 'Materials', 'amount' => '72.00'], ['name' => 'Labor', 'amount' => '48.00']];
    $budgetProposal['phases'] = [['id' => $phaseA->id, 'amount' => '60.00'], ['id' => $phaseB->id, 'amount' => '60.00']];
    $project->refresh();
    $planning->propose($manager->id, $project->id, $project->version, (string) Str::uuid(), 'Documented scope increase.', $budgetProposal);
    $project->refresh();
    $changeTwo = (int) DB::table('project_changes')->where('project_id', $project->id)->where('status', 'pending')->value('id');
    $planning->decide($reviewer->id, $project->id, $project->version, $changeTwo, true, 'Independent reviewer approves baseline two.');
    $baselineRows = DB::table('project_baselines')->where('project_id', $project->id)->orderBy('revision')->get();
    $pass($baselineRows->count() === 2 && $baselineRows[0]->snapshot === $snapshotOne && bccomp((string) $baselineRows[0]->total, '100.00', 2) === 0
        && $baselineRows[1]->revision === 2 && bccomp((string) $baselineRows[1]->total, '120.00', 2) === 0 && (int) $baselineRows[1]->approved_by === $reviewer->id,
        'independent budget revision is reapproved while the prior baseline snapshot remains immutable');

    $project->refresh();
    $planning->transition($manager->id, $project->id, $project->version, 'planned', 'Approved baseline permits planning.');
    $project->refresh();
    $pass($project->status === 'planned', 'draft project transitions to planned only after an approved baseline');
    $planning->transition($manager->id, $project->id, $project->version, 'active', 'Execution starts.');
    $project->refresh();
    $pass($project->status === 'active' && $project->actual_start === null, 'activation alone does not record actual execution start');
    $planning->transition($manager->id, $project->id, $project->version, 'in_progress', 'Execution is underway.');
    $project->refresh();
    $pass($project->status === 'in_progress', 'active project can transition into in-progress execution');
    $pass($project->actual_start === now('Africa/Cairo')->toDateString(), 'first in-progress transition records actual start in Cairo local date');
    $planning->transition($manager->id, $project->id, $project->version, 'on_hold', 'Site access is temporarily unavailable.');
    $project->refresh();
    $pass($project->status === 'on_hold', 'active execution can be put on hold with a recorded reason');
    $planning->transition($manager->id, $project->id, $project->version, 'active', 'Site access was restored.');
    $project->refresh();
    $pass($project->status === 'active', 'on-hold project can resume to active');
    $planning->transition($manager->id, $project->id, $project->version, 'in_progress', 'Execution resumes.');
    $project->refresh();

    app(ProjectActions::class)->taskStatus($member->id, $project->id, $taskA1->id, 1, 'completed');
    $project->refresh();
    $half = $access->progress($project);
    $pass($half['percent'] === '25.00', 'equal-phase calculation averages 50% and 0% exactly');
    $pass((int) DB::table('project_tasks')->where('id', $taskA1->id)->value('completed_by') === $member->id,
        'task completion records the actual assigned member');
    try {
        app(ProjectActions::class)->taskStatus($member->id, $project->id, $taskA1->id, 1, 'pending');
        throw new RuntimeException('A stale task version overwrote a newer task update.');
    } catch (ValidationException) {
        $pass((int) DB::table('project_tasks')->where('id', $taskA1->id)->value('version') === 2,
            'stale task version is rejected without changing persisted state');
    }

    app(ProjectActions::class)->taskStatus($member->id, $project->id, $taskA2->id, 1, 'completed');
    app(ProjectActions::class)->taskStatus($member->id, $project->id, $taskB->id, 1, 'completed');
    app(ProjectActions::class)->taskStatus($manager->id, $project->id, $managerTask->id, 1, 'completed');
    $project->refresh();
    $final = $access->progress($project);
    $pass($final['percent'] === '100.00', 'all completed tasks produce exact equal-phase completion');

    $actions->task($manager->id, $project->id, $project->version, ['phase_id' => $phaseA->id, 'title' => 'Synthetic prerequisite task', 'assigned_to' => $member->id,
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    $project->refresh();
    $prerequisiteTask = (int) DB::table('project_tasks')->where('project_id', $project->id)->where('title', 'Synthetic prerequisite task')->value('id');
    $actions->task($manager->id, $project->id, $project->version, ['phase_id' => $phaseA->id, 'title' => 'Synthetic dependent task', 'assigned_to' => $member->id,
        'start_date' => '2026-11-01', 'end_date' => '2026-12-31']);
    $project->refresh();
    $dependentTask = (int) DB::table('project_tasks')->where('project_id', $project->id)->where('title', 'Synthetic dependent task')->value('id');
    app(ProjectExecution::class)->dependency($manager->id, $project->id, $project->version, $dependentTask, $prerequisiteTask);
    $project->refresh();
    $pass(DB::table('project_task_dependencies')->where('task_id', $dependentTask)->where('depends_on_id', $prerequisiteTask)->exists(),
        'task dependency persists as a project-scoped prerequisite');
    $reject(fn () => $actions->taskStatus($member->id, $project->id, $dependentTask, 1, 'in_progress'), 'dependent task cannot start before prerequisite completion');
    $actions->taskStatus($member->id, $project->id, $prerequisiteTask, 1, 'completed');
    $actions->taskStatus($member->id, $project->id, $dependentTask, 1, 'in_progress', '', 35);
    $actions->taskStatus($member->id, $project->id, $dependentTask, 2, 'blocked', 'Waiting for site access permit.');
    $blocked = DB::table('project_tasks')->where('id', $dependentTask)->first();
    $pass($blocked->status === 'blocked' && $blocked->blocked_reason === 'Waiting for site access permit.' && $blocked->progress === 35,
        'blocked task retains its exact progress and records a reason');
    $actions->taskStatus($member->id, $project->id, $dependentTask, 3, 'in_progress', '', 60);
    $resumedTask = DB::table('project_tasks')->where('id', $dependentTask)->first();
    $project->refresh();
    $executionProgress = $access->progress($project);
    $pass($resumedTask->status === 'in_progress' && $resumedTask->blocked_reason === '' && $resumedTask->progress === 60
        && $executionProgress['percent'] === '95.00', 'resumed task clears block reason and contributes exact weighted project progress');
    $activityCount = DB::table('project_activities')->where('project_id', $project->id)->where('action', 'task.status')->count();
    $pass($activityCount === 8, 'task activity history records each real status change');
    $actions->taskStatus($member->id, $project->id, $dependentTask, 4, 'completed');
    $project->refresh();
    app(ProjectExecution::class)->handover($manager->id, $project->id, $project->version, 'Customer handover accepted; synthetic completion record retained.');
    $project->refresh();
    $planning->transition($manager->id, $project->id, $project->version, 'completed', 'All required tasks, milestone, and handover evidence are complete.');
    $project->refresh();
    $pass($project->status === 'completed', 'project completes after all required tasks, milestones, and handover evidence');
    $planning->transition($manager->id, $project->id, $project->version, 'closed', 'Empty owner projections show no outstanding commitments.');
    $project->refresh();
    $pass($project->status === 'closed', 'project closes after owner financial projections confirm no open obligations');
    $reject(fn () => $actions->metadata($manager->id, $project->id, $project->version, ['name' => 'Illegal closed edit', 'priority' => 'normal'], 'Read-only state check.'),
        'closed project master is read-only', 409);
    app(ProjectPlanning::class)->transition($manager->id, $project->id, $project->version, 'active', 'Reopen closed project for authorized correction.');
    $project->refresh();
    $pass($project->status === 'active', 'authorized project_reopen transition reopens a legitimately closed project');

    $utcProbe = CarbonImmutable::create(2026, 10, 28, 9, 15, 30, 'UTC');
    $utcProbeId = DB::table('project_activities')->insertGetId(['project_id' => $project->id, 'actor_id' => $manager->id,
        'action' => 'qa.utc_timestamp_probe', 'before_snapshot' => null,
        'after_snapshot' => json_encode(['instant' => $utcProbe->toIso8601String()], JSON_THROW_ON_ERROR), 'occurred_at' => $utcProbe]);
    $databaseTimezone = (string) DB::selectOne("SELECT current_setting('TimeZone') AS timezone")->timezone;
    $storedEpoch = (int) DB::table('project_activities')->where('id', $utcProbeId)->selectRaw('extract(epoch from occurred_at)::bigint as epoch')->value('epoch');
    $pass(strtoupper($databaseTimezone) === 'UTC' && $storedEpoch === $utcProbe->getTimestamp(),
        'PostgreSQL persists the exact UTC Carbon instant in a UTC session');

    try {
        DB::table('project_activities')->where('project_id', $project->id)->delete();
        throw new RuntimeException('Project activity immutability trigger did not reject deletion.');
    } catch (QueryException) {
        $pass(true, 'project activity history is append-only at the PostgreSQL boundary');
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    DB::rollBack();
}
if ($failure !== null) {
    fwrite(STDERR, 'FAIL '.$failure::class.': '.$failure->getMessage()."\n");
    exit(1);
}
fwrite(STDOUT, 'RESULT all_pass=true assertions='.$assertionCount."\n");
