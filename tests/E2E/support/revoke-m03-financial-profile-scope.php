<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
$app->useStoragePath($root.'/.tmp/m03-party-financial-browser-storage');
$app->make(Kernel::class)->bootstrap();
$actual = DB::selectOne('select current_database() as db, inet_server_port() as port');
$markerPath = getenv('E2E_CLUSTER_MARKER') ?: '';
$marker = is_file($markerPath) ? json_decode(file_get_contents($markerPath), true) : null;
$user = (int) getenv('M03_REVOCABLE_ACTOR_ID');
$branch = (int) getenv('M03_REVOCABLE_BRANCH_ID');
if ($actual->db !== 'erp_elec_new_test' || (int) $actual->port !== 55460 || ! is_array($marker)
    || $marker['kind'] !== 'codex-e2e-postgres' || $marker['repo'] !== $root || (int) $marker['port'] !== 55460
    || ! hash_equals((string) ($marker['token'] ?? ''), (string) getenv('E2E_CLUSTER_TOKEN'))
    || ! DB::table('user_branch_scopes')->where('user_id', $user)->where('branch_id', $branch)->exists()) {
    throw new RuntimeException('ABORT: refusing to revoke anything outside the owned synthetic profile actor/scope.');
}
$deleted = DB::table('access_grants')->where('user_id', $user)->where('permission_code', 'M12.ar_view')->where('branch_id', $branch)->delete();
if ($deleted !== 1) {
    throw new RuntimeException('Expected one synthetic M12 AR grant to be revoked.');
}
echo json_encode(['revoked' => true, 'actor_id' => $user, 'branch_id' => $branch]).PHP_EOL;
