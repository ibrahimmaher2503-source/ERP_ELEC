<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organization\Application\AssignUserRole;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Products\Domain\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath(dirname(__DIR__, 3).'/.tmp/master-remediation-20261101/ui-private/finance/storage');
$app->make(Kernel::class)->bootstrap();

$target = DB::selectOne("select current_database() as db, current_setting('port') as port, current_setting('data_directory') as data_directory");
$root = realpath(dirname(__DIR__, 3));
$owned = realpath($root.'/.tmp/master-remediation-20261101/ui-pgdata');
$marker = $root.'/.tmp/master-remediation-20261101/ui-private/.codex-ui-owned.txt';
$receipt = $root.'/.tmp/master-remediation-20261101/ui-private/finance/gate2-finance-ui-bootstrap.json';
$saved = is_file($receipt) ? json_decode((string) file_get_contents($receipt), true) : null;
$nonce = (string) getenv('E2E_FINANCE_DB_NONCE');
$database = 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32);
$storedNonce = DB::table('codex_test_guard.ownership_receipts')->where('database', $database)->value('nonce');
if (! preg_match('/^[a-f0-9]{64}$/D', $nonce) || $target->db !== $database || (int) $target->port !== 49686 || ! $owned
    || realpath($target->data_directory) !== $owned || ! is_file($marker)
    || trim((string) file_get_contents($marker)) !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
    || ! is_array($saved) || ($saved['database'] ?? null) !== $database || (int) ($saved['port'] ?? 0) !== 49686
    || ($saved['app_key'] ?? null) !== getenv('APP_KEY') || realpath((string) ($saved['data_directory'] ?? '')) !== $owned
    || ! hash_equals((string) ($saved['ownership_nonce'] ?? ''), $nonce) || ! hash_equals($nonce, (string) $storedNonce)) {
    throw new RuntimeException('Purchasing browser fixture requires the owned nonce-bound finance UI PostgreSQL database.');
}

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($input['preflight'] ?? false) === true) {
    $tag = (string) ($input['tag'] ?? '');
    if (! preg_match('/^[A-F0-9]{12}$/', $tag)
        || DB::table('users')->whereIn('email', ['round2-master-'.$tag.'@example.test', 'round2-bootstrap-'.$tag.'@example.test'])->exists()) {
        throw new RuntimeException('Run tag is invalid or already owned by an existing account.');
    }
    echo json_encode(['owned' => true], JSON_THROW_ON_ERROR);
    exit(0);
}
$tag = (string) ($input['tag'] ?? '');
$actor = (int) ($input['actor_id'] ?? 0);
if (($input['prepare'] ?? false) === true) {
    $password = (string) ($input['password'] ?? '');
    if (! preg_match('/^[A-F0-9]{12}$/', $tag) || strlen($password) < 32) {
        throw new RuntimeException('Invalid synthetic actor input.');
    }
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create();
    }
    $user = User::factory()->create(['email' => 'round2-master-'.$tag.'@example.test', 'password' => Hash::make($password),
        'active' => true, 'email_verified_at' => now(), 'session_version' => 1]);
    $bootstrap = User::factory()->create(['email' => 'round2-bootstrap-'.$tag.'@example.test',
        'password' => Hash::make(bin2hex(random_bytes(32))), 'active' => true, 'email_verified_at' => now(), 'session_version' => 1]);
    DB::table('access_grants')->insert(['user_id' => $bootstrap->id, 'permission_code' => 'M01.security_admin',
        'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    $role = DB::table('roles')->where('code', 'Administrator')->first(['id', 'version']);
    if (! $role) {
        throw new RuntimeException('Administrator role is not registered in the isolated database.');
    }
    app(AssignUserRole::class)->execute($bootstrap, (int) $role->id, $user->id, true, (int) $role->version);
    $branch = Branch::factory()->create(['code' => 'R2-'.$tag.'-BR']);
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $product = Product::factory()->create(['sku' => 'R2-'.$tag.'-P', 'created_by' => $user->id, 'updated_by' => $user->id]);
    echo json_encode(['actor' => ['id' => $user->id, 'email' => $user->email],
        'master' => ['branch_id' => $branch->id, 'product_id' => $product->id, 'product_sku' => $product->sku]], JSON_THROW_ON_ERROR);
    exit(0);
}
if (($input['verify_actor'] ?? false) === true) {
    if (! preg_match('/^[A-F0-9]{12}$/', $tag)) {
        throw new RuntimeException('Invalid actor tag.');
    }
    $user = DB::table('users')->where('id', $actor)->where('email', 'round2-master-'.$tag.'@example.test')->first();
    $role = $user && DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
        ->where('user_roles.user_id', $actor)->where('roles.code', 'Administrator')->exists();
    echo json_encode(['active' => (bool) ($user?->active && $user?->email_verified_at), 'administrator' => (bool) $role], JSON_THROW_ON_ERROR);
    exit(0);
}
if (($input['cleanup'] ?? false) === true) {
    if (! preg_match('/^[A-F0-9]{12}$/', $tag)) {
        throw new RuntimeException('Invalid cleanup tag.');
    }
    DB::transaction(function () use ($tag): void {
        foreach (['round2-master-', 'round2-bootstrap-'] as $prefix) {
            $user = DB::table('users')->where('email', $prefix.$tag.'@example.test')->lockForUpdate()->first();
            if (! $user) {
                continue;
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('users')->where('id', $user->id)->update(['active' => false, 'email_verified_at' => null,
                'session_version' => $user->session_version + 1, 'updated_at' => now()]);
        }
    });
    echo json_encode(['actor_disabled' => true], JSON_THROW_ON_ERROR);
    exit(0);
}
if (! preg_match('/^[A-F0-9]{12}$/', $tag) || ! DB::table('users')->where('id', $actor)->where('active', true)->whereNotNull('email_verified_at')->exists()
    || ! DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $actor)->where('roles.code', 'Administrator')->exists()) {
    throw new RuntimeException('Expected the newly prepared test Administrator actor.');
}
throw new RuntimeException('Expected preflight, prepare, verify_actor, or cleanup mode.');
