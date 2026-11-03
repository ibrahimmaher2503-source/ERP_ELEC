<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

putenv('APP_ENV=local');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env');
$app->make(Kernel::class)->bootstrap();

$db = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || $db->db !== 'erp_elec_new'
    || ! in_array($db->address, ['127.0.0.1', '127.0.0.1/32'], true) || (int) $db->port !== 5443) {
    throw new RuntimeException('ABORT before reads: preservation check requires development PostgreSQL erp_elec_new on 127.0.0.1:5443.');
}

$projects = DB::table('projects')->whereIn('id', [1, 2, 3])->orderBy('id')->get(['id', 'status', 'version'])->map(fn ($row) => [
    'id' => (int) $row->id, 'status' => $row->status, 'version' => (int) $row->version,
])->all();
$activation = DB::table('accounting_activation')->where('id', 1)->first(['go_live_date', 'activated_at']);
$maintenanceRequest = DB::table('maintenance_requests')->where('number', 'DEMO-EG-MR-001')->first(['id', 'status']);
$maintenanceWork = DB::table('maintenance_work_orders')->where('number', 'DEMO-EG-WO-001')->first(['id', 'status']);
$maintenanceVisitCount = $maintenanceWork
    ? DB::table('maintenance_visits')->where('work_order_id', $maintenanceWork->id)->where('status', 'planned')->whereNull('arrival_at')->whereNull('recorded_at')->count()
    : 0;
$counts = [
    'stock_movements' => (int) DB::table('inventory_movements')->count(),
    'posted_journals' => (int) DB::table('accounting_journals')->where('status', 'posted')->count(),
    'customer_invoices' => (int) DB::table('rp_customer_invoices')->count(),
    'supplier_invoices' => (int) DB::table('rp_supplier_invoices')->count(),
    'treasury_movements' => (int) DB::table('treasury_movements')->count(),
    'maintenance_labor_entries' => (int) DB::table('maintenance_labor_entries')->count(),
    'maintenance_part_issues' => (int) DB::table('maintenance_part_issues')->count(),
    'maintenance_completions' => (int) DB::table('maintenance_completions')->count(),
    'direct_m08_user_grants' => (int) DB::table('access_grants')->where('permission_code', 'like', 'M08.%')->count(),
];
$evidence = [
    'result' => 'PASS',
    'read_only' => true,
    'database' => ['name' => $db->db, 'host' => $db->address, 'port' => (int) $db->port],
    'projects_1_2_3' => $projects,
    'accounting_activation' => $activation ? ['go_live_date' => $activation->go_live_date, 'activated_at' => $activation->activated_at] : null,
    'm08_demo' => ['request_status' => $maintenanceRequest?->status, 'work_order_status' => $maintenanceWork?->status,
        'planned_unstarted_visits' => $maintenanceVisitCount],
    'preserved_counts' => $counts,
];
$expectedProjects = [
    ['id' => 1, 'status' => 'draft', 'version' => 1],
    ['id' => 2, 'status' => 'draft', 'version' => 1],
    ['id' => 3, 'status' => 'draft', 'version' => 1],
];
if ($projects !== $expectedProjects || ! $activation || $activation->go_live_date !== null || $activation->activated_at !== null
    || ! $maintenanceRequest || $maintenanceRequest->status !== 'draft' || ! $maintenanceWork || $maintenanceWork->status !== 'draft'
    || $maintenanceVisitCount !== 1 || array_sum($counts) !== 0) {
    $evidence['result'] = 'FAIL';
    $path = $root.'/docs/qa/evidence/phase3-development-preservation.json';
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", LOCK_EX);
    throw new RuntimeException('Development preservation expectations changed: '.json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}
$path = $root.'/docs/qa/evidence/phase3-development-preservation.json';
if (! is_dir(dirname($path))) {
    mkdir(dirname($path), 0775, true);
}
file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", LOCK_EX);
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
