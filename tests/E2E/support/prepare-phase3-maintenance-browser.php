<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Inventory\Domain\Warehouse;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
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
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$expectedPort = (int) (getenv('PHASE3_BROWSER_DB_PORT') ?: 55440);
$storage = getenv('PHASE3_STORAGE_ROOT');
if (! $storage || ! str_contains(str_replace('\\', '/', $storage), '/.tmp/phase3-maintenance-browser/')
    || ! in_array($expectedPort, [55435, 55437, 55440], true)) {
    throw new RuntimeException('ABORT: M08 browser fixture requires private workspace storage and an approved disposable PostgreSQL clone port.');
}
foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir($storage.'/'.$dir) && ! mkdir($storage.'/'.$dir, 0700, true) && ! is_dir($storage.'/'.$dir)) {
        throw new RuntimeException('Cannot create isolated M08 browser storage.');
    }
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$actual = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'erp_elec_new_test' || config('database.connections.pgsql.host') !== '127.0.0.1'
    || (int) config('database.connections.pgsql.port') !== $expectedPort || $actual->db !== 'erp_elec_new_test'
    || ! in_array($actual->address, ['127.0.0.1', '127.0.0.1/32'], true) || (int) $actual->port !== $expectedPort
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)) {
    throw new RuntimeException('ABORT before browser writes: expected the isolated loopback PostgreSQL clone.');
}

$mode = getenv('PHASE3_FIXTURE_MODE') ?: 'prepare';
if ($mode === 'request-readback') {
    $requestId = (int) (getenv('PHASE3_REQUEST_ID') ?: 0);
    $request = $requestId > 0 ? DB::table('maintenance_requests')->find($requestId) : null;
    if (! $request) {
        throw new RuntimeException('M08 browser request readback could not find the persisted request.');
    }
    echo json_encode(['database' => $actual->db, 'port' => (int) $actual->port,
        'request' => ['id' => (int) $request->id, 'version' => (int) $request->version,
            'status' => $request->status, 'description' => $request->description]], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($mode === 'readback') {
    $id = (int) (getenv('PHASE3_WORK_ORDER_ID') ?: 0);
    $requestId = (int) (getenv('PHASE3_REQUEST_ID') ?: 0);
    if ($id < 1) {
        throw new RuntimeException('M08 browser readback requires a work-order ID.');
    }
    $work = DB::table('maintenance_work_orders')->find($id);
    if (! $work) {
        throw new RuntimeException('M08 browser work order is missing from the isolated PostgreSQL clone.');
    }
    $visits = DB::table('maintenance_visits')->where('work_order_id', $id)->get();
    $visitIds = $visits->pluck('id');
    $request = $requestId > 0 ? DB::table('maintenance_requests')->find($requestId) : null;
    echo json_encode(['database' => $actual->db, 'port' => (int) $actual->port,
        'accounting_activated' => DB::table('accounting_activation')->where('id', 1)->whereNotNull('activated_at')->exists(),
        'accounting_periods' => DB::table('accounting_periods')->get(['starts_on', 'ends_on', 'status'])->map(fn ($row) => (array) $row)->all(),
        'inventory_product_count' => DB::table('products')->where('kind', '<>', 'service')->count(),
        'stocked_inventory' => DB::table('inventory_balances as b')->join('inventory_warehouses as w', 'w.id', '=', 'b.warehouse_id')->whereRaw('b.on_hand > 0')->orderByDesc('b.on_hand')->limit(5)->get(['b.product_id', 'b.warehouse_id', 'w.branch_id', 'b.on_hand'])->map(fn ($row) => (array) $row)->all(),
        'work_order' => ['id' => (int) $work->id, 'number' => $work->number, 'status' => $work->status, 'version' => (int) $work->version,
            'closed_at' => $work->closed_at, 'request_id' => $work->request_id ? (int) $work->request_id : null],
        'request' => $request ? ['id' => (int) $request->id, 'version' => (int) $request->version, 'status' => $request->status, 'description' => $request->description] : null,
        'visits' => $visits->map(fn ($visit) => ['id' => (int) $visit->id, 'status' => $visit->status, 'outcome' => $visit->outcome,
            'recorded_by' => $visit->recorded_by ? (int) $visit->recorded_by : null, 'acknowledgment' => $visit->acknowledgment])->all(),
        'assignments' => DB::table('maintenance_assignments')->where('work_order_id', $id)->get(['id', 'technician_id', 'active'])->map(fn ($row) => (array) $row)->all(),
        'part_requirements' => DB::table('maintenance_part_requirements')->where('work_order_id', $id)->get(['id', 'product_id', 'warehouse_id', 'quantity'])->map(fn ($row) => (array) $row)->all(),
        'part_reservations' => DB::table('maintenance_part_reservations')->whereIn('requirement_id', DB::table('maintenance_part_requirements')->where('work_order_id', $id)->select('id'))->get(['requirement_id', 'reservation_id'])->map(fn ($row) => (array) $row)->all(),
        'part_issues' => DB::table('maintenance_part_issues')->whereIn('requirement_id', DB::table('maintenance_part_requirements')->where('work_order_id', $id)->select('id'))->get(['id', 'movement_id', 'journal_id', 'quantity', 'amount'])->map(fn ($row) => (array) $row)->all(),
        'documents' => DB::table('maintenance_documents')->where('work_order_id', $id)->get(['id', 'visit_id', 'original_name', 'mime', 'sha256'])->map(fn ($row) => (array) $row)->all(),
        'inventory_on_hand' => DB::table('inventory_balances')->whereIn('product_id', DB::table('maintenance_part_requirements')->where('work_order_id', $id)->select('product_id'))->whereIn('warehouse_id', DB::table('maintenance_part_requirements')->where('work_order_id', $id)->select('warehouse_id'))->value('on_hand'),
        'checklist_results' => DB::table('maintenance_checklist_results')->whereIn('visit_id', $visitIds)->count(),
        'labor' => DB::table('maintenance_labor_entries')->where('work_order_id', $id)->get(['duration_minutes', 'work_type', 'cost'])->map(fn ($row) => (array) $row)->all(),
        'completions' => DB::table('maintenance_completions')->where('work_order_id', $id)->get(['id', 'revision', 'official_document_id'])->map(fn ($row) => (array) $row)->all(),
        'completion_count' => DB::table('maintenance_completions')->where('work_order_id', $id)->count(),
        'activity_count' => DB::table('maintenance_activities')->where('entity_type', 'maintenance_work_order')->where('entity_id', $id)->count()], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($mode !== 'prepare') {
    throw new RuntimeException('Unknown M08 browser fixture mode.');
}
$runtimePrivileges = DB::selectOne("select current_user as role,
    has_table_privilege(current_user, 'public.maintenance_cost_proposals', 'select,insert') as cost_proposals,
    has_table_privilege(current_user, 'public.maintenance_expense_sources', 'select,insert') as expense_sources,
    has_table_privilege(current_user, 'public.maintenance_labor_recognitions', 'select,insert') as labor_recognitions,
    has_sequence_privilege(current_user, 'public.maintenance_cost_proposals_id_seq', 'usage,select') as cost_proposal_sequence,
    has_sequence_privilege(current_user, 'public.maintenance_expense_sources_id_seq', 'usage,select') as expense_source_sequence");
if (! $runtimePrivileges->cost_proposals || ! $runtimePrivileges->expense_sources || ! $runtimePrivileges->labor_recognitions
    || ! $runtimePrivileges->cost_proposal_sequence || ! $runtimePrivileges->expense_source_sequence) {
    throw new RuntimeException('ABORT before browser writes: the isolated PostgreSQL runtime role lacks the required M08 costing DML/sequence grants.');
}
$password = getenv('PHASE3_BROWSER_PASSWORD');
if (! is_string($password) || strlen($password) < 24) {
    throw new RuntimeException('M08 browser setup requires an ephemeral test-only actor password.');
}
if (! DB::table('companies')->where('id', 1)->exists()) {
    Company::factory()->create();
}
$tag = 'DEMO-EG-M08-BROWSER-'.Str::upper(Str::random(8));
$stock = DB::table('inventory_balances as b')->join('inventory_warehouses as w', 'w.id', '=', 'b.warehouse_id')
    ->whereRaw('b.on_hand > 0')->orderByDesc('b.on_hand')->first(['b.product_id', 'b.warehouse_id', 'w.branch_id', 'b.on_hand']);
if (! $stock || bccomp((string) DB::table('inventory_fifo_layers')->where('warehouse_id', $stock->warehouse_id)
    ->where('product_id', $stock->product_id)->sum('remaining_quantity'), '1.00000000', 8) < 0) {
    throw new RuntimeException('M08 browser parts issue requires existing posted FIFO stock in the isolated clone.');
}
$branch = Branch::query()->find((int) $stock->branch_id);
$warehouse = Warehouse::query()->find((int) $stock->warehouse_id);
$product = Product::query()->find((int) $stock->product_id);
if (! $branch?->active || ! $warehouse?->active || ! $product?->active || $product->kind === 'service' || $product->tracking !== 'quantity') {
    throw new RuntimeException('Existing M08 browser stock source is not an active quantity product, warehouse, and branch.');
}
$customer = Customer::factory()->create(['code' => $tag, 'name' => 'عميل تجربة صيانة ميدانية']);
$actors = [];
foreach (['dispatcher', 'technician', 'reviewer', 'denied'] as $kind) {
    $actors[$kind] = User::factory()->create(['active' => true, 'email_verified_at' => now(), 'password' => Hash::make($password)]);
    DB::table('user_branch_scopes')->insert(['user_id' => $actors[$kind]->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
}
$grants = [
    'dispatcher' => ['view', 'request_create', 'request_edit', 'request_approve', 'workorder_manage', 'assign', 'checklist_manage', 'parts_request', 'cost_view', 'document_manage', 'document_view', 'reports_view'],
    'technician' => ['view', 'visit_record', 'labor_record', 'workorder_manage', 'parts_issue'],
    'reviewer' => ['view', 'verify', 'close', 'cost_view', 'document_view', 'reports_view'],
    'denied' => [],
];
foreach ($grants as $kind => $permissions) {
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insert(['user_id' => $actors[$kind]->id, 'permission_code' => 'M08.'.$permission,
            'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('access_grants')->insert(['user_id' => $actors[$kind]->id, 'permission_code' => 'M12.ar_view',
        'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('access_grants')->insert(['user_id' => $actors[$kind]->id, 'permission_code' => 'M02.product_view',
        'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
}
DB::table('access_grants')->insert(['user_id' => $actors['dispatcher']->id, 'permission_code' => 'M03.customer_view',
    'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
DB::table('user_warehouse_scopes')->insert(['user_id' => $actors['dispatcher']->id, 'warehouse_id' => $warehouse->id,
    'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
DB::table('access_grants')->insert(['user_id' => $actors['dispatcher']->id, 'permission_code' => 'M06.balance_view',
    'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
DB::table('user_warehouse_scopes')->insert(['user_id' => $actors['technician']->id, 'warehouse_id' => $warehouse->id,
    'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
DB::table('user_warehouse_scopes')->insert(['user_id' => $actors['reviewer']->id, 'warehouse_id' => $warehouse->id,
    'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
$sourceGrants = [
    'dispatcher' => ['M06.balance_view', 'M06.reservation_manage', 'M06.reservation_view', 'M11.period_reopen'],
    'technician' => ['M06.issue_create', 'M06.issue_post', 'M06.issue_approve', 'M06.movement_view', 'M06.reservation_view', 'M11.source_post', 'M11.journal_post'],
];
foreach ($sourceGrants as $kind => $permissions) {
    foreach ($permissions as $permission) {
        $scope = PermissionCatalog::DEFINITIONS[$permission][1] ?? 'branch';
        DB::table('access_grants')->insertOrIgnore(['user_id' => $actors[$kind]->id, 'permission_code' => $permission,
            'branch_id' => $scope === 'company' ? null : $branch->id,
            'created_at' => now(), 'updated_at' => now()]);
    }
}
if ($periodReopen = DB::table('accounting_periods')->whereDate('starts_on', '<=', now('Africa/Cairo')->toDateString())
    ->whereDate('ends_on', '>=', now('Africa/Cairo')->toDateString())->where('status', 'closed')->first()) {
    app(AccountingActions::class)->reopen((int) $actors['dispatcher']->id, (int) $periodReopen->id, (int) $periodReopen->version,
        'Reopen isolated test period for source-linked M08 browser issue acceptance.');
} elseif (! DB::table('accounting_periods')->whereDate('starts_on', '<=', now('Africa/Cairo')->toDateString())
    ->whereDate('ends_on', '>=', now('Africa/Cairo')->toDateString())->where('status', 'open')->exists()) {
    throw new RuntimeException('M08 browser part issue requires an open owner-authorized accounting period in the isolated clone.');
}
echo json_encode(['database' => $actual->db, 'port' => (int) $actual->port, 'branch_id' => (int) $branch->id,
    'runtime_privileges' => ['role' => $runtimePrivileges->role, 'cost_proposals' => (bool) $runtimePrivileges->cost_proposals,
        'expense_sources' => (bool) $runtimePrivileges->expense_sources, 'labor_recognitions' => (bool) $runtimePrivileges->labor_recognitions,
        'cost_proposal_sequence' => (bool) $runtimePrivileges->cost_proposal_sequence, 'expense_source_sequence' => (bool) $runtimePrivileges->expense_source_sequence],
    'customer_id' => (int) $customer->id, 'customer_code' => $customer->code, 'warehouse_id' => (int) $warehouse->id,
    'product_id' => (int) $product->id,
    'stock_source' => ['branch_id' => (int) $branch->id, 'warehouse_id' => (int) $warehouse->id,
        'product_id' => (int) $product->id, 'on_hand' => (string) $stock->on_hand,
        'fifo_available' => (string) DB::table('inventory_fifo_layers')->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)->sum('remaining_quantity')],
    'actors' => array_map(fn (User $user) => ['id' => (int) $user->id, 'email' => $user->email], $actors)], JSON_THROW_ON_ERROR);
