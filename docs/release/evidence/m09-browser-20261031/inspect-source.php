<?php

declare(strict_types=1);

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
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

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = Illuminate\Support\Facades\DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if ($db->db !== 'erp_elec_new_test' || (int) $db->port !== 55448 || ! in_array($db->address, ['127.0.0.1', '127.0.0.1/32'], true)) {
    throw new RuntimeException('Read-only source inspection did not reach expected local source database.');
}

echo json_encode([
    'database' => $db->db,
    'port' => (int) $db->port,
    'companies' => Illuminate\Support\Facades\DB::table('companies')->get(['id', 'code', 'name'])->map(fn ($row) => (array) $row)->all(),
    'branches' => Illuminate\Support\Facades\DB::table('branches')->where('active', true)->get(['id', 'company_id', 'code', 'name'])->map(fn ($row) => (array) $row)->all(),
    'customers' => Illuminate\Support\Facades\DB::table('customers')->where('status', 'active')->limit(5)->get(['id', 'code', 'name'])->map(fn ($row) => (array) $row)->all(),
    'service_products' => Illuminate\Support\Facades\DB::table('products')->where('kind', 'service')->where('active', true)->limit(5)->get(['id', 'sku', 'name', 'base_unit_id'])->map(fn ($row) => (array) $row)->all(),
    'stock_products' => Illuminate\Support\Facades\DB::table('inventory_balances as b')->join('inventory_warehouses as w', 'w.id', '=', 'b.warehouse_id')->join('products as p', 'p.id', '=', 'b.product_id')->select('w.branch_id', 'b.warehouse_id', 'b.product_id', 'b.on_hand', 'p.sku', 'p.base_unit_id')->whereRaw('b.on_hand::numeric > 0')->limit(10)->get()->map(fn ($row) => (array) $row)->all(),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
