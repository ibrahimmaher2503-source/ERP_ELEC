<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organization\Application\AssignUserRole;
use App\Modules\Products\Domain\Unit;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Round2 master fixtures require exact PostgreSQL erp_elec_new_test.');
}
foreach (['users', 'roles', 'user_roles', 'access_audits', 'permission_catalog', 'access_grants', 'units_of_measure'] as $table) {
    if (! Schema::hasTable($table)) {
        throw new RuntimeException("Run test database migrations first ({$table}).");
    }
}

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? '';
if ($mode === 'verify-user') {
    $name = DB::table('users')->where('email', $input['email'])->value('name');
    if ($name !== $input['name']) {
        throw new RuntimeException('The managed user name did not match the final browser save (persisted: '.($name ?? 'missing').').');
    }
    fwrite(STDOUT, json_encode(['persisted_name' => $name], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    exit(0);
}
if ($mode === 'verify') {
    $actual = [
        'product' => DB::table('products')->where('sku', $input['sku'])->value('name'),
        'product_revision' => DB::table('products')->where('sku', $input['sku'])->value('name_en'),
        'customer' => DB::table('customers')->where('code', $input['customer_code'])->value('name'),
        'customer_revision' => DB::table('customers')->where('code', $input['customer_code'])->value('name_en'),
        'supplier' => DB::table('suppliers')->where('code', $input['supplier_code'])->value('name'),
        'supplier_revision' => DB::table('suppliers')->where('code', $input['supplier_code'])->value('name_en'),
        'branch' => DB::table('branches')->where('code', $input['branch_code'])->value('name'),
        'managed_user' => DB::table('users')->where('email', $input['managed_user_email'])->value('name'),
        'company' => (array) DB::table('companies')->where('id', 1)->first(['name', 'code', 'legal_name']),
    ];
    if ($actual !== $input['expected']) {
        fwrite(STDERR, json_encode(['error' => 'Persisted master data did not match the final browser saves.', 'expected' => $input['expected'], 'actual' => $actual], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, json_encode(['persisted' => $actual], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    exit(0);
}
if ($mode !== 'prepare') {
    throw new InvalidArgumentException('Expected prepare or verify mode.');
}
$tag = strtoupper((string) ($input['tag'] ?? ''));
$password = (string) ($input['password'] ?? '');
if (! preg_match('/^[A-F0-9]{12}$/', $tag) || strlen($password) < 32) {
    throw new InvalidArgumentException('Invalid isolated run tag or temporary password.');
}

$unit = Unit::query()->where('active', true)->orderBy('id')->first();
if (! $unit) {
    $unit = Unit::factory()->create();
}
$email = 'round2-master-'.$tag.'@example.test';
$user = User::query()->where('email', $email)->first();
if ($user) {
    throw new RuntimeException('Reserved per-run E2E identity unexpectedly already exists.');
}
$user = User::factory()->create([
    'email' => $email,
    'name' => 'Round2 synthetic master-data operator '.$tag,
    'active' => true,
    'email_verified_at' => now(),
    'password' => Hash::make($password),
    'gender' => 'male',
    'version' => 1,
    'session_version' => 1,
]);

// This script is pinned to the isolated test DB above. A one-purpose bootstrap
// actor supplies the initial security authority; the E2E operator's Administrator
// membership itself is assigned and audited through Organization's real action.
$bootstrap = User::factory()->create([
    'email' => 'round2-bootstrap-'.$tag.'@example.test',
    'name' => 'Round2 isolated security bootstrap '.$tag,
    'active' => true,
    'email_verified_at' => now(),
    'password' => Hash::make(bin2hex(random_bytes(32))),
    'gender' => 'male',
    'version' => 1,
    'session_version' => 1,
]);
DB::table('access_grants')->insert([
    'user_id' => $bootstrap->id,
    'permission_code' => 'M01.security_admin',
    'branch_id' => null,
    'created_at' => now(),
    'updated_at' => now(),
]);
$administrator = DB::table('roles')->where('code', 'Administrator')->first(['id', 'version']);
if (! $administrator || ! DB::table('role_permissions')->where('role_id', $administrator->id)->where('permission_code', 'M01.security_admin')->exists()) {
    throw new RuntimeException('The isolated test database is missing the registered Administrator security capability.');
}
app(AssignUserRole::class)->execute($bootstrap, (int) $administrator->id, (int) $user->id, true, (int) $administrator->version);
$company = DB::table('companies')->where('id', 1)->first(['name', 'code', 'legal_name']);
fwrite(STDOUT, json_encode([
    'actor' => ['id' => (int) $user->id, 'email' => $email],
    'unit' => ['id' => (int) $unit->id, 'name' => $unit->name],
    'company' => $company ? ['name' => $company->name, 'code' => $company->code, 'legal_name' => $company->legal_name] : null,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
