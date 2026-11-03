<?php

declare(strict_types=1);

// Builds isolated browser actors and a preview-only receipt on owned PostgreSQL 55462.
use App\Models\User;
use App\Modules\Parties\Domain\Customer;
use App\Modules\System\Application\DataOperations\OperationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 3);
$port = (int) (getenv('E2E_DB_PORT') ?: 0);
$database = (string) (getenv('E2E_DB_DATABASE') ?: '');
$storage = (string) (getenv('E2E_APP_STORAGE') ?: '');
$cluster = $root.'/.tmp/ui-acceptance/postgres-55462';
$markerPath = $cluster.'/codex-owned.json';
$bootstrapPath = $root.'/.tmp/ui-acceptance/bootstrap.json';

if ($port !== 55462 || $database !== 'erp_elec_new_test'
    || realpath($storage) !== realpath($root.'/.tmp/ui-acceptance/storage')
    || ! is_file($markerPath) || ! is_file($bootstrapPath)) {
    throw new RuntimeException('ABORT: browser actors require isolated PostgreSQL 55462.');
}

$marker = json_decode((string) file_get_contents($markerPath), true, 512, JSON_THROW_ON_ERROR);
$bootstrap = json_decode((string) file_get_contents($bootstrapPath), true, 512, JSON_THROW_ON_ERROR);
if (($marker['kind'] ?? null) !== 'codex-ui-acceptance' || ($marker['version'] ?? null) !== 1
    || ($marker['repo'] ?? null) !== realpath($root) || (int) ($marker['port'] ?? 0) !== 55462
    || ($marker['database'] ?? null) !== 'erp_elec_new_test'
    || realpath((string) (getenv('E2E_CLUSTER_MARKER') ?: '')) !== realpath($markerPath)
    || ! hash_equals((string) ($marker['token'] ?? ''), (string) (getenv('E2E_CLUSTER_TOKEN') ?: ''))
    || ($bootstrap['status'] ?? null) !== 'READY' || (int) ($bootstrap['db_port'] ?? 0) !== 55462
    || ($bootstrap['database'] ?? null) !== 'erp_elec_new_test'
    || realpath($root.'/'.(string) ($bootstrap['postgres_data_directory'] ?? '')) !== realpath($cluster)) {
    throw new RuntimeException('ABORT: acceptance marker or bootstrap identity mismatch.');
}

foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55462', 'DB_DATABASE' => 'erp_elec_new_test'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne("select current_database() as name, current_setting('port') as port, current_setting('data_directory') as data_directory");
if ($identity->name !== 'erp_elec_new_test' || (int) $identity->port !== 55462
    || realpath($identity->data_directory) !== realpath($cluster)) {
    throw new RuntimeException('ABORT: database listener or PostgreSQL data directory changed.');
}

$fixtures = DB::transaction(function (): array {
    $suffix = Str::upper(Str::random(10));
    $ordinaryPassword = Str::random(48);
    $ownerPassword = Str::random(48);
    $ordinary = User::factory()->create([
        'name' => 'DEMO-EG مستخدم عادي لاختبار الأمان '.$suffix,
        'email' => 'qa-ordinary-security-'.$suffix.'@example.test',
        'password' => $ordinaryPassword,
        'active' => true,
        'email_verified_at' => now(),
    ]);
    $owner = User::factory()->create([
        'name' => 'DEMO-EG مالك معاينة بيانات العملاء '.$suffix,
        'email' => 'qa-customer-preview-owner-'.$suffix.'@example.test',
        'password' => $ownerPassword,
        'active' => true,
        'email_verified_at' => now(),
    ]);

    $permissions = ['M03.customer_view', 'M03.customer_update', 'M03.customer_bulk_update'];
    sort($permissions, SORT_STRING);
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insert(['user_id' => $owner->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $ordinaryAuthority = [
        'roles' => DB::table('user_roles')->where('user_id', $ordinary->id)->count(),
        'direct_grants' => DB::table('access_grants')->where('user_id', $ordinary->id)->count(),
        'branch_scopes' => DB::table('user_branch_scopes')->where('user_id', $ordinary->id)->count(),
        'warehouse_scopes' => DB::table('user_warehouse_scopes')->where('user_id', $ordinary->id)->count(),
    ];
    $ownerRoles = DB::table('user_roles')->where('user_id', $owner->id)->count();
    $ownerScopes = DB::table('user_branch_scopes')->where('user_id', $owner->id)->count() + DB::table('user_warehouse_scopes')->where('user_id', $owner->id)->count();
    $ownerPermissions = DB::table('access_grants')->where('user_id', $owner->id)->orderBy('permission_code')->pluck('permission_code')->all();
    if (array_sum($ordinaryAuthority) !== 0 || $ownerRoles !== 0 || $ownerScopes !== 0 || $ownerPermissions !== $permissions) {
        throw new RuntimeException('ABORT: acceptance actors do not match their least-privilege fixture contracts.');
    }

    $customer = Customer::factory()->create([
        'code' => 'DEMO-EG-QAOP-'.$suffix,
        'name' => 'عميل اصطناعي لمعاينة استيراد البيانات '.$suffix,
        'name_en' => 'Synthetic preview customer '.$suffix,
        'entity_type' => 'organization',
        'status' => 'active',
        'city' => 'القاهرة',
        'email' => null,
        'phone' => null,
        'mobile' => null,
    ]);
    $run = app(OperationService::class)->previewBulk((int) $owner->id, 'customers', [(int) $customer->id], [
        'city' => ['mode' => 'set', 'value' => 'الجيزة'],
    ]);
    $item = $run->items()->firstOrFail();
    $unchangedCity = $customer->fresh()->city;
    if ($run->entity !== 'customers' || $run->kind !== 'bulk' || $run->mode !== 'update' || $run->status !== 'preview'
        || $run->items()->count() !== 1 || $item->status !== 'valid' || ($item->after_values['city'] ?? null) !== 'الجيزة'
        || $unchangedCity !== 'القاهرة') {
        throw new RuntimeException('ABORT: preview fixture was not valid or changed its synthetic customer.');
    }

    return [
        'ordinary' => ['id' => (int) $ordinary->id, 'email' => $ordinary->email, 'password' => $ordinaryPassword, 'authority_rows' => $ordinaryAuthority],
        'owner' => ['id' => (int) $owner->id, 'email' => $owner->email, 'password' => $ownerPassword, 'permissions' => $ownerPermissions, 'role_count' => $ownerRoles, 'scope_count' => $ownerScopes],
        'customer_code' => $customer->code,
        'customer_city_before' => 'القاهرة',
        'customer_city_after' => 'الجيزة',
        'customer_city_persisted' => $unchangedCity,
        'preview_run_id' => (string) $run->id,
        'preview_item_count' => (int) $run->items()->count(),
        'preview_item_status' => $item->status,
        'preview_executed' => false,
    ];
});

// The browser runner consumes credentials in memory and never writes them to logs or evidence.
echo json_encode($fixtures, JSON_THROW_ON_ERROR);
