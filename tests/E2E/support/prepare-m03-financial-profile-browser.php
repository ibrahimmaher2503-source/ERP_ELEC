<?php

use App\Models\User;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Organization\Domain\Branch;
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
$storage = $root.'/.tmp/m03-party-financial-browser-storage';
foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir($storage.'/'.$dir) && ! mkdir($storage.'/'.$dir, 0700, true) && ! is_dir($storage.'/'.$dir)) {
        throw new RuntimeException('Cannot create isolated M03 browser storage.');
    }
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$actual = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
$port = (int) config('database.connections.pgsql.port');
$markerPath = getenv('E2E_CLUSTER_MARKER') ?: '';
$marker = is_file($markerPath) ? json_decode(file_get_contents($markerPath), true) : null;
if (DB::connection()->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql'
    || $actual->db !== 'erp_elec_new_test' || config('database.connections.pgsql.database') !== $actual->db
    || ! in_array($actual->address, ['127.0.0.1', '127.0.0.1/32'], true) || (int) $actual->port !== $port || $port !== 55460
    || ! is_array($marker) || $marker['kind'] !== 'codex-e2e-postgres' || $marker['repo'] !== $root
    || (int) $marker['port'] !== $port || ! hash_equals((string) ($marker['token'] ?? ''), (string) getenv('E2E_CLUSTER_TOKEN'))) {
    throw new RuntimeException('ABORT: M03 browser acceptance requires the marker-owned isolated PostgreSQL 55460 database.');
}
$evidencePath = $root.'/docs/qa/evidence/phase1-financial-journeys.json';
$evidence = is_file($evidencePath) ? json_decode(file_get_contents($evidencePath), true, 512, JSON_THROW_ON_ERROR) : [];
$ids = $evidence['party_financial_profiles'] ?? [];
if (($evidence['all_pass'] ?? false) !== true || ($evidence['database'] ?? null) !== $actual->db
    || ($evidence['postgres_port'] ?? null) !== $port || ! isset($ids['actor_id'], $ids['branch_id'], $ids['customer_id'], $ids['supplier_id'])) {
    throw new RuntimeException('Missing committed real-action M12/M13 financial-profile fixture evidence.');
}
$actor = User::query()->whereKey((int) $ids['actor_id'])->where('active', true)->whereNotNull('email_verified_at')->first();
$branch = DB::table('branches')->find((int) $ids['branch_id']);
if (! $actor || ! $branch || ! DB::table('user_branch_scopes')->where('user_id', $actor->id)->where('branch_id', $branch->id)->exists()
    || ! DB::table('rp_customer_invoices')->where('customer_id', (int) $ids['customer_id'])->where('branch_id', $branch->id)->whereNotNull('posted_at')->exists()
    || ! DB::table('rp_supplier_invoices')->where('supplier_id', (int) $ids['supplier_id'])->where('branch_id', $branch->id)->whereNotNull('posted_at')->exists()) {
    throw new RuntimeException('Committed M03 browser party/action fixture is missing or out of scope.');
}
$grant = static function (int $user, string $code, ?int $branchId): void {
    DB::table('access_grants')->insertOrIgnore(['user_id' => $user, 'permission_code' => $code, 'branch_id' => $branchId,
        'created_at' => now(), 'updated_at' => now()]);
};
foreach (['M03.customer_export', 'M03.supplier_export'] as $permission) {
    $grant((int) $actor->id, $permission, null);
}
foreach (['M12.customer_invoice_view', 'M12.supplier_invoice_view'] as $permission) {
    $grant((int) $actor->id, $permission, (int) $branch->id);
}
$denied = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
$revocable = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
foreach ([$denied, $revocable] as $user) {
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant((int) $user->id, 'M03.customer_view', null);
}
$grant((int) $revocable->id, 'M12.ar_view', (int) $branch->id);
$api = app(ReceivablesPayablesApi::class);
$customerProfile = $api->partyFinancialProfile((int) $actor->id, 'customer', (int) $ids['customer_id'], ['branch' => (int) $branch->id]);
$supplierProfile = $api->partyFinancialProfile((int) $actor->id, 'supplier', (int) $ids['supplier_id'], ['branch' => (int) $branch->id]);
$emptyBranch = Branch::factory()->create(['code' => 'DEMO-EG-M03-EMPTY-'.strtoupper(bin2hex(random_bytes(3)))]);
DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $emptyBranch->id, 'created_at' => now(), 'updated_at' => now()]);
$grant((int) $actor->id, 'M12.ar_view', (int) $emptyBranch->id);
$emptyProfile = $api->partyFinancialProfile((int) $actor->id, 'customer', (int) $ids['customer_id'], ['branch' => (int) $emptyBranch->id]);
if ($emptyProfile['authorized'] !== true || $emptyProfile['invoices']['total'] !== 0 || $emptyProfile['summary']['outstanding'] !== '0.00'
    || $emptyProfile['statement']['rows']['total'] !== 0 || $emptyProfile['movements']['authorized'] !== false) {
    throw new RuntimeException('Valid in-scope empty AR branch should authorize zero financial rows while withholding M13 movements.');
}
echo json_encode(['database' => $actual->db, 'host' => '127.0.0.1', 'port' => $port,
    'actor' => ['id' => (int) $actor->id, 'email' => $actor->email],
    'denied_actor' => ['id' => (int) $denied->id, 'email' => $denied->email],
    'revocable_actor' => ['id' => (int) $revocable->id, 'email' => $revocable->email],
    'branch_id' => (int) $branch->id, 'empty_branch_id' => (int) $emptyBranch->id, 'empty_branch_name' => $emptyBranch->name,
    'customer_id' => (int) $ids['customer_id'], 'supplier_id' => (int) $ids['supplier_id'],
    'empty_branch_profile' => ['authorized' => true, 'invoice_rows' => 0, 'statement_rows' => 0, 'treasury_authorized' => false],
    'expected' => ['customer' => $customerProfile['summary'], 'supplier' => $supplierProfile['summary']]],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
