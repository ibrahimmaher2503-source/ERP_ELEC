<?php

// Read the already committed M07 action graph and prepare its isolated browser identity.
use App\Models\User;
use App\Modules\Projects\Application\ProjectFinancials;
use App\Modules\Projects\Domain\Project;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = getenv('PHASE2_STORAGE_ROOT');
if (! $storage || ! str_contains(str_replace('\\', '/', $storage), '/.tmp/phase2-projects-browser')) {
    throw new RuntimeException('ABORT: browser storage must be isolated under workspace .tmp.');
}
foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir($storage.'/'.$dir) && ! mkdir($storage.'/'.$dir, 0700, true) && ! is_dir($storage.'/'.$dir)) {
        throw new RuntimeException('Cannot create isolated M07 browser storage.');
    }
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$expectedPort = (int) (getenv('PHASE2_BROWSER_DB_PORT') ?: 55435);
$actual = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'erp_elec_new_test' || config('database.connections.pgsql.host') !== '127.0.0.1'
    || (int) config('database.connections.pgsql.port') !== $expectedPort || $actual->db !== 'erp_elec_new_test'
    || ! in_array($actual->address, ['127.0.0.1', '127.0.0.1/32'], true) || (int) $actual->port !== $expectedPort
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true) || ! in_array($expectedPort, [55435, 55437, 55440], true)) {
    throw new RuntimeException('ABORT before browser setup: expected isolated localhost PostgreSQL clone.');
}

// This is the synthetic, closed financial source project emitted by the guarded commit fixture.
$project = Project::query()->where('name', 'Synthetic project financial integration')->where('status', 'closed')->orderByDesc('id')->first();
if (! $project) {
    throw new RuntimeException('Committed, closed synthetic M07 source project is missing.');
}
$maker = User::query()->whereKey((int) $project->manager_id)->where('active', true)->whereNotNull('email_verified_at')->first();
$baseline = DB::table('project_baselines')->where('project_id', $project->id)->orderByDesc('revision')->first();
$reviewer = $baseline ? User::query()->whereKey((int) $baseline->approved_by)->where('active', true)->whereNotNull('email_verified_at')->first() : null;
$deniedId = DB::table('access_grants')->where('permission_code', 'M05.receipt_view')->where('branch_id', $project->branch_id)
    ->whereNotIn('user_id', array_filter([$maker?->id, $reviewer?->id]))->value('user_id');
$denied = $deniedId ? User::query()->whereKey((int) $deniedId)->where('active', true)->whereNotNull('email_verified_at')->first() : null;
if (! $maker || ! $reviewer || ! $denied || $maker->id === $reviewer->id || $denied->id === $maker->id || $denied->id === $reviewer->id) {
    throw new RuntimeException('Synthetic browser maker/reviewer/denied actors are incomplete or not independent.');
}
$browserPassword = getenv('PHASE2_BROWSER_PASSWORD');
if (getenv('PHASE2_E2E_SET_ACTOR_PASSWORD') === '1') {
    if (! is_string($browserPassword) || strlen($browserPassword) < 20) {
        throw new RuntimeException('ABORT: synthetic browser actor credential reset was invalid.');
    }
    DB::table('users')->where('id', $maker->id)->update(['password' => Hash::make($browserPassword), 'updated_at' => now()]);
}

// Minimal synthetic UI permissions required by the browser journey; do not grant a role or broad module access.
$now = now();
foreach ([$maker->id, $reviewer->id] as $budgetViewerId) {
    DB::table('access_grants')->insertOrIgnore(['user_id' => $budgetViewerId, 'permission_code' => 'M07.budget_view',
        'branch_id' => $project->branch_id, 'created_at' => $now, 'updated_at' => $now]);
}
DB::table('access_grants')->insertOrIgnore(['user_id' => $maker->id, 'permission_code' => 'M07.export',
    'branch_id' => $project->branch_id, 'created_at' => $now, 'updated_at' => $now]);
$warehouseForBrowser = (int) DB::table('project_material_reservations')->where('project_id', $project->id)->join('inventory_reservations', 'inventory_reservations.id', '=', 'project_material_reservations.reservation_id')->value('warehouse_id');
DB::table('access_grants')->insertOrIgnore(['user_id' => $maker->id, 'permission_code' => 'M07.material_issue',
    'branch_id' => $project->branch_id, 'created_at' => $now, 'updated_at' => $now]);
if ($warehouseForBrowser > 0) {
    DB::table('user_warehouse_scopes')->insertOrIgnore(['user_id' => $maker->id, 'warehouse_id' => $warehouseForBrowser,
        'branch_id' => $project->branch_id, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('access_grants')->insertOrIgnore(['user_id' => $maker->id, 'permission_code' => 'M06.issue_post',
        'branch_id' => $project->branch_id, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('access_grants')->insertOrIgnore(['user_id' => $maker->id, 'permission_code' => 'M06.balance_view',
        'branch_id' => $project->branch_id, 'created_at' => $now, 'updated_at' => $now]);
}

$links = DB::table('project_source_links')->where('project_id', $project->id)->orderBy('id')->get(['id', 'source_type', 'source_id']);
$salesIds = $links->where('source_type', 'sales_order')->pluck('source_id')->map(fn ($id) => (int) $id)->values()->all();
$poIds = $links->where('source_type', 'purchase_order')->pluck('source_id')->map(fn ($id) => (int) $id)->values()->all();
$issue = DB::table('project_material_issues')->where('project_id', $project->id)->where('status', 'posted')->orderByDesc('id')->first();
$movement = $issue ? DB::table('inventory_movements')->where('idempotency_key', 'reservation:'.$issue->issue_key)->first() : null;
$requirement = $issue ? DB::table('project_requirements')->where('id', $issue->requirement_id)->first() : null;
$reservation = $issue ? DB::table('inventory_reservations')->where('id', $issue->reservation_id)->first() : null;
$linkableSales = DB::table('sales_orders')->where('notes', 'Synthetic unlinked draft source for isolated browser acceptance')->first();
$linkableSalesLine = $linkableSales ? DB::table('sales_order_lines')->where('sales_order_id', $linkableSales->id)->first() : null;
$linkablePo = DB::table('purchasing_orders')->where('notes', 'NEG-B')->first();
$linkablePoLine = $linkablePo ? DB::table('purchasing_order_lines')->where('order_id', $linkablePo->id)->first() : null;
$linkableReceipt = $linkablePo ? DB::table('purchasing_receipts')->where('order_id', $linkablePo->id)->where('status', 'posted')->first() : null;
$linkableReceiptLine = $linkableReceipt ? DB::table('purchasing_receipt_lines')->where('receipt_id', $linkableReceipt->id)->first() : null;
$resumeProjectId = (int) (getenv('PHASE2_E2E_PROJECT_ID') ?: 0);
$resumeProject = $resumeProjectId > 0 ? Project::query()->find($resumeProjectId) : null;
if ($resumeProject) {
    $resumeLinks = DB::table('project_source_links')->where('project_id', $resumeProject->id)->get();
    $resumeSalesId = (int) ($resumeLinks->firstWhere('source_type', 'sales_order')->source_id ?? 0);
    $resumePoId = (int) ($resumeLinks->firstWhere('source_type', 'purchase_order')->source_id ?? 0);
    if ($resumeSalesId) {
        $linkableSales = DB::table('sales_orders')->where('id', $resumeSalesId)->first();
        $linkableSalesLine = $linkableSales ? DB::table('sales_order_lines')->where('sales_order_id', $resumeSalesId)->first() : null;
    }
    if ($resumePoId) {
        $linkablePo = DB::table('purchasing_orders')->where('id', $resumePoId)->first();
        $linkablePoLine = $linkablePo ? DB::table('purchasing_order_lines')->where('order_id', $resumePoId)->first() : null;
        $linkableReceipt = $linkablePo ? DB::table('purchasing_receipts')->where('order_id', $resumePoId)->where('status', 'posted')->first() : null;
        $linkableReceiptLine = $linkableReceipt ? DB::table('purchasing_receipt_lines')->where('receipt_id', $linkableReceipt->id)->first() : null;
    }
}
if ($salesIds === [] || count($poIds) < 2 || ! $issue || ! $movement || ! $requirement || ! $reservation
    || ! DB::table('inventory_fifo_allocations')->where('movement_id', $movement->id)->exists()
    || ! $linkableSales || (! $resumeProject && getenv('PHASE2_E2E_DIAG_CLOSE') !== '1' && $linkableSales->status !== 'draft') || ! $linkableSalesLine
    || ! $linkablePo || ! $linkablePoLine || ! $linkableReceiptLine
    || (! $resumeProject && getenv('PHASE2_E2E_DIAG_CLOSE') !== '1' && (DB::table('project_source_links')->where('source_type', 'sales_order')->where('source_id', $linkableSales->id)->exists()
    || DB::table('project_source_links')->where('source_type', 'purchase_order')->where('source_id', $linkablePo->id)->exists()))) {
    throw new RuntimeException('Committed source graph is missing linked or safely unlinked Sales/PO/material/FIFO records.');
}
$summary = app(ProjectFinancials::class)->summary((int) $maker->id, (int) $project->id);
if (in_array(true, $summary['closure_blockers'], true) || $summary['fifo_gl_difference'] !== '0.00'
    || $summary['subledger']['unallocated_invoices'] !== 0 || $summary['subledger']['pending_invoices'] !== 0
    || $summary['subledger']['grni_cleared'] !== $summary['purchasing']['received_value']) {
    throw new RuntimeException('Committed closed source project does not reconcile its real owner projections.');
}
$salesId = $salesIds[0];
$fixture = ['database' => $actual->db, 'host' => '127.0.0.1', 'port' => (int) $actual->port,
    'actor' => ['id' => (int) $maker->id, 'email' => $maker->email], 'reviewer' => ['id' => (int) $reviewer->id, 'email' => $reviewer->email],
    'denied_actor' => ['id' => (int) $denied->id, 'email' => $denied->email],
    'project' => ['id' => (int) $project->id, 'code' => $project->code, 'name' => $project->name,
        'branch_id' => (int) $project->branch_id, 'customer_id' => (int) $project->customer_id,
        'customer_name' => DB::table('customers')->where('id', $project->customer_id)->value('name'), 'status' => $project->status],
    'sales_order_ids' => $salesIds, 'purchase_order_ids' => $poIds, 'product_id' => (int) $requirement->product_id,
    'warehouse_id' => (int) $reservation->warehouse_id, 'project_issue_id' => (int) $issue->id, 'issue_movement_id' => (int) $movement->id,
    'linkable_sales_order' => ['id' => (int) $linkableSales->id, 'line_id' => (int) $linkableSalesLine->id, 'status' => $linkableSales->status],
    'linkable_purchase_order' => ['order_id' => (int) $linkablePo->id, 'order_line_id' => (int) $linkablePoLine->id, 'receipt_line_id' => (int) $linkableReceiptLine->id]];
if ($resumeProject) {
    $fixture['existing_browser_project'] = ['id' => (int) $resumeProject->id, 'name' => $resumeProject->name, 'status' => $resumeProject->status,
        'sales_order_id' => (int) $linkableSales->id, 'purchase_order_id' => (int) $linkablePo->id,
        'requirement_id' => (int) DB::table('project_requirements')->where('project_id', $resumeProject->id)->value('id'),
        'reservation_id' => (int) DB::table('project_material_reservations')->where('project_id', $resumeProject->id)->orderByDesc('id')->value('reservation_id'),
        'posted_issue_id' => (int) DB::table('project_material_issues')->where('project_id', $resumeProject->id)->where('status', 'posted')->orderByDesc('id')->value('id'),
        'posted_reservation_id' => (int) (DB::table('project_material_issues')->where('project_id', $resumeProject->id)->where('status', 'posted')->orderByDesc('id')->value('reservation_id') ?: 0),
        'task_id' => (int) DB::table('project_tasks')->where('project_id', $resumeProject->id)->value('id'),
        'task_title' => DB::table('project_tasks')->where('project_id', $resumeProject->id)->value('title'),
        'task_status' => DB::table('project_tasks')->where('project_id', $resumeProject->id)->value('status'),
        'milestone_id' => (int) DB::table('project_milestones')->where('project_id', $resumeProject->id)->value('id'),
        'milestone_title' => DB::table('project_milestones')->where('project_id', $resumeProject->id)->value('title'),
        'milestone_status' => DB::table('project_milestones')->where('project_id', $resumeProject->id)->value('status'),
        'document_number' => DB::table('project_documents')->where('project_id', $resumeProject->id)->orderByDesc('id')->value('number')];
}
$financeEvidence = ['source_fixture_readback' => true, 'database' => 'erp_elec_new_test', 'postgres_port' => $expectedPort,
    'project_financial_projection' => ['budget' => $summary['approved_budget'], 'remaining' => $summary['remaining_budget'],
        'purchasing' => $summary['purchasing'], 'subledger' => $summary['subledger'], 'accounting' => $summary['accounting'],
        'fifo_gl_difference' => $summary['fifo_gl_difference']],
    'customer' => ['sales_order_id' => $salesId],
    'project_fixture' => ['project_id' => (int) $project->id, 'manager_id' => (int) $maker->id, 'reviewer_id' => (int) $reviewer->id,
        'denied_actor_id' => (int) $denied->id, 'branch_id' => (int) $project->branch_id, 'customer_id' => (int) $project->customer_id,
        'product_id' => (int) $requirement->product_id, 'warehouse_id' => (int) $reservation->warehouse_id,
        'purchase_order_ids' => $poIds, 'sales_order_id' => $salesId, 'project_issue_id' => (int) $issue->id,
        'source_link_ids' => $links->pluck('id')->map(fn ($id) => (int) $id)->all(), 'committed' => true]];
$fixture['finance_evidence'] = $financeEvidence;
$newProjectId = (int) (getenv('PHASE2_E2E_PROJECT_ID') ?: 0);
if ($newProjectId > 0) {
    $newProject = DB::table('projects')->where('id', $newProjectId)->first();
    $newDocument = DB::table('project_documents as d')->join('project_document_revisions as r', 'r.document_id', '=', 'd.id')
        ->where('d.project_id', $newProjectId)->orderByDesc('r.id')->first(['d.id as document_id', 'd.task_id', 'd.number', 'd.title', 'r.id as revision_id', 'r.status', 'r.sha256', 'r.size']);
    $fixture['browser_project_readback'] = $newProject ? ['id' => (int) $newProject->id, 'code' => $newProject->code, 'name' => $newProject->name,
        'status' => $newProject->status, 'version' => (int) $newProject->version,
        'baseline' => DB::table('project_baselines')->where('project_id', $newProjectId)->orderByDesc('revision')->first(['revision', 'currency', 'total', 'approved_by']),
        'phases' => (int) DB::table('project_phases')->where('project_id', $newProjectId)->count(),
        'tasks' => (int) DB::table('project_tasks')->where('project_id', $newProjectId)->count(),
        'task_ids' => DB::table('project_tasks')->where('project_id', $newProjectId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(),
        'task_states' => DB::table('project_tasks')->where('project_id', $newProjectId)->orderBy('id')->get(['id', 'title', 'status', 'version']),
        'milestones' => (int) DB::table('project_milestones')->where('project_id', $newProjectId)->count(),
        'milestone_states' => DB::table('project_milestones')->where('project_id', $newProjectId)->orderBy('id')->get(['id', 'title', 'status', 'version']),
        'requirements' => (int) DB::table('project_requirements')->where('project_id', $newProjectId)->count(),
        'reservation_ids' => DB::table('project_material_reservations')->where('project_id', $newProjectId)->orderBy('id')->pluck('reservation_id')->map(fn ($id) => (int) $id)->all(),
        'handovers' => (int) DB::table('project_handovers')->where('project_id', $newProjectId)->count(),
        'issue' => (function () use ($newProjectId) {
            $issue = DB::table('project_material_issues')->where('project_id', $newProjectId)->where('status', 'posted')->orderByDesc('id')->first();
            $movement = $issue ? DB::table('inventory_movements')->where('idempotency_key', 'reservation:'.$issue->issue_key)->first() : null;

            return $issue ? ['id' => (int) $issue->id, 'reservation_id' => (int) $issue->reservation_id,
                'movement_id' => (int) ($movement->id ?? 0), 'fifo_allocations' => $movement ? (int) DB::table('inventory_fifo_allocations')->where('movement_id', $movement->id)->count() : 0] : null;
        })(), 'document' => $newDocument] : null;
}
echo json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
