<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Purchasing\Application\PurchaseOrders;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('M05/M06 browser E2E requires the exact separate PostgreSQL test database.');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$mode = (string) ($argv[1] ?? 'prepare');
if ($mode === 'cleanup') {
    $email = (string) ($input['email'] ?? '');
    if (! preg_match('/^m05-m06-[A-F0-9]{10}@example\.test$/', $email)) {
        throw new RuntimeException('Refusing to clean a non-E2E user.');
    }
    $user = User::query()->where('email', $email)->firstOrFail();
    if ($user->name !== 'Synthetic M05 M06 E2E') {
        throw new RuntimeException('E2E user identity does not match cleanup guard.');
    }
    DB::table('sessions')->where('user_id', $user->id)->delete();
    $user->forceFill(['active' => false, 'email_verified_at' => null, 'session_version' => $user->session_version + 1])->save();
    fwrite(STDOUT, json_encode(['temporary_actor_disabled' => true, 'sessions_revoked' => true], JSON_THROW_ON_ERROR));
    exit(0);
}
if ($mode === 'verify') {
    $order = DB::table('purchasing_orders')->where('id', (int) $input['order_id'])->first();
    $orderLine = DB::table('purchasing_order_lines')->where('order_id', (int) $input['order_id'])->first();
    $count = DB::table('inventory_documents')->where('id', (int) $input['count_id'])->first();
    $countLine = DB::table('inventory_document_lines')->where('document_id', (int) $input['count_id'])->first();
    fwrite(STDOUT, json_encode(['order' => ['version' => (int) $order->version, 'status' => $order->status, 'notes' => $order->notes, 'quantity' => $orderLine->quantity], 'count' => ['version' => (int) $count->version, 'status' => $count->status, 'quantity' => $countLine->quantity, 'cutoff' => (int) $count->cutoff, 'recorded' => $countLine->recorded]], JSON_THROW_ON_ERROR));
    exit(0);
}
if ($mode === 'verify-export') {
    $book = (new Xlsx)->load((string) $input['path']);
    $rows = $book->getSheetByName('Data')->toArray();
    $book->disconnectWorksheets();
    $ids = [];
    foreach (array_slice($rows, 1) as $row) {
        if (isset($row[0]) && $row[0] !== '') {
            $ids[] = (int) $row[0];
        }
    }
    fwrite(STDOUT, json_encode(['rows' => count($ids), 'ids' => $ids, 'expected' => (int) $input['order_id']], JSON_THROW_ON_ERROR));
    exit(0);
}
$tag = strtoupper(bin2hex(random_bytes(5)));
$password = (string) ($input['password'] ?? '');
if (strlen($password) < 32) {
    throw new InvalidArgumentException('Temporary E2E password missing.');
}
if (! DB::table('companies')->where('id', 1)->exists()) {
    DB::table('companies')->insert(['id' => 1, 'code' => 'M05M06-E2E', 'name' => 'Synthetic M05 M06 E2E', 'currency' => 'EGP', 'setup_complete' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
}
DB::beginTransaction();
try {
    $branch = Branch::factory()->create(['code' => 'E2E-'.$tag]);
    $user = User::factory()->create(['email' => 'm05-m06-'.$tag.'@example.test', 'name' => 'Synthetic M05 M06 E2E', 'password' => Hash::make($password), 'active' => true, 'email_verified_at' => now(), 'session_version' => 1]);
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['M01.branch_view', 'M05.order_view', 'M05.order_create', 'M05.order_update', 'M05.export', 'M06.count_create', 'M06.count_submit', 'M06.warehouse_create', 'M06.warehouse_view', 'M06.warehouse_update', 'M02.product_view', 'M03.supplier_view'] as $code) {
        if (! DB::table('permission_catalog')->where('code', $code)->exists()) {
            throw new RuntimeException('Required permission not registered: '.$code);
        }
        DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $code, 'branch_id' => in_array($code, ['M02.product_view', 'M03.supplier_view'], true) ? null : $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M06.warehouse_create', 'M06.warehouse_view', 'M06.warehouse_update'] as $code) {
        DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $code, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $product = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $supplier = Supplier::factory()->create(['status' => 'active']);
    $warehouse = app(InventoryActions::class)->saveWarehouse($user->id, ['code' => 'E2E-WH-'.$tag, 'name' => 'Synthetic E2E warehouse '.$tag, 'branch_id' => $branch->id, 'active' => true]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $order = app(PurchaseOrders::class)->save($user->id, ['creation_key' => (string) Str::uuid(), 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'award_id' => null, 'order_date' => now('Africa/Cairo')->toDateString(), 'expected_date' => null, 'currency' => 'EGP', 'reported_tax' => null, 'payment_terms' => 'Synthetic terms', 'notes' => 'Browser initial', 'lines' => [['product_id' => $product->id, 'quantity' => '3.00000000', 'unit_price' => '10.00000000', 'discount_amount' => '0.00000000']]], null, 0);
    $count = app(InventoryDocuments::class)->save($user->id, 'count', ['creation_key' => (string) Str::uuid(), 'warehouse_id' => $warehouse['id'], 'reason' => 'Browser initial count', 'lines' => [['product_id' => $product->id, 'quantity' => '2.00000000']]], null, 0);
    DB::commit();
    fwrite(STDOUT, json_encode(['email' => $user->email, 'branch_id' => (int) $branch->id, 'product_id' => (int) $product->id, 'warehouse_id' => (int) $warehouse['id'], 'order_id' => (int) $order, 'count_id' => (int) $count['id'], 'supplier' => $supplier->name, 'date' => now('Africa/Cairo')->toDateString(), 'tag' => $tag], JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    DB::rollBack();
    throw $e;
}
