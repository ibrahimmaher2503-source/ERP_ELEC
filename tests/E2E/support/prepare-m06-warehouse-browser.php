<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$root = dirname(__DIR__, 3);
$private = $root.'/.tmp/master-remediation-20261101/ui-private';
$finance = $private.'/finance';
$cluster = realpath($root.'/.tmp/master-remediation-20261101/ui-pgdata');
$bootstrap = is_file($finance.'/gate2-finance-ui-bootstrap.json') ? json_decode(file_get_contents($finance.'/gate2-finance-ui-bootstrap.json'), true, 512, JSON_THROW_ON_ERROR) : [];
$nonce = (string) getenv('E2E_FINANCE_DB_NONCE');
$database = 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32);
if (getenv('APP_ENV') !== 'testing' || getenv('E2E_DB_PORT') !== '49686' || getenv('DB_PORT') !== '49686'
    || ! preg_match('/^[a-f0-9]{64}$/D', $nonce) || getenv('DB_DATABASE') !== $database || $cluster === false
    || ! is_file($private.'/.codex-ui-owned.txt') || trim(file_get_contents($private.'/.codex-ui-owned.txt')) !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
    || ($bootstrap['database'] ?? null) !== $database || ! hash_equals((string) ($bootstrap['ownership_nonce'] ?? ''), $nonce)
    || (int) ($bootstrap['port'] ?? 0) !== 49686 || realpath((string) ($bootstrap['data_directory'] ?? '')) !== $cluster
    || ($bootstrap['app_key'] ?? null) !== getenv('APP_KEY')) {
    throw new RuntimeException('ABORT: warehouse browser fixture requires the owned Gate2 PostgreSQL cluster.');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($finance.'/storage');
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('select current_database() as db, host(inet_server_addr()) as host, inet_server_port() as port, current_user as db_user');
if (DB::connection()->getDriverName() !== 'pgsql' || $identity->db !== $database || $identity->host !== '127.0.0.1'
    || (int) $identity->port !== 49686 || $identity->db_user !== 'postgres'
    || realpath((string) DB::scalar('show data_directory')) !== $cluster
    || DB::table('codex_test_guard.ownership_receipts')->where('database', $database)->value('nonce') !== $nonce) {
    throw new RuntimeException('ABORT before writes: unexpected PostgreSQL identity.');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? 'prepare';
if ($mode === 'inspect-last-created') {
    $rows = DB::table('inventory_warehouses')->where('code', 'like', 'E2E-WH-%')->where('name', 'مستودع اختبار المتصفح')
        ->orderByDesc('id')->limit(5)->get(['id', 'code', 'branch_id', 'version', 'created_at']);
    echo json_encode(['matching_count' => DB::table('inventory_warehouses')->where('code', 'like', 'E2E-WH-%')->where('name', 'مستودع اختبار المتصفح')->count(), 'latest' => $rows], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === 'cleanup-latest') {
    $actor = User::query()->where('email', 'like', 'm06-warehouse-%@example.test')->where('name', 'Synthetic M06 Warehouse Browser')
        ->where('active', true)->orderByDesc('id')->first();
    if (! $actor || ! preg_match('/^m06-warehouse-[A-F0-9]{10}@example\.test$/D', $actor->email)) {
        throw new RuntimeException('No matching active owned actor; cleanup refused.');
    }
    DB::transaction(function () use ($actor): void {
        DB::table('sessions')->where('user_id', $actor->id)->delete();
        $actor->forceFill(['active' => false, 'email_verified_at' => null, 'session_version' => $actor->session_version + 1])->save();
    });
    echo json_encode(['actor_id' => $actor->id, 'email' => $actor->email, 'actor_disabled' => true], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === 'verify' || $mode === 'cleanup') {
    $email = (string) ($input['email'] ?? '');
    if (! preg_match('/^m06-warehouse-[A-F0-9]{10}@example\.test$/D', $email)) {
        throw new RuntimeException('Refusing to access an unowned actor.');
    }
    $actor = User::query()->where('email', $email)->where('name', 'Synthetic M06 Warehouse Browser')->firstOrFail();
    if ($mode === 'cleanup') {
        DB::table('sessions')->where('user_id', $actor->id)->delete();
        $actor->forceFill(['active' => false, 'email_verified_at' => null, 'session_version' => $actor->session_version + 1])->save();
        echo json_encode(['actor_disabled' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    $row = DB::table('inventory_warehouses')->where('code', (string) ($input['code'] ?? ''))->where('branch_id', (int) ($input['branch_id'] ?? 0))->first();
    echo json_encode(['id' => $row?->id, 'name' => $row?->name, 'address' => $row?->address, 'version' => $row?->version], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode !== 'prepare' || strlen((string) ($input['password'] ?? '')) < 32) {
    throw new InvalidArgumentException('Invalid fixture request.');
}
$tag = strtoupper(bin2hex(random_bytes(5)));
DB::transaction(function () use ($input, $tag): void {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create();
    }
    $branch = Branch::factory()->create(['code' => 'E2E-WH-BR-'.$tag]);
    $actor = User::factory()->create(['email' => 'm06-warehouse-'.$tag.'@example.test', 'name' => 'Synthetic M06 Warehouse Browser', 'password' => Hash::make($input['password']), 'active' => true, 'email_verified_at' => now(), 'session_version' => 1]);
    DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['M01.branch_view', 'M06.warehouse_view', 'M06.warehouse_create', 'M06.warehouse_update'] as $code) {
        if (! DB::table('permission_catalog')->where('code', $code)->exists()) {
            throw new RuntimeException('Missing permission: '.$code);
        }
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $code, 'branch_id' => $code === 'M01.branch_view' ? $branch->id : null, 'created_at' => now(), 'updated_at' => now()]);
    }
    echo json_encode(['email' => $actor->email, 'branch_id' => $branch->id, 'code' => 'E2E-WH-'.$tag], JSON_THROW_ON_ERROR);
});
