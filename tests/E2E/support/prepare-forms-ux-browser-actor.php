<?php

declare(strict_types=1);

// Synthetic Administrator and minimum company/branch/warehouse scope for isolated read-only page QA.
use App\Models\User;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Organization\Application\SyncAdministratorPermissions;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 3);
$work = $root.'/.tmp/forms-ux-20261102/private';
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$keys = ['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'E2E_DB_PORT', 'E2E_CLUSTER_MARKER', 'E2E_CLUSTER_TOKEN'];
$target = OptimizationRegressionDatabaseGuard::target($root, array_combine($keys, array_map(static fn ($key) => (string) (getenv($key) ?: ''), $keys)));
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'],
    'DB_PORT' => (string) $target['port'], 'DB_DATABASE' => $target['database'], 'DB_USERNAME' => $target['username'],
    'DB_PASSWORD' => '', 'DB_URL' => '',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($work.'/storage');
$app->make(Kernel::class)->bootstrap();
$app['config']->set('database.default', 'pgsql');
$app['config']->set('database.connections.pgsql.driver', 'pgsql');
$app['config']->set('database.connections.pgsql.host', $target['connection_host']);
$app['config']->set('database.connections.pgsql.port', $target['port']);
$app['config']->set('database.connections.pgsql.database', $target['database']);
$app['config']->set('database.connections.pgsql.username', $target['username']);
$app['config']->set('database.connections.pgsql.password', '');
$app['config']->set('database.connections.pgsql.url', null);
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port, current_user, (select pg_get_userbyid(datdba) from pg_database where datname=current_database()) as database_owner, current_setting('server_version_num')::int as server_version_num, current_setting('data_directory') as data_directory");
$receipt = DB::selectOne('select nonce from codex_test_guard.ownership_receipts where database = current_database()');
OptimizationRegressionDatabaseGuard::verify($target, $identity, $receipt);

if (DB::table('companies')->exists() || DB::table('users')->exists() || DB::table('branches')->exists()) {
    throw new RuntimeException('ABORT: fresh synthetic browser identity requires an empty application fixture database.');
}

$tag = strtoupper(Str::random(8));
$password = Str::random(48);
$actor = DB::transaction(function () use ($tag, $password): array {
    $company = Company::factory()->create([
        'code' => 'DEMO-EG-FORMS-'.$tag, 'name' => 'شركة اختبار الواجهات '.$tag,
        'legal_name' => 'شركة اختبار الواجهات القانونية '.$tag, 'address' => 'عنوان تجريبي — بيانات اختبار فقط',
        'setup_complete' => true,
    ]);
    $branch = Branch::factory()->create([
        'code' => 'DEMO-EG-FORMS-'.$tag, 'name' => 'فرع اختبار النماذج '.$tag, 'company_id' => $company->id, 'active' => true,
    ]);
    $user = User::factory()->create([
        'name' => 'DEMO-EG Administrator Forms UX '.$tag, 'email' => 'forms-ux-'.$tag.'@example.test',
        'password' => Hash::make($password), 'active' => true, 'email_verified_at' => now(),
    ]);
    $role = DB::table('roles')->where('code', 'Administrator')->first();
    if (! $role) throw new RuntimeException('The migrated Administrator role is missing.');
    DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    app(SyncAdministratorPermissions::class)->execute();
    $warehouse = app(InventoryActions::class)->saveWarehouse((int) $user->id, [
        'code' => 'DEMO-EG-FORMS-WH-'.$tag, 'name' => 'مستودع اختبار النماذج '.$tag, 'branch_id' => (int) $branch->id, 'active' => true,
    ]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id,
        'created_at' => now(), 'updated_at' => now()]);

    return ['email' => $user->email, 'password' => $password, 'user_id' => (int) $user->id,
        'company_id' => (int) $company->id, 'branch_id' => (int) $branch->id, 'warehouse_id' => (int) $warehouse['id'], 'tag' => $tag];
});

file_put_contents($work.'/actor.json', json_encode($actor, JSON_THROW_ON_ERROR), LOCK_EX);
chmod($work.'/actor.json', 0600);
echo 'READY synthetic Administrator, company, branch, and warehouse fixtures on owned PostgreSQL 55467.'.PHP_EOL;
