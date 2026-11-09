<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organization\Domain\Company;
use App\Modules\Products\Domain\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 3);
$private = $root.'/.tmp/master-remediation-20261101/ui-private';
$finance = $private.'/finance';
$cluster = realpath($root.'/.tmp/master-remediation-20261101/ui-pgdata');
$markerPath = $private.'/.codex-ui-owned.txt';
$bootstrapPath = $finance.'/gate2-finance-ui-bootstrap.json';
$bootstrap = is_file($bootstrapPath) ? json_decode(file_get_contents($bootstrapPath), true, 512, JSON_THROW_ON_ERROR) : [];
$nonce = (string) getenv('E2E_FINANCE_DB_NONCE');
$database = 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32);
if (getenv('APP_ENV') !== 'testing' || getenv('E2E_DB_PORT') !== '49686' || getenv('DB_PORT') !== '49686'
    || ! preg_match('/^[a-f0-9]{64}$/D', $nonce) || getenv('DB_DATABASE') !== $database || $cluster === false
    || ! is_file($markerPath) || trim(file_get_contents($markerPath)) !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
    || ($bootstrap['database'] ?? null) !== $database || ! hash_equals((string) ($bootstrap['ownership_nonce'] ?? ''), $nonce)
    || (int) ($bootstrap['port'] ?? 0) !== 49686 || realpath((string) ($bootstrap['data_directory'] ?? '')) !== $cluster
    || ($bootstrap['app_key'] ?? null) !== getenv('APP_KEY') || realpath($finance) === false) {
    throw new RuntimeException('ABORT: product price browser fixture requires the owned Gate2 PostgreSQL cluster.');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($finance.'/storage');
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('select current_database() as db, host(inet_server_addr()) as host, inet_server_port() as port, current_user as db_user');
if (DB::connection()->getDriverName() !== 'pgsql' || $identity->db !== $database
    || $identity->host !== '127.0.0.1' || (int) $identity->port !== 49686 || $identity->db_user !== 'postgres'
    || realpath((string) DB::scalar('show data_directory')) !== $cluster
    || DB::table('codex_test_guard.ownership_receipts')->where('database', $database)->value('nonce') !== $nonce) {
    throw new RuntimeException('ABORT before writes: unexpected PostgreSQL identity.');
}

$mode = $argv[1] ?? '';
if ($mode === 'cleanup') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    DB::table('sessions')->whereIn('user_id', [(int) $input['maker_id'], (int) $input['reviewer_id']])->delete();
    echo json_encode(['sessions_cleared' => true], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === 'verify') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $product = Product::findOrFail((int) $input['product_id']);
    $rows = DB::table('product_sale_prices')->where('product_id', $product->id)->orderBy('id')->get();
    if ($rows->count() !== 1 || $rows[0]->status !== 'approved' || (int) $rows[0]->approved_by !== (int) $input['reviewer_id']
        || (int) $rows[0]->created_by !== (int) $input['maker_id'] || (string) $rows[0]->amount !== '123.45000000') {
        throw new RuntimeException('Product price approval did not persist with independent attribution.');
    }
    echo json_encode(['product_id' => $product->id, 'price_status' => $rows[0]->status], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode !== 'prepare') {
    throw new RuntimeException('Expected prepare or verify.');
}
if (! DB::table('companies')->where('id', 1)->exists()) {
    Company::factory()->create();
}
$nonce = Str::lower(Str::random(16));
$password = bin2hex(random_bytes(18));
$maker = User::factory()->create(['email' => "product-maker-{$nonce}@example.test", 'password' => Hash::make($password), 'active' => true, 'email_verified_at' => now()]);
$reviewer = User::factory()->create(['email' => "product-reviewer-{$nonce}@example.test", 'password' => Hash::make($password), 'active' => true, 'email_verified_at' => now()]);
foreach ([[$maker, ['M02.product_view', 'M02.price_view', 'M02.price_manage']], [$reviewer, ['M02.product_view', 'M02.price_view', 'M02.price_approve']]] as [$actor, $permissions]) {
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
$product = Product::factory()->create(['created_by' => $maker->id, 'updated_by' => $maker->id, 'active' => true]);
echo json_encode(['product_id' => $product->id, 'maker' => ['id' => $maker->id, 'email' => $maker->email, 'password' => $password], 'reviewer' => ['id' => $reviewer->id, 'email' => $reviewer->email, 'password' => $password]], JSON_THROW_ON_ERROR);
