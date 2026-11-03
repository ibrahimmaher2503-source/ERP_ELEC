<?php

use App\Modules\Projects\Application\ProjectActions;
use App\Modules\Projects\Application\ProjectDocuments;
use App\Modules\Projects\Application\ProjectExecution;
use App\Modules\Projects\Application\ProjectMaterials;
use App\Modules\Projects\Application\ProjectPlanning;
use App\Modules\Projects\Application\ProjectSources;
use App\Modules\Projects\Domain\Project;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = $root.'/.tmp/phase2-concurrency-storage';
foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir($storage.'/'.$dir) && ! mkdir($storage.'/'.$dir, 0700, true) && ! is_dir($storage.'/'.$dir)) {
        throw new RuntimeException('Cannot create isolated M07 worker storage.');
    }
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, json_encode(['uncaught' => $error::class, 'message' => $error->getMessage(), 'trace' => array_slice($error->getTrace(), 0, 8)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    exit(1);
});

$connection = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'erp_elec_new_test' || config('database.connections.pgsql.database') !== 'erp_elec_new_test'
    || config('database.connections.pgsql.host') !== '127.0.0.1' || (string) config('database.connections.pgsql.port') !== '55437'
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true) || $connection->db !== 'erp_elec_new_test'
    || ! in_array($connection->address, ['127.0.0.1', '127.0.0.1/32'], true) || (string) $connection->port !== '55437') {
    throw new RuntimeException('ABORT before writes: M07 concurrency requires the isolated erp_elec_new_test PostgreSQL cluster on 127.0.0.1:55437.');
}

$fixturePath = $root.'/docs/qa/evidence/phase2-projects-financial.json';
$fixtureEvidence = is_file($fixturePath) ? json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR) : [];
$fixture = $fixtureEvidence['project_fixture'] ?? [];
if (($fixtureEvidence['all_pass'] ?? false) !== true || ($fixture['committed'] ?? false) !== true
    || (int) ($fixtureEvidence['postgres_port'] ?? 0) !== 55437 || ! isset($fixture['project_id'], $fixture['manager_id'], $fixture['reviewer_id'], $fixture['branch_id'], $fixture['customer_id'], $fixture['product_id'], $fixture['warehouse_id'], $fixture['purchase_order_ids'][0])) {
    // A later QA run may replace the canonical evidence file with its 5443 result. Reuse only a persisted,
    // active, action-created M07 worker project from this isolated cluster in that case.
    $candidate = DB::table('projects')->where('status', 'active')->where('code', 'like', 'PH2-RACE-MAT-%')->orderByDesc('id')->first();
    if (! $candidate) {
        throw new RuntimeException('No committed Phase 2 fixture evidence for port 55437 and no active synthetic M07 worker project fallback.');
    }
    $productId = (int) DB::table('project_requirements')->where('project_id', $candidate->id)->orderBy('id')->value('product_id');
    $warehouseId = (int) DB::table('user_warehouse_scopes')->where('user_id', $candidate->manager_id)->where('branch_id', $candidate->branch_id)->value('warehouse_id');
    $purchaseOrders = DB::table('project_source_links')->where('project_id', $candidate->id)->where('source_type', 'purchase_order')->orderBy('id')->pluck('source_id')->map(fn ($id) => (int) $id)->all();
    $reviewerId = (int) DB::table('rp_credit_notes')->where('branch_id', $candidate->branch_id)->whereNotNull('approved_by')->orderByDesc('id')->value('approved_by');
    if (! $productId || ! $warehouseId || $purchaseOrders === [] || ! $reviewerId) {
        throw new RuntimeException('Persisted synthetic worker project is missing its actual product, warehouse, purchase, or reviewer relationships.');
    }
    $fixture = ['project_id' => (int) $candidate->id, 'manager_id' => (int) $candidate->manager_id, 'reviewer_id' => $reviewerId,
        'branch_id' => (int) $candidate->branch_id, 'customer_id' => (int) $candidate->customer_id, 'product_id' => $productId,
        'warehouse_id' => $warehouseId, 'purchase_order_ids' => $purchaseOrders];
}

$need = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$makerId = (int) $fixture['manager_id'];
$reviewerId = (int) $fixture['reviewer_id'];
$branchId = (int) $fixture['branch_id'];
$customerId = (int) $fixture['customer_id'];
$productId = (int) $fixture['product_id'];
$warehouseId = (int) $fixture['warehouse_id'];
$date = now('Africa/Cairo')->toDateString();
$end = date('Y-m-d', strtotime($date.' + 30 days'));
$now = now();
DB::table('access_grants')->insertOrIgnore(['user_id' => $reviewerId, 'permission_code' => 'M07.financial_view', 'branch_id' => $branchId,
    'created_at' => $now, 'updated_at' => $now]);
$actions = app(ProjectActions::class);
$planning = app(ProjectPlanning::class);

$race = static function (array $tasks) use ($root): array {
    $tmp = sys_get_temp_dir().'/phase2-project-race-'.getmypid().'-'.bin2hex(random_bytes(5));
    if (! mkdir($tmp, 0700, true)) {
        throw new RuntimeException('Cannot create isolated M07 worker barrier.');
    }
    $gate = $tmp.'/go';
    $running = [];
    foreach ($tasks as $i => $task) {
        $ready = $tmp.'/ready-'.$i;
        $process = new Process([PHP_BINARY, $root.'/tests/Support/phase2-project-concurrency-worker.php', (string) $task['operation'],
            json_encode($task['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $gate, $ready], $root, null, null, 90);
        $process->start();
        $running[] = [$process, $ready];
    }
    $deadline = microtime(true) + 45;
    foreach ($running as [, $ready]) {
        while (! is_file($ready) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($ready)) {
            foreach ($running as [$process]) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            throw new RuntimeException('Two independent PostgreSQL PHP workers failed to reach the common barrier.');
        }
    }
    $readyRows = array_map(fn ($path) => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), array_column($running, 1));
    if (count(array_unique(array_column($readyRows, 'pg_backend_pid'))) !== count($tasks)) {
        throw new RuntimeException('Workers did not hold independent PostgreSQL backend connections.');
    }
    file_put_contents($gate, 'go', LOCK_EX);
    $results = [];
    foreach ($running as [$process]) {
        $process->wait();
        $json = trim($process->getOutput());
        $results[] = ['exit_code' => $process->getExitCode(), 'result' => $json === '' ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            'stderr' => trim($process->getErrorOutput())];
    }
    foreach (glob($tmp.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);

    return ['ready' => $readyRows, 'workers' => $results];
};

$approvedRace = [];
$materialRace = [];
$linkRace = [];
$closeRace = [];
$failureEvidence = [];

// A second, action-created project reuses real stock from the committed Purchasing receipt fixture.
$reserveProject = $actions->save($makerId, null, 0, (string) Str::uuid(), [
    'code' => 'PH2-RACE-MAT-'.strtoupper(Str::random(6)), 'name' => 'Synthetic concurrent material demand', 'branch_id' => $branchId,
    'customer_id' => $customerId, 'manager_id' => $makerId, 'members' => [], 'start_date' => $date, 'end_date' => $end,
    'description' => 'Disposable independent-worker demand-cap fixture', 'notes' => '',
]);
$actions->phase($makerId, $reserveProject->id, $reserveProject->version, ['title' => 'Reserved material scope', 'start_date' => $date, 'end_date' => $end]);
$reserveProject->refresh();
$phase = DB::table('project_phases')->where('project_id', $reserveProject->id)->firstOrFail();
$planning->propose($makerId, $reserveProject->id, $reserveProject->version, (string) Str::uuid(), 'Synthetic initial baseline for concurrent material acceptance.', [
    'scope' => 'Synthetic concurrent material demand', 'currency' => 'EGP', 'total' => '100.00',
    'categories' => [['name' => 'Materials', 'amount' => '100.00']], 'phases' => [],
    'structure' => [['id' => (int) $phase->id, 'title' => $phase->title, 'start_date' => $phase->start_date, 'end_date' => $phase->end_date, 'cancelled' => false]],
]);
$reserveProject->refresh();
$budgetChangeId = (int) DB::table('project_changes')->where('project_id', $reserveProject->id)->where('status', 'pending')->value('id');
$approvedRace = $race([
    ['operation' => 'approve_budget', 'payload' => ['actor_id' => $reviewerId, 'project_id' => (int) $reserveProject->id, 'version' => $reserveProject->version, 'change_id' => $budgetChangeId]],
    ['operation' => 'approve_budget', 'payload' => ['actor_id' => $reviewerId, 'project_id' => (int) $reserveProject->id, 'version' => $reserveProject->version, 'change_id' => $budgetChangeId]],
]);
$approvedBaselines = DB::table('project_baselines')->where('project_id', $reserveProject->id)->count();
$approvedChange = DB::table('project_changes')->where('id', $budgetChangeId)->value('status');
$need(count(array_filter($approvedRace['workers'], fn ($w) => ($w['result']['outcome'] ?? null) === 'passed')) === 1
    && count(array_filter($approvedRace['workers'], fn ($w) => ($w['result']['outcome'] ?? null) === 'rejected')) === 1
    && $approvedBaselines === 1 && $approvedChange === 'approved', 'Concurrent project budget approval did not produce exactly one winner and one committed baseline.');
$planning->transition($makerId, $reserveProject->id, (int) Project::findOrFail($reserveProject->id)->version, 'planned', 'Approved synthetic material test baseline.');
$planning->transition($makerId, $reserveProject->id, (int) Project::findOrFail($reserveProject->id)->version, 'active', 'Begin synthetic material acceptance work.');
app(ProjectSources::class)->requirement($makerId, $reserveProject->id, ['product_id' => $productId, 'quantity' => '1.00000000', 'notes' => 'Exact one-unit synthetic demand for concurrent cap check.']);
$requirementId = (int) DB::table('project_requirements')->where('project_id', $reserveProject->id)->where('product_id', $productId)->value('id');
$materialRace = $race([
    ['operation' => 'reserve_material', 'payload' => ['actor_id' => $makerId, 'project_id' => (int) $reserveProject->id, 'requirement_id' => $requirementId,
        'warehouse_id' => $warehouseId, 'quantity' => '1.00000000', 'key' => (string) Str::uuid()]],
    ['operation' => 'reserve_material', 'payload' => ['actor_id' => $makerId, 'project_id' => (int) $reserveProject->id, 'requirement_id' => $requirementId,
        'warehouse_id' => $warehouseId, 'quantity' => '1.00000000', 'key' => (string) Str::uuid()]],
]);
$materialSuccess = count(array_filter($materialRace['workers'], fn ($w) => ($w['result']['outcome'] ?? null) === 'passed'));
$materialFailures = count(array_filter($materialRace['workers'], fn ($w) => ($w['result']['outcome'] ?? null) === 'rejected'));
$materialReservations = DB::table('project_material_reservations')->where('project_id', $reserveProject->id)->where('requirement_id', $requirementId)->count();
$reservationQty = (string) DB::table('inventory_reservations')->where('source_module', 'projects')->where('source_id', (string) $reserveProject->id)
    ->where('source_line', (string) $requirementId)->selectRaw('coalesce(sum(reserved-fulfilled-released),0)::text as quantity')->value('quantity');
$need($materialSuccess === 1 && $materialFailures === 1 && $materialReservations === 1 && bccomp($reservationQty, '1.00000000', 8) === 0,
    'Concurrent M07 reservations exceeded one unit of demand or failed to serialize the competing request.');

// Two workers race to link the same real source to a project where that link does not exist yet.
$sourceProjectId = (int) $reserveProject->id;
$orderId = (int) DB::table('purchasing_orders as o')->where('o.branch_id', $branchId)
    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('project_source_links as l')->where('l.source_type', 'purchase_order')->whereColumn('l.source_id', 'o.id'))
    ->orderByDesc('o.id')->value('o.id');
$need($orderId > 0, 'No real unlinked synthetic same-branch purchase order is available for the source-link race.');
$linkRace = $race([
    ['operation' => 'link_source', 'payload' => ['actor_id' => $makerId, 'project_id' => $sourceProjectId, 'source_type' => 'purchase_order', 'source_id' => $orderId]],
    ['operation' => 'link_source', 'payload' => ['actor_id' => $makerId, 'project_id' => $sourceProjectId, 'source_type' => 'purchase_order', 'source_id' => $orderId]],
]);
$linkCount = DB::table('project_source_links')->where('project_id', $sourceProjectId)->where('source_type', 'purchase_order')->where('source_id', $orderId)->count();
$need(count(array_filter($linkRace['workers'], fn ($w) => ($w['result']['outcome'] ?? null) === 'passed')) === 2 && $linkCount === 1,
    'Concurrent replay of one Project source link created duplicates or rejected a valid replay: '.json_encode(['count' => $linkCount, 'workers' => $linkRace], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// A clean synthetic project can reach completion and close through its public owner Actions.
$closeProject = $actions->save($makerId, null, 0, (string) Str::uuid(), [
    'code' => 'PH2-RACE-CLOSE-'.strtoupper(Str::random(6)), 'name' => 'Synthetic concurrent close', 'branch_id' => $branchId,
    'customer_id' => $customerId, 'manager_id' => $makerId, 'members' => [], 'start_date' => $date, 'end_date' => $end,
    'description' => 'Disposable independent-worker close fixture', 'notes' => '',
]);
$actions->phase($makerId, $closeProject->id, $closeProject->version, ['title' => 'Closeout scope', 'start_date' => $date, 'end_date' => $end]);
$closeProject->refresh();
$closePhase = DB::table('project_phases')->where('project_id', $closeProject->id)->firstOrFail();
$planning->propose($makerId, $closeProject->id, $closeProject->version, (string) Str::uuid(), 'Synthetic clean close baseline.', [
    'scope' => 'Synthetic closeout scope', 'currency' => 'EGP', 'total' => '100.00', 'categories' => [['name' => 'Closeout', 'amount' => '100.00']], 'phases' => [],
    'structure' => [['id' => (int) $closePhase->id, 'title' => $closePhase->title, 'start_date' => $closePhase->start_date, 'end_date' => $closePhase->end_date, 'cancelled' => false]],
]);
$closeProject->refresh();
$closeChangeId = (int) DB::table('project_changes')->where('project_id', $closeProject->id)->where('status', 'pending')->value('id');
$planning->decide($reviewerId, $closeProject->id, $closeProject->version, $closeChangeId, true, 'Independent synthetic close baseline approval.');
$planning->transition($makerId, $closeProject->id, (int) Project::findOrFail($closeProject->id)->version, 'planned', 'Plan synthetic close fixture.');
$planning->transition($makerId, $closeProject->id, (int) Project::findOrFail($closeProject->id)->version, 'active', 'Start synthetic close fixture.');
$closeProject->refresh();
$closePhase = DB::table('project_phases')->where('project_id', $closeProject->id)->firstOrFail();
$actions->task($makerId, $closeProject->id, $closeProject->version, ['phase_id' => (int) $closePhase->id, 'title' => 'Finish closeout scope',
    'assigned_to' => $makerId, 'start_date' => $date, 'end_date' => $end, 'notes' => 'Synthetic task completed by owner action.', 'required' => true]);
$taskId = (int) DB::table('project_tasks')->where('project_id', $closeProject->id)->value('id');
$actions->taskStatus($makerId, $closeProject->id, $taskId, 1, 'completed');
app(ProjectExecution::class)->handover($reviewerId, $closeProject->id, (int) Project::findOrFail($closeProject->id)->version,
    'Synthetic handover evidence for concurrent project close test.');
$planning->transition($reviewerId, $closeProject->id, (int) Project::findOrFail($closeProject->id)->version, 'completed', 'All synthetic close fixture tasks are complete.');
$closeVersion = (int) Project::findOrFail($closeProject->id)->version;
$closeRace = $race([
    ['operation' => 'close_project', 'payload' => ['actor_id' => $reviewerId, 'project_id' => (int) $closeProject->id, 'version' => $closeVersion]],
    ['operation' => 'close_project', 'payload' => ['actor_id' => $reviewerId, 'project_id' => (int) $closeProject->id, 'version' => $closeVersion]],
]);
$closed = Project::findOrFail($closeProject->id);
$closeWins = count(array_filter($closeRace['workers'], fn ($w) => ($w['result']['outcome'] ?? null) === 'passed'));
$need($closeWins === 1 && $closed->status === 'closed' && $closed->version === $closeVersion + 1,
    'Concurrent close did not produce exactly one successful close/version increment.');
$staleClose = app(ProjectPlanning::class);
$staleRejected = false;
try {
    $staleClose->transition($reviewerId, $closeProject->id, $closeVersion, 'closed', 'Stale repeated close version must be rejected.');
} catch (Throwable) {
    $staleRejected = true;
}
$need($staleRejected && Project::findOrFail($closeProject->id)->version === $closeVersion + 1, 'A repeated stale close/version call altered closed project history.');

// Inject a PostgreSQL failure after baseline insertion to prove the approval action rolls the full financial change back.
$failureProject = $actions->save($makerId, null, 0, (string) Str::uuid(), [
    'code' => 'PH2-ROLLBACK-'.strtoupper(Str::random(6)), 'name' => 'Synthetic baseline rollback', 'branch_id' => $branchId,
    'customer_id' => $customerId, 'manager_id' => $makerId, 'members' => [], 'start_date' => $date, 'end_date' => $end,
    'description' => 'Disposable injected PostgreSQL failure fixture', 'notes' => '',
]);
$actions->phase($makerId, $failureProject->id, $failureProject->version, ['title' => 'Rollback scope', 'start_date' => $date, 'end_date' => $end]);
$failureProject->refresh();
$failurePhase = DB::table('project_phases')->where('project_id', $failureProject->id)->firstOrFail();
$planning->propose($makerId, $failureProject->id, $failureProject->version, (string) Str::uuid(), 'Injected SQL failure must leave budget proposal pending.', [
    'scope' => 'Synthetic rollback check', 'currency' => 'EGP', 'total' => '25.00', 'categories' => [['name' => 'Rollback', 'amount' => '25.00']], 'phases' => [],
    'structure' => [['id' => (int) $failurePhase->id, 'title' => $failurePhase->title, 'start_date' => $failurePhase->start_date, 'end_date' => $failurePhase->end_date, 'cancelled' => false]],
]);
$failureProject->refresh();
$failureVersion = (int) $failureProject->version;
$failureChange = (int) DB::table('project_changes')->where('project_id', $failureProject->id)->where('status', 'pending')->value('id');
$functionSql = 'CREATE OR REPLACE FUNCTION phase2_injected_baseline_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.project_id = '.(int) $failureProject->id." THEN RAISE EXCEPTION 'intentional M07 concurrency acceptance failure'; END IF; RETURN NEW; END \$\$";
DB::unprepared($functionSql);
DB::unprepared('CREATE TRIGGER phase2_injected_baseline_failure AFTER INSERT ON project_baselines FOR EACH ROW EXECUTE FUNCTION phase2_injected_baseline_failure()');
try {
    $planning->decide($reviewerId, $failureProject->id, $failureVersion, $failureChange, true, 'Injected database failure rollback acceptance.');
    throw new RuntimeException('Injected financial failure was not raised.');
} catch (QueryException $error) {
    if (! str_contains($error->getMessage(), 'intentional M07 concurrency acceptance failure')) {
        throw $error;
    }
    $failureEvidence = ['class' => $error::class, 'message' => 'intentional M07 concurrency acceptance failure'];
} finally {
    DB::unprepared('DROP TRIGGER IF EXISTS phase2_injected_baseline_failure ON project_baselines');
    DB::unprepared('DROP FUNCTION IF EXISTS phase2_injected_baseline_failure()');
}
$failureProject->refresh();
$need($failureProject->version === $failureVersion && DB::table('project_changes')->where('id', $failureChange)->value('status') === 'pending'
    && DB::table('project_baselines')->where('project_id', $failureProject->id)->count() === 0
    && ! DB::table('project_activities')->where('project_id', $failureProject->id)->where('action', 'change.approved')->exists(),
    'Injected PostgreSQL failure left an approved change, baseline, version increment, or activity behind.');

// Fail actual material-issue financial posting after FIFO/stock work has begun; all owner effects must roll back.
$reservationLink = DB::table('project_material_reservations')->where('project_id', $reserveProject->id)->where('requirement_id', $requirementId)->firstOrFail();
$reservationBefore = DB::table('inventory_reservations')->find($reservationLink->reservation_id);
$balanceBefore = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->firstOrFail();
$movementCountBefore = DB::table('inventory_movements')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->count();
$fifoCountBefore = DB::table('inventory_fifo_allocations')->count();
$journalCountBefore = DB::table('accounting_journals')->count();
$issueKey = (string) Str::uuid();
DB::unprepared("CREATE OR REPLACE FUNCTION phase2_injected_inventory_gl_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.source_key LIKE 'inventory-movement:%' THEN RAISE EXCEPTION 'intentional M07 inventory GL failure'; END IF; RETURN NEW; END \$\$");
DB::unprepared('CREATE TRIGGER phase2_injected_inventory_gl_failure BEFORE INSERT ON accounting_journals FOR EACH ROW EXECUTE FUNCTION phase2_injected_inventory_gl_failure()');
$inventoryFailure = null;
try {
    app(ProjectMaterials::class)->issue($reviewerId, (int) $reserveProject->id, (int) $reservationLink->reservation_id, '1.00000000', $issueKey);
    throw new RuntimeException('Injected project inventory GL failure was not raised.');
} catch (QueryException $error) {
    if (! str_contains($error->getMessage(), 'intentional M07 inventory GL failure')) {
        throw $error;
    }
    $inventoryFailure = ['class' => $error::class, 'message' => 'intentional M07 inventory GL failure'];
} finally {
    DB::unprepared('DROP TRIGGER IF EXISTS phase2_injected_inventory_gl_failure ON accounting_journals');
    DB::unprepared('DROP FUNCTION IF EXISTS phase2_injected_inventory_gl_failure()');
}
$reservationAfter = DB::table('inventory_reservations')->find($reservationLink->reservation_id);
$balanceAfter = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->firstOrFail();
$need($inventoryFailure !== null && $reservationAfter->fulfilled === $reservationBefore->fulfilled && $reservationAfter->released === $reservationBefore->released
    && $balanceAfter->on_hand === $balanceBefore->on_hand && $balanceAfter->reserved === $balanceBefore->reserved
    && DB::table('inventory_movements')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->count() === $movementCountBefore
    && DB::table('inventory_fifo_allocations')->count() === $fifoCountBefore && DB::table('accounting_journals')->count() === $journalCountBefore
    && ! DB::table('project_material_issues')->where('issue_key', $issueKey)->exists()
    && ! DB::table('inventory_reservation_events')->where('idempotency_key', $issueKey)->exists(),
    'Injected material GL failure left partial reservation, stock, FIFO, journal, or project issue effects.');

// Store one real M07 private document revision, then restore PostgreSQL plus that private path into a clone.
$documentPhase = DB::table('project_phases')->where('project_id', $reserveProject->id)->where('cancelled', false)->firstOrFail();
$documentProject = Project::findOrFail($reserveProject->id);
$actions->task($makerId, (int) $reserveProject->id, (int) $documentProject->version, ['phase_id' => (int) $documentPhase->id,
    'title' => 'Synthetic task linked to private recovery document', 'assigned_to' => $makerId, 'start_date' => $date, 'end_date' => $end,
    'notes' => 'Task row for migration 181 recovery verification.', 'required' => false]);
$documentTaskId = (int) DB::table('project_tasks')->where('project_id', $reserveProject->id)->where('title', 'Synthetic task linked to private recovery document')->value('id');
$need($documentTaskId > 0, 'Could not create a real project task for task-document recovery verification.');
$documentFixture = $root.'/.tmp/phase2-concurrency-20261028/project-recovery-fixture.pdf';
$recoveryDocumentNumber = 'RECOVERY-M07-'.strtoupper(Str::random(8));
$pdfBytes = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents($documentFixture, $pdfBytes, LOCK_EX);
$uploaded = new UploadedFile($documentFixture, 'synthetic-project-recovery.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
app(ProjectDocuments::class)->upload($makerId, $sourceProjectId, (string) Str::uuid(), ['number' => $recoveryDocumentNumber, 'title' => 'Synthetic recovery acceptance document',
    'classification' => 'synthetic', 'required' => false, 'task_id' => $documentTaskId, 'notes' => 'Synthetic-only M07 private file for restore verification.'], $uploaded);
$documentRevision = DB::table('project_document_revisions as r')->join('project_documents as d', 'd.id', '=', 'r.document_id')
    ->where('d.project_id', $sourceProjectId)->where('d.number', $recoveryDocumentNumber)->orderByDesc('r.revision')->first(['r.path', 'r.sha256', 'r.size', 'r.revision', 'd.task_id']);
$need($documentRevision && str_starts_with($documentRevision->path, 'projects/'.$sourceProjectId.'/'), 'M07 document upload did not create a safe private project path.');
$need((int) $documentRevision->task_id === $documentTaskId && DB::table('project_tasks')->where('project_id', $sourceProjectId)->where('id', $documentTaskId)->exists(),
    'M07 document revision did not retain its action-created same-project task association.');
$sourcePrivateFile = Storage::disk('local')->path($documentRevision->path);
$need(is_file($sourcePrivateFile) && hash_equals($documentRevision->sha256, hash_file('sha256', $sourcePrivateFile)), 'M07 private file hash does not match its persisted document revision.');
$backupRoot = $root.'/.tmp/phase2-concurrency-20261028';
if (! is_dir($backupRoot)) {
    mkdir($backupRoot, 0700, true);
}
$dumpPath = $backupRoot.'/phase2-postgres-backup.dump';
$binaryRoot = $root.'/.tools/postgresql/pgsql/bin';
$dump = new Process([$binaryRoot.'/pg_dump.exe', '-h', '127.0.0.1', '-p', '55437', '-U', 'postgres', '-Fc', '-f', $dumpPath, 'erp_elec_new_test'], $root, null, null, 120);
$dump->run();
$need($dump->isSuccessful() && is_file($dumpPath) && filesize($dumpPath) > 0, 'Synthetic PostgreSQL backup could not be created.');
$restoreDb = 'erp_elec_new_phase2_restore_test';
$psql = new Process([$binaryRoot.'/psql.exe', '-h', '127.0.0.1', '-p', '55437', '-U', 'postgres', '-d', 'postgres', '-v', 'ON_ERROR_STOP=1', '-c', 'DROP DATABASE IF EXISTS '.$restoreDb.' WITH (FORCE)', '-c', 'CREATE DATABASE '.$restoreDb], $root, null, null, 60);
$psql->run();
$need($psql->isSuccessful(), 'Could not create isolated Phase 2 restore clone: '.mb_substr(trim($psql->getErrorOutput()), 0, 500));
$restore = new Process([$binaryRoot.'/pg_restore.exe', '-h', '127.0.0.1', '-p', '55437', '-U', 'postgres', '-d', $restoreDb, '--no-owner', $dumpPath], $root, null, null, 120);
$restore->run();
$need($restore->isSuccessful(), 'Synthetic PostgreSQL restore failed: '.mb_substr(trim($restore->getErrorOutput()), 0, 700));
$targetPrivateRoot = $root.'/.tmp/phase2-concurrency-restore-storage/app/private';
$targetProjectDir = $targetPrivateRoot.'/projects/'.$sourceProjectId;
if (! is_dir($targetProjectDir) && ! mkdir($targetProjectDir, 0700, true) && ! is_dir($targetProjectDir)) {
    throw new RuntimeException('Cannot create isolated restored M07 private project path.');
}
if (! copy($sourcePrivateFile, $targetProjectDir.'/'.basename($documentRevision->path))) {
    throw new RuntimeException('Could not restore the synthetic M07 private file.');
}
$pdo = new PDO('pgsql:host=127.0.0.1;port=55437;dbname='.$restoreDb, 'postgres', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$restoredMigrations = (int) $pdo->query("select count(*) from migrations where migration like '%_178000_%' or migration like '%_179000_%' or migration like '%_180000_%' or migration like '%_181000_%'")->fetchColumn();
$taskColumn = (int) $pdo->query("select count(*) from information_schema.columns where table_schema='public' and table_name='project_documents' and column_name='task_id'")->fetchColumn();
$taskForeignKey = (int) $pdo->query("select count(*) from pg_constraint c join pg_class t on t.oid=c.conrelid where t.relname='project_documents' and c.contype='f' and pg_get_constraintdef(c.oid) like '%FOREIGN KEY (task_id)%'")->fetchColumn();
$documentQuery = $pdo->prepare('select r.path,r.sha256,r.size,d.project_id,d.task_id from project_document_revisions r join project_documents d on d.id=r.document_id where d.number=? order by r.revision desc limit 1');
$documentQuery->execute([$recoveryDocumentNumber]);
$restoredDocument = $documentQuery->fetch(PDO::FETCH_ASSOC);
$restoredFile = is_array($restoredDocument) ? $targetPrivateRoot.'/'.$restoredDocument['path'] : '';
$restoredRowCount = (int) $pdo->query('select count(*) from projects where id='.(int) $sourceProjectId)->fetchColumn();
$restoredTask = is_array($restoredDocument) ? $pdo->query('select project_id from project_tasks where id='.(int) $restoredDocument['task_id'])->fetch(PDO::FETCH_ASSOC) : false;
$pdo = null;
$need($restoredMigrations === 4 && $taskColumn === 1 && $taskForeignKey === 1 && is_array($restoredDocument) && $restoredRowCount === 1
    && (int) $restoredDocument['task_id'] === $documentTaskId && is_array($restoredTask) && (int) $restoredTask['project_id'] === $sourceProjectId && is_file($restoredFile)
    && (int) filesize($restoredFile) === (int) $restoredDocument['size'] && hash_equals($restoredDocument['sha256'], hash_file('sha256', $restoredFile)),
    'Restored M07 task-document schema/FK/association, project row, private file, size, and SHA-256 did not reconcile.');
$recoveryEvidence = ['backup_bytes' => filesize($dumpPath), 'restored_database' => $restoreDb, 'migrations_178_181' => $restoredMigrations,
    'task_id_column' => $taskColumn === 1, 'task_id_foreign_key' => $taskForeignKey === 1, 'task_id' => (int) $restoredDocument['task_id'],
    'task_same_project' => (int) $restoredTask['project_id'] === $sourceProjectId,
    'project_id' => $sourceProjectId, 'document_path' => $restoredDocument['path'], 'private_sha256' => hash_file('sha256', $restoredFile),
    'private_size' => filesize($restoredFile), 'passed' => true];
@unlink($documentFixture);

$evidence = [
    'database' => (string) $connection->db, 'postgres_port' => (int) $connection->port, 'synthetic' => true,
    'budget_approval_one_winner' => ['project_id' => (int) $reserveProject->id, 'change_id' => $budgetChangeId, 'workers' => $approvedRace,
        'persisted_baseline_count' => $approvedBaselines, 'change_status' => $approvedChange, 'passed' => true],
    'material_demand_cap' => ['project_id' => (int) $reserveProject->id, 'requirement_id' => $requirementId, 'demand' => '1.00000000',
        'active_reservation_quantity' => $reservationQty, 'reservation_rows' => $materialReservations, 'workers' => $materialRace, 'passed' => true],
    'source_link_replay' => ['project_id' => $sourceProjectId, 'source_type' => 'purchase_order', 'source_id' => $orderId, 'link_rows' => $linkCount,
        'workers' => $linkRace, 'passed' => true],
    'repeated_close_version' => ['project_id' => (int) $closeProject->id, 'status' => $closed->status, 'version_before' => $closeVersion,
        'version_after' => $closed->version, 'workers' => $closeRace, 'stale_repeat_rejected' => $staleRejected, 'passed' => true],
    'injected_financial_failure_rollback' => ['project_id' => (int) $failureProject->id, 'change_id' => $failureChange,
        'version_after_failure' => $failureProject->version, 'change_status_after_failure' => DB::table('project_changes')->where('id', $failureChange)->value('status'),
        'baseline_rows_after_failure' => DB::table('project_baselines')->where('project_id', $failureProject->id)->count(), 'injected_error' => $failureEvidence, 'passed' => true],
    'material_financial_failure_rollback' => ['project_id' => (int) $reserveProject->id, 'reservation_id' => (int) $reservationLink->reservation_id,
        'issue_key' => $issueKey, 'injected_error' => $inventoryFailure, 'stock_unchanged' => true, 'fifo_unchanged' => true, 'gl_unchanged' => true,
        'project_issue_absent' => true, 'passed' => true],
    'postgres_and_private_file_recovery' => $recoveryEvidence,
    'all_pass' => true,
];
$evidencePath = $root.'/docs/qa/evidence/phase2-project-concurrency.json';
if (! is_dir(dirname($evidencePath))) {
    mkdir(dirname($evidencePath), 0770, true);
}
file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
