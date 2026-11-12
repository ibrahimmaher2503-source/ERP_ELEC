<?php

declare(strict_types=1);

// Creates one fresh synthetic performance actor in the owned 55461 database.
// Password output is consumed in memory by the login helper and is never logged.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 3);
$port = (int) (getenv('E2E_DB_PORT') ?: 0);
$storage = getenv('E2E_APP_STORAGE') ?: '';
$cluster = $root.'/.tmp/page-speed-audit/postgres-55461';
$markerPath = $cluster.'/codex-owned.json';
if ($port !== 55461 || getenv('E2E_DB_DATABASE') !== 'erp_elec_new_test'
    || ! str_contains(str_replace('\\', '/', $storage), '/.tmp/page-speed-audit/')) {
    throw new RuntimeException('ABORT: performance actor refresh requires isolated PostgreSQL 55461 and private storage.');
}
if (! is_file($markerPath)) {
    throw new RuntimeException('ABORT: isolated PostgreSQL ownership marker is missing.');
}
$marker = json_decode((string) file_get_contents($markerPath), true, 512, JSON_THROW_ON_ERROR);
if (($marker['kind'] ?? null) !== 'codex-e2e-postgres' || ($marker['repo'] ?? null) !== realpath($root)
    || (int) ($marker['port'] ?? 0) !== 55461 || (int) ($marker['version'] ?? 0) !== 1
    || realpath((string) (getenv('E2E_CLUSTER_MARKER') ?: '')) !== realpath($markerPath)
    || strlen((string) (getenv('E2E_CLUSTER_TOKEN') ?: '')) < 32
    || ! hash_equals((string) ($marker['token'] ?? ''), (string) getenv('E2E_CLUSTER_TOKEN'))) {
    throw new RuntimeException('ABORT: performance actor refresh marker does not match this workspace.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '55461', 'DB_DATABASE' => 'erp_elec_new_test'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || (int) DB::selectOne('select current_setting(\'port\') as port')->port !== 55461) {
    throw new RuntimeException('ABORT: connected database identity does not match isolated performance cluster.');
}
$role = DB::table('roles')->where('code', 'Administrator')->first();
if (! $role) {
    throw new RuntimeException('Administrator role is missing from the isolated fixture database.');
}
$password = Str::random(48);
$suffix = strtoupper(Str::random(8));
$user = User::factory()->create(['name' => 'DEMO-EG E2E performance refresh '.$suffix,
    'email' => 'e2e-performance-refresh-'.$suffix.'@example.test', 'password' => Hash::make($password),
    'active' => true, 'email_verified_at' => now()]);
DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
$now = now();
foreach (DB::table('branches')->where('active', true)->pluck('id') as $branchId) {
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $user->id, 'branch_id' => $branchId, 'created_at' => $now, 'updated_at' => $now]);
}
foreach (DB::table('inventory_warehouses')->where('active', true)->get(['id', 'branch_id']) as $warehouse) {
    DB::table('user_warehouse_scopes')->insertOrIgnore(['user_id' => $user->id, 'warehouse_id' => $warehouse->id,
        'branch_id' => $warehouse->branch_id, 'created_at' => $now, 'updated_at' => $now]);
}
echo json_encode(['id' => (int) $user->id, 'email' => $user->email, 'password' => $password], JSON_THROW_ON_ERROR);
