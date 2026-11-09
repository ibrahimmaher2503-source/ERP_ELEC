<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Domain\Warehouse;
use App\Modules\Organization\Application\AssignUserRole;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Purchasing\Application\PurchaseOrders;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($root.'/.tmp/master-remediation-20261101/ui-private/finance/storage');
$app->make(Kernel::class)->bootstrap();

$nonce = (string) getenv('E2E_FINANCE_DB_NONCE');
$bootstrap = json_decode((string) file_get_contents($root.'/.tmp/master-remediation-20261101/ui-private/finance/gate2-finance-ui-bootstrap.json'), true, 512, JSON_THROW_ON_ERROR);
$identity = DB::selectOne("select current_database() as db, current_setting('port') as port, current_setting('data_directory') as directory");
$database = 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32);
if (! preg_match('/^[a-f0-9]{64}$/D', $nonce) || ! hash_equals((string) ($bootstrap['ownership_nonce'] ?? ''), $nonce)
    || $identity->db !== $database || (int) $identity->port !== 49686
    || realpath($identity->directory) !== realpath($root.'/.tmp/master-remediation-20261101/ui-pgdata')
    || trim((string) file_get_contents($root.'/.tmp/master-remediation-20261101/ui-private/.codex-ui-owned.txt')) !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
    || DB::table('codex_test_guard.ownership_receipts')->where('database', $database)->value('nonce') !== $nonce) {
    throw new RuntimeException('Receipt acceptance requires the exact owned Gate2 finance database.');
}

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$tag = (string) ($input['tag'] ?? '');
if (! preg_match('/^[A-F0-9]{12}$/D', $tag)) throw new RuntimeException('Invalid run tag.');
$actor = User::where('email', 'round2-master-'.$tag.'@example.test')->firstOrFail();
$reviewerEmail = 'receipt-review-'.$tag.'@example.test';

if (($input['mode'] ?? '') === 'setup') {
    $branch = DB::table('branches')->where('id', (int) ($input['branch_id'] ?? 0))->where('code', 'R2-'.$tag.'-BR')->first();
    $product = DB::table('products')->where('id', (int) ($input['product_id'] ?? 0))->where('sku', 'R2-'.$tag.'-P')->first();
    $bootstrapActor = User::where('email', 'round2-bootstrap-'.$tag.'@example.test')->firstOrFail();
    if (! $branch || ! $product || User::where('email', $reviewerEmail)->exists()) throw new RuntimeException('Run fixtures are absent or tag was reused.');
    $reviewer = User::factory()->create(['email' => $reviewerEmail, 'active' => true, 'email_verified_at' => now()]);
    $role = DB::table('roles')->where('code', 'Administrator')->first(['id', 'version']);
    app(AssignUserRole::class)->execute($bootstrapActor, $role->id, $reviewer->id, true, $role->version);
    DB::table('user_branch_scopes')->insert(['user_id' => $reviewer->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $warehouse = Warehouse::factory()->create(['code' => 'R2-'.$tag.'-WH', 'branch_id' => $branch->id]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $actor->id, 'warehouse_id' => $warehouse->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $supplier = Supplier::factory()->create(['code' => 'R2-'.$tag.'-SUP', 'status' => 'active']);
    $orders = app(PurchaseOrders::class);
    $order = $orders->save($actor->id, ['creation_key' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $branch->id,
        'supplier_id' => $supplier->id, 'order_date' => now('Africa/Cairo')->toDateString(), 'currency' => 'EGP',
        'lines' => [['product_id' => $product->id, 'quantity' => '2.00000000', 'unit_price' => '10.00000000', 'discount_amount' => '0.00000000']]], null, 0);
    $orders->transition($actor->id, $order, 1, 'submit');
    $orders->transition($reviewer->id, $order, 2, 'approve');
    echo json_encode(['order' => $order, 'warehouse' => $warehouse->id], JSON_THROW_ON_ERROR);
    exit;
}
if (($input['mode'] ?? '') === 'verify') {
    $order = DB::table('purchasing_orders')->where('id', (int) ($input['order'] ?? 0))->where('created_by', $actor->id)->first();
    $receipt = DB::table('purchasing_receipts')->where('id', (int) ($input['receipt'] ?? 0))->where('order_id', $order?->id)->where('created_by', $actor->id)->first();
    if (! $order || $order->status !== 'approved' || ! $receipt || $receipt->status !== 'integration_pending'
        || DB::table('purchasing_receipt_lines')->where('receipt_id', $receipt->id)->count() !== 1
        || $receipt->journal_id !== null) throw new RuntimeException('Receipt persistence or draft-only boundary failed.');
    echo json_encode(['approved_order' => true, 'receipt_pending' => true, 'journal_absent' => true], JSON_THROW_ON_ERROR);
    exit;
}
if (($input['mode'] ?? '') === 'cleanup') {
    DB::table('users')->where('email', $reviewerEmail)->update(['active' => false, 'email_verified_at' => null, 'updated_at' => now()]);
    echo json_encode(['reviewer_disabled' => true], JSON_THROW_ON_ERROR);
    exit;
}
throw new RuntimeException('Unknown receipt acceptance mode.');
