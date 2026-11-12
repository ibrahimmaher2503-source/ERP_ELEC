<?php

declare(strict_types=1);

// Private, committed actors for the isolated Gate 2 UI acceptance database.
use App\Models\User;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 2);
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$privateFiles = $taskRoot.'/ui-private';
$markerPath = $privateFiles.'/.codex-ui-owned.txt';
$marker = 'master-remediation-20261101 Gate2 UI private PostgreSQL marker';
$fixturePath = $privateFiles.'/gate2-ui-actors.json';
$dashboardFixturePath = $privateFiles.'/gate2-ui-dashboard-actor.json';
$seederFixturePath = $privateFiles.'/gate2-ui-seeder-actor.json';
$storage = $privateFiles.'/storage';
$port = (int) (getenv('E2E_DB_PORT') ?: 0);
if ($port !== 49686 || (getenv('E2E_DB_DATABASE') ?: '') !== 'erp_elec_new_test' || ! is_dir($privateFiles)
    || ! is_file($markerPath) || trim((string) file_get_contents($markerPath)) !== $marker) {
    throw new RuntimeException('Refusing Gate 2 UI fixture work outside the provisioned private 49686 target.');
}

foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '49686', 'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '',
    'APP_CONFIG_CACHE' => '.tmp/master-remediation-20261101/ui-private/cache/config.php',
    'APP_ROUTES_CACHE' => '.tmp/master-remediation-20261101/ui-private/cache/routes.php',
    'APP_EVENTS_CACHE' => '.tmp/master-remediation-20261101/ui-private/cache/events.php',
    'APP_SERVICES_CACHE' => '.tmp/master-remediation-20261101/ui-private/cache/services.php',
    'APP_PACKAGES_CACHE' => '.tmp/master-remediation-20261101/ui-private/cache/packages.php',
    'APP_VIEW_COMPILED_PATH' => $privateFiles.'/views', 'VIEW_COMPILED_PATH' => $privateFiles.'/views'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 49686, 'database.connections.pgsql.database' => 'erp_elec_new_test',
    'database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => '',
    'database.connections.pgsql.url' => null, 'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user,
    current_setting('server_version_num') as server_version_num,
    (select r.rolname from pg_database d join pg_roles r on r.oid=d.datdba where d.datname=current_database()) as database_owner");
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = OptimizationRegressionDatabaseGuard::target($root, ['APP_ENV' => 'testing', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '49686', 'E2E_DB_PORT' => '49686', 'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'postgres']);
OptimizationRegressionDatabaseGuard::verify($target, $identity);
if ($identity->database_owner !== 'postgres' || (int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000) {
    throw new RuntimeException('Gate 2 UI fixtures require the exact PostgreSQL 17 private database owned by postgres.');
}
$nonce = DB::table('codex_test_guard.ownership_receipts')->where('database', 'erp_elec_new_test')->value('nonce');
if (! is_string($nonce) || ! preg_match('/^[a-f0-9]{64}$/', $nonce)) {
    throw new RuntimeException('Gate 2 UI writes require the in-database ownership nonce.');
}

if (in_array('--prepare-seeder-actor', $argv, true)) {
    $fixture = is_file($seederFixturePath) ? json_decode((string) file_get_contents($seederFixturePath), true, 512, JSON_THROW_ON_ERROR) : null;
    if (is_array($fixture)) {
        $actor = is_array($fixture['actor'] ?? null) ? User::query()->whereKey((int) ($fixture['actor']['id'] ?? 0))
            ->where('email', $fixture['actor']['email'] ?? '')->where('active', true)->whereNotNull('email_verified_at')->first() : null;
        if (! hash_equals((string) ($fixture['ownership_nonce'] ?? ''), $nonce) || ! $actor) {
            throw new RuntimeException('Existing private seeder actor receipt does not match the active verified owned actor.');
        }
        foreach (['M01.security_admin', 'M01.company_update'] as $permission) {
            DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => $permission,
                'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! DB::table('companies')->where('id', 1)->exists()) {
            app(SaveCompany::class)->execute($actor, [
                'code' => 'DEMO-EG-G2UI', 'name' => 'شركة تجريبية لقبول Gate 2', 'legal_name' => null,
                'tax_registration' => null, 'commercial_registration' => null,
                'address' => 'بيانات تجريبية خاصة بالقبول المحلي، لا تمثل عنواناً حقيقياً.', 'phone' => null, 'email' => null,
            ], null);
        }
        echo 'PREPARED seeder actor id='.(int) $fixture['actor']['id'].PHP_EOL;
        exit(0);
    }
    if (User::query()->where('name', 'like', 'DEMO-EG Gate 2 seeder %')->exists()) {
        throw new RuntimeException('Unreceipted Gate 2 seeder actor exists; refusing to adopt or duplicate it.');
    }
    foreach (['users', 'access_grants', 'companies'] as $table) {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            throw new RuntimeException("Missing migrated seeder actor table {$table}.");
        }
    }
    $suffix = Str::upper(Str::random(10));
    $password = Str::random(48);
    $user = DB::transaction(function () use ($suffix, $password): User {
        $user = User::factory()->create(['name' => 'DEMO-EG Gate 2 seeder '.$suffix,
            'email' => strtolower('g2ui-seeder-'.$suffix).'@example.test', 'password' => $password,
            'active' => true, 'email_verified_at' => now()]);
        foreach (['M01.security_admin', 'M01.company_update'] as $permission) {
            DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $permission,
                'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    });
    $fixture = ['database' => 'erp_elec_new_test', 'port' => 49686, 'data_directory' => realpath($target['data_directory']),
        'ownership_nonce' => $nonce, 'actor' => ['id' => (int) $user->id, 'email' => $user->email, 'password' => $password]];
    if (file_put_contents($seederFixturePath, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Could not persist the private seeder actor credential receipt.');
    }
    if (! DB::table('companies')->where('id', 1)->exists()) {
        app(SaveCompany::class)->execute($user, [
            'code' => 'DEMO-EG-G2UI', 'name' => 'شركة تجريبية لقبول Gate 2', 'legal_name' => null,
            'tax_registration' => null, 'commercial_registration' => null,
            'address' => 'بيانات تجريبية خاصة بالقبول المحلي، لا تمثل عنواناً حقيقياً.', 'phone' => null, 'email' => null,
        ], null);
    }
    echo 'PREPARED seeder actor id='.(int) $user->id.PHP_EOL;
    exit(0);
}

if (in_array('--ensure-dashboard-actor', $argv, true)) {
    $fixture = is_file($fixturePath) ? json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR) : null;
    if (! is_array($fixture) || ! hash_equals((string) ($fixture['ownership_nonce'] ?? ''), $nonce)
        || ($fixture['database'] ?? '') !== 'erp_elec_new_test'
        || (int) ($fixture['port'] ?? 0) !== 49686) {
        throw new RuntimeException('Refusing dashboard actor setup without the existing matching private fixture receipt.');
    }
    if (! DB::getSchemaBuilder()->hasTable('roles') || ! DB::getSchemaBuilder()->hasTable('user_roles')) {
        throw new RuntimeException('Dashboard role fixture requires the migrated ordinary-role tables.');
    }
    $branch = Branch::query()->whereKey((int) ($fixture['branch_id'] ?? 0))->where('active', true)->first();
    if (! $branch) {
        throw new RuntimeException('Dashboard actor fixture branch is unavailable; refusing to choose another branch.');
    }
    $role = DB::table('roles')->where('code', 'SalesClerk')->first(['id']);
    if (! $role) {
        throw new RuntimeException('Dashboard acceptance requires the seeded ordinary SalesClerk role.');
    }
    if (is_file($dashboardFixturePath)) {
        $dashboard = json_decode((string) file_get_contents($dashboardFixturePath), true, 512, JSON_THROW_ON_ERROR);
        $actorInfo = is_array($dashboard['actor'] ?? null) ? $dashboard['actor'] : [];
        $actor = User::query()->whereKey((int) ($actorInfo['id'] ?? 0))->where('email', $actorInfo['email'] ?? '')
            ->where('active', true)->whereNotNull('email_verified_at')->first();
        $roleAssigned = $actor && DB::table('user_roles')->where('user_id', $actor->id)->where('role_id', $role->id)->exists();
        $scoped = $actor && DB::table('user_branch_scopes')->where('user_id', $actor->id)->where('branch_id', $branch->id)->exists();
        $grantBranchMatches = true;
        foreach (['M04.sales_order_view', 'M07.project_view', 'M07.tender_view', 'M08.view', 'M09.view', 'M11.report_view', 'M12.ar_view'] as $permission) {
            $grantBranchMatches = $grantBranchMatches && (bool) DB::table('access_grants')->where('user_id', $actorInfo['id'] ?? 0)
                ->where('permission_code', $permission)->where('branch_id', $branch->id)->exists();
        }
        $grantBranchMatches = $grantBranchMatches && (bool) DB::table('access_grants')->where('user_id', $actorInfo['id'] ?? 0)
            ->where('permission_code', 'M13.account_view')->whereNull('branch_id')->exists();
        if (! hash_equals((string) ($dashboard['ownership_nonce'] ?? ''), $nonce)
            || (int) ($dashboard['branch_id'] ?? 0) !== (int) $branch->id || ! $actor || ! $roleAssigned || ! $scoped || ! $grantBranchMatches) {
            throw new RuntimeException('Existing dashboard actor receipt no longer matches its active scoped SalesClerk identity.');
        }
        echo 'READY dashboard actor id='.(int) $actor->id.PHP_EOL;
        exit(0);
    }
    $dashboardPermissions = ['M04.sales_order_view', 'M07.project_view', 'M07.tender_view', 'M08.view', 'M09.view', 'M11.report_view', 'M12.ar_view'];
    $suffix = Str::upper(Str::random(10));
    $password = Str::random(48);
    $actor = DB::transaction(function () use ($dashboardPermissions, $branch, $role, $suffix, $password): User {
        $user = User::factory()->create(['name' => 'DEMO-EG Dashboard SalesClerk '.$suffix,
            'email' => strtolower('g2ui-dashboard-'.$suffix).'@example.test', 'password' => $password,
            'active' => true, 'email_verified_at' => now()]);
        foreach ($dashboardPermissions as $permission) {
            DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $permission,
                'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => 'M13.account_view',
            'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    });
    $receipt = ['database' => 'erp_elec_new_test', 'port' => 49686, 'ownership_nonce' => $nonce,
        'branch_id' => (int) $branch->id, 'role_code' => 'SalesClerk',
        'actor' => ['id' => (int) $actor->id, 'email' => $actor->email, 'password' => $password]];
    if (file_put_contents($dashboardFixturePath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Could not persist the private dashboard actor credential receipt.');
    }
    echo 'READY dashboard actor id='.(int) $actor->id.PHP_EOL;
    exit(0);
}

if (in_array('--prepare-denial-actors', $argv, true)) {
    $fixture = is_file($fixturePath) ? json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR) : null;
    if (! is_array($fixture) || ! hash_equals((string) ($fixture['ownership_nonce'] ?? ''), $nonce)
        || ($fixture['database'] ?? '') !== 'erp_elec_new_test' || (int) ($fixture['port'] ?? 0) !== 49686) {
        throw new RuntimeException('Refusing denial-actor preparation without the matching private fixture receipt.');
    }
    $branchId = (int) ($fixture['branch_id'] ?? 0);
    $branch = Branch::query()->whereKey($branchId)->where('active', true)->first();
    if (! $branch) {
        throw new RuntimeException('Denial-actor fixture branch is unavailable; refusing to select another branch.');
    }

    foreach (['revoked', 'disabled'] as $key) {
        $actorInfo = is_array($fixture['actors'][$key] ?? null) ? $fixture['actors'][$key] : [];
        $actorId = (int) ($actorInfo['id'] ?? 0);
        $email = (string) ($actorInfo['email'] ?? '');
        $tag = 'DEMO-EG Gate 2 '.$key.' ';
        if ($actorId < 1 || ! str_ends_with(strtolower($email), '@example.test')) {
            throw new RuntimeException("Private {$key} denial actor receipt is incomplete.");
        }
        $actor = User::query()->whereKey($actorId)->where('email', $email)->first();
        $scopes = DB::table('user_branch_scopes')->where('user_id', $actorId)->orderBy('branch_id')->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
        if (! $actor || ! str_starts_with($actor->name, $tag)
            || $scopes !== [$branchId]
            || DB::table('user_roles')->where('user_id', $actorId)->exists()) {
            throw new RuntimeException("Refusing to prepare {$key} actor because its exact synthetic identity or branch scope changed.");
        }

        DB::transaction(function () use ($actor, $actorId, $branchId): void {
            if (! $actor->active || $actor->email_verified_at === null) {
                $actor->forceFill(['active' => true, 'email_verified_at' => now()])->save();
            }
            foreach (['M01.branch_view', 'M01.employee_view'] as $permission) {
                DB::table('access_grants')->insertOrIgnore(['user_id' => $actorId, 'permission_code' => $permission,
                    'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $grantRows = DB::table('access_grants')->where('user_id', $actorId)->orderBy('permission_code')->get(['permission_code', 'branch_id']);
        $actualGrants = $grantRows->map(fn ($row) => [$row->permission_code, $row->branch_id === null ? null : (int) $row->branch_id])->all();
        $expectedGrants = [['M01.branch_view', $branchId], ['M01.employee_view', $branchId]];
        usort($actualGrants, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        usort($expectedGrants, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $ready = $actualGrants === $expectedGrants
            && User::query()->whereKey($actorId)->where('email', $email)->where('name', 'like', $tag.'%')
                ->where('active', true)->whereNotNull('email_verified_at')->exists()
            && $scopes === [$branchId];
        if (! $ready) {
            throw new RuntimeException("Could not restore the exact ready state for the synthetic {$key} denial actor.");
        }
    }
    echo 'READY nonce-bound synthetic denial actors'.PHP_EOL;
    exit(0);
}

if (in_array('--revoke', $argv, true) || in_array('--disable', $argv, true)) {
    $fixture = is_file($fixturePath) ? json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR) : null;
    if (! is_array($fixture) || ! hash_equals((string) ($fixture['ownership_nonce'] ?? ''), $nonce)) {
        throw new RuntimeException('Refusing actor state change without the matching private fixture nonce.');
    }
    if (in_array('--revoke', $argv, true)) {
        $actor = User::findOrFail((int) $fixture['actors']['revoked']['id']);
        $deleted = DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', 'M01.employee_view')->where('branch_id', $fixture['branch_id'])->delete();
        if ($deleted !== 1) {
            throw new RuntimeException('Expected exactly one owned employee-view grant to revoke.');
        }
        echo 'REVOKED M01.employee_view'.PHP_EOL;
    } else {
        $actor = User::findOrFail((int) $fixture['actors']['disabled']['id']);
        if (! $actor->active) {
            throw new RuntimeException('Disabled actor was already inactive; refusing ambiguous fixture transition.');
        }
        $actor->forceFill(['active' => false])->save();
        echo 'DISABLED synthetic actor'.PHP_EOL;
    }
    exit(0);
}

if (is_file($fixturePath)) {
    throw new RuntimeException('Private Gate 2 UI actors already exist. Reuse the existing fixture receipt; never overwrite it.');
}
$seederFixture = is_file($seederFixturePath) ? json_decode((string) file_get_contents($seederFixturePath), true, 512, JSON_THROW_ON_ERROR) : null;
if (! is_array($seederFixture) || ! hash_equals((string) ($seederFixture['ownership_nonce'] ?? ''), $nonce)
    || ! is_array($seederFixture['actor'] ?? null)) {
    throw new RuntimeException('Prepare the guarded active seeder actor before creating Gate 2 browser actors.');
}
$seederActor = User::query()->whereKey((int) $seederFixture['actor']['id'])->where('email', $seederFixture['actor']['email'])
    ->where('active', true)->whereNotNull('email_verified_at')->firstOrFail();
$requiredTables = ['branches', 'customers', 'products', 'organization_employees', 'attendance_shifts', 'attendance_schedules', 'attendance_punches', 'service_contracts', 'access_grants', 'user_branch_scopes', 'user_roles', 'roles', 'users'];
foreach ($requiredTables as $table) {
    if (! DB::getSchemaBuilder()->hasTable($table)) {
        throw new RuntimeException("Missing migrated UI acceptance table {$table}.");
    }
}
if (Branch::query()->where('code', 'like', 'DEMO-EG-G2UI-%')->exists()
    || User::query()->where('name', 'like', 'DEMO-EG Gate 2 %')->where('id', '<>', $seederActor->id)->exists()) {
    throw new RuntimeException('Unreceipted Gate 2 UI fixture rows exist; refusing to duplicate or adopt them.');
}
$branch = Branch::factory()->create(['code' => 'DEMO-EG-G2UI-'.Str::upper(Str::random(8)), 'name' => 'فرع اختبار واجهات Gate 2']);
$customer = Customer::query()->where('code', 'like', 'DEMO-EG-CUS-%')->where('status', 'active')->orderBy('id')->first();
$service = Product::query()->where('sku', 'like', 'DEMO-EG-%')->where('kind', 'service')->where('active', true)->orderBy('id')->first();
if (! $customer) {
    throw new RuntimeException('Seed the explicit synthetic Egypt market demo data before preparing M10 UI fixtures.');
}
if (! $service) {
    $service = Product::factory()->create(['sku' => 'DEMO-EG-G2UI-SRV-'.Str::upper(Str::random(8)), 'kind' => 'service', 'tracking' => 'none']);
}

$managerPermissions = ['M01.branch_view', 'M01.employee_view', 'M01.employee_manage', 'M10.contract_view', 'M10.contract_create',
    'M10.contract_edit', 'M10.billing_manage', 'M15.view', 'M15.shift_manage', 'M15.schedule_manage', 'M15.punch_manage'];
$globalManagerPermissions = ['M02.product_view', 'M03.customer_view'];
$viewPermissions = ['M01.branch_view', 'M01.employee_view', 'M10.contract_view', 'M15.view'];
$suffix = Str::upper(Str::random(10));
$createActor = static function (string $key, array $permissions, array $globalPermissions = []) use ($branch, $suffix): array {
    $password = Str::random(48);
    $user = User::factory()->create(['name' => 'DEMO-EG Gate 2 '.$key.' '.$suffix,
        'email' => strtolower('g2ui-'.$key.'-'.$suffix).'@example.test', 'password' => $password,
        'active' => true, 'email_verified_at' => now()]);
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insert(['user_id' => $user->id,
            'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($globalPermissions as $permission) {
        DB::table('access_grants')->insert(['user_id' => $user->id,
            'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);

    return ['id' => (int) $user->id, 'email' => $user->email, 'password' => $password];
};
$actors = ['manager' => ['id' => (int) $seederActor->id, 'email' => $seederActor->email, 'password' => $seederFixture['actor']['password']],
    'reader' => $createActor('reader', $viewPermissions),
    'revoked' => $createActor('revoked', ['M01.branch_view', 'M01.employee_view']),
    'disabled' => $createActor('disabled', ['M01.branch_view', 'M01.employee_view'])];
foreach ($managerPermissions as $permission) {
    DB::table('access_grants')->insert(['user_id' => $seederActor->id,
        'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
}
foreach ($globalManagerPermissions as $permission) {
    DB::table('access_grants')->insert(['user_id' => $seederActor->id,
        'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
}
DB::table('user_branch_scopes')->insert(['user_id' => $seederActor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
$setupGrants = DB::table('access_grants')->where('user_id', $seederActor->id)
    ->whereIn('permission_code', ['M01.security_admin', 'M01.company_update'])->whereNull('branch_id')->delete();
if (! in_array($setupGrants, [0, 2], true)) {
    throw new RuntimeException('Unexpected Gate 2 seeder setup grant state; refusing to continue.');
}
$receipt = ['database' => 'erp_elec_new_test', 'port' => 49686, 'data_directory' => realpath($target['data_directory']),
    'ownership_nonce' => $nonce, 'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id,
    'customer_code' => $customer->code, 'service_product_id' => (int) $service->id,
    'service_product_sku' => $service->sku, 'actors' => $actors];
if (file_put_contents($fixturePath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
    throw new RuntimeException('Could not persist the private Gate 2 UI fixture receipt.');
}
if (is_file($seederFixturePath) && ! unlink($seederFixturePath)) {
    throw new RuntimeException('Could not remove the superseded private seeder credential receipt.');
}
echo 'PREPARED private fixture receipt '.$fixturePath.PHP_EOL;
