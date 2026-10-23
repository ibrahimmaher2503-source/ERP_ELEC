<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\SupplierOffers;
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
if (app()->environment() !== 'testing' || DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test' || DB::connection()->getConfig('host') !== '127.0.0.1' || (string) DB::connection()->getConfig('port') !== '5443') {
    throw new RuntimeException('Standalone RFQ E2E requires APP_ENV=testing and exact PostgreSQL erp_elec_new_test at 127.0.0.1:5443.');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$mode = (string) ($argv[1] ?? 'prepare');
$run = strtoupper((string) ($input['run'] ?? ''));
if (! preg_match('/^[A-F0-9]{12}$/', $run)) {
    throw new RuntimeException('Invalid standalone RFQ E2E run identity.');
}
$email = static fn (string $role): string => 'rfq-e2e-'.$role.'-'.$run.'@example.test';
$name = static fn (string $role): string => 'Synthetic Standalone RFQ E2E '.$role.' '.$run;
if ($mode === 'cleanup') {
    $result = [];
    foreach (['creator', 'reviewer'] as $role) {
        $user = User::query()->where('email', $email($role))->firstOrFail();
        if ($user->name !== $name($role)) {
            throw new RuntimeException('Temporary actor identity does not match cleanup guard.');
        }
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['active' => false, 'email_verified_at' => null, 'session_version' => $user->session_version + 1])->save();
        $result[$role] = ['disabled' => true, 'sessions_revoked' => true];
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}
if ($mode === 'verify-export') {
    $book = (new Xlsx)->load((string) $input['path']);
    $rows = $book->getSheetByName('Data')->toArray();
    $book->disconnectWorksheets();
    if (count($rows) < 2) {
        throw new RuntimeException('Comparison workbook contains no data rows.');
    }
    $row = array_map('strval', $rows[1]);
    $expected = [0 => (string) $input['offer_id'], 2 => (string) $input['supplier_reference'], 3 => 'EGP', 4 => (string) $input['sku'], 5 => (string) $input['product_name'], 6 => '3.12500000', 7 => '3.12500000', 8 => '8.00000000', 10 => '25.00000000'];
    foreach ($expected as $column => $value) {
        if (($row[$column] ?? '') !== $value) {
            throw new RuntimeException('Comparison workbook value mismatch in column '.$column.'.');
        }
    }
    fwrite(STDOUT, json_encode(['rows' => count($rows) - 1, 'verified_columns' => array_keys($expected)], JSON_THROW_ON_ERROR));
    exit(0);
}
if ($mode === 'verify-draft') {
    $id = (int) $input['rfq_id'];
    $rfq = DB::table('purchasing_rfqs')->find($id);
    $line = DB::table('purchasing_rfq_lines')->where('rfq_id', $id)->first();
    if (! $rfq || ! $line || $rfq->source_type !== 'STANDALONE' || $rfq->status !== ($input['status'] ?? 'draft') || $rfq->subject !== $input['subject'] || (int) $rfq->version !== (int) $input['version'] || $line->quantity !== $input['quantity'] || $line->source_quantity !== $input['quantity'] || (int) $line->product_id !== (int) $input['product_id'] || (int) $line->base_unit_id !== (int) $input['base_unit_id'] || $line->sku !== $input['sku'] || $line->product_name !== $input['product_name'] || $line->unit_name !== $input['unit_name']) {
        throw new RuntimeException('Persisted standalone RFQ draft/issue values do not match the browser workflow.');
    }
    fwrite(STDOUT, json_encode(['rfq_id' => $id, 'source_type' => $rfq->source_type, 'status' => $rfq->status, 'version' => (int) $rfq->version, 'subject' => $rfq->subject, 'quantity' => $line->quantity, 'source_quantity' => $line->source_quantity], JSON_THROW_ON_ERROR));
    exit(0);
}
if ($mode === 'verify-offer') {
    $offer = DB::table('purchasing_offers')->find((int) $input['offer_id']);
    $line = DB::table('purchasing_offer_lines')->where('offer_id', (int) $input['offer_id'])->first();
    $source = $line ? DB::table('purchasing_rfq_product_lines')->find($line->rfq_line_id) : null;
    if (! $offer || ! $line || ! $source || (int) $offer->rfq_id !== (int) $input['rfq_id'] || (int) $offer->supplier_id !== (int) $input['supplier_id'] || $offer->status !== 'recorded' || $line->quantity !== $input['quantity'] || (int) $source->product_id !== (int) $input['product_id'] || (int) $source->base_unit_id !== (int) $input['base_unit_id'] || $source->sku !== $input['sku'] || $source->product_name !== $input['product_name'] || $source->unit_name !== $input['unit_name']) {
        throw new RuntimeException('Recorded offer does not preserve its standalone source line.');
    }
    fwrite(STDOUT, json_encode(['offer_id' => (int) $offer->id, 'status' => $offer->status, 'source_product_id' => (int) $source->product_id, 'source_sku' => $source->sku, 'quantity' => $line->quantity], JSON_THROW_ON_ERROR));
    exit(0);
}
if ($mode === 'complete-award') {
    $rfq = DB::table('purchasing_rfqs')->find((int) $input['rfq_id']);
    $offer = DB::table('purchasing_offers')->find((int) $input['offer_id']);
    $creator = User::query()->where('email', $email('creator'))->firstOrFail();
    $reviewer = User::query()->where('email', $email('reviewer'))->firstOrFail();
    if (! $rfq || ! $offer || $rfq->source_type !== 'STANDALONE' || (int) $offer->rfq_id !== (int) $rfq->id || $offer->status !== 'recorded') {
        throw new RuntimeException('Full-award fixture must reference this run’s issued standalone RFQ and recorded offer.');
    }
    $postingCount = DB::table('inventory_movements')->count();
    $awardId = app(SupplierOffers::class)->propose($creator->id, $offer->id, 'DEMO-EG browser acceptance of the complete offer');
    app(SupplierOffers::class)->decide($reviewer->id, $awardId, 1, true);
    $offerLines = DB::table('purchasing_offer_lines')->where('offer_id', $offer->id)->orderBy('id')->get();
    $rfqLines = DB::table('purchasing_rfq_product_lines')->where('rfq_id', $rfq->id)->orderBy('id')->get();
    $poLines = $offerLines->map(fn ($line) => ['product_id' => (int) $rfqLines->firstWhere('id', $line->rfq_line_id)->product_id, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount])->all();
    $orderId = app(PurchaseOrders::class)->save($creator->id, ['creation_key' => (string) Str::uuid(), 'branch_id' => (int) $rfq->branch_id, 'supplier_id' => (int) $offer->supplier_id, 'award_id' => $awardId, 'order_date' => now('Africa/Cairo')->toDateString(), 'expected_date' => null, 'currency' => $offer->currency, 'payment_terms' => $offer->payment_terms, 'notes' => 'DEMO-EG standalone RFQ award conversion', 'reported_tax' => $offer->reported_tax, 'lines' => $poLines], null, 0);
    app(PurchaseOrders::class)->transition($creator->id, $orderId, 1, 'submit');
    app(PurchaseOrders::class)->transition($reviewer->id, $orderId, 2, 'approve');
    $stored = DB::table('purchasing_order_lines')->where('order_id', $orderId)->first();
    $source = $rfqLines->first();
    if (! $stored || (int) $stored->product_id !== (int) $source->product_id || (int) $stored->unit_id !== (int) $source->base_unit_id || $stored->sku !== $source->sku || $stored->product_name !== $source->product_name || $stored->quantity !== '3.12500000' || $stored->unit_price !== '8.00000000' || DB::table('inventory_movements')->count() !== $postingCount) {
        throw new RuntimeException('Standalone award to approved PO mapping failed or created an unexpected inventory posting.');
    }
    fwrite(STDOUT, json_encode(['award_id' => (int) $awardId, 'award_status' => DB::table('purchasing_awards')->where('id', $awardId)->value('status'), 'order_id' => $orderId, 'order_status' => DB::table('purchasing_orders')->where('id', $orderId)->value('status'), 'product_id' => (int) $stored->product_id, 'unit_id' => (int) $stored->unit_id, 'sku' => $stored->sku, 'quantity' => $stored->quantity, 'unit_price' => $stored->unit_price, 'posting_rows' => 0], JSON_THROW_ON_ERROR));
    exit(0);
}
if ($mode !== 'prepare') {
    throw new RuntimeException('Unsupported fixture operation.');
}
$creatorPassword = (string) ($input['creator_password'] ?? '');
$reviewerPassword = (string) ($input['reviewer_password'] ?? '');
if (strlen($creatorPassword) < 32 || strlen($reviewerPassword) < 32) {
    throw new InvalidArgumentException('Temporary E2E actor credentials missing.');
}
DB::beginTransaction();
try {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        DB::table('companies')->insert(['id' => 1, 'code' => 'M05-RFQ-E2E', 'name' => 'Synthetic standalone RFQ test company', 'currency' => 'EGP', 'setup_complete' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    $branch = Branch::factory()->create(['code' => 'DEMO-EG-RFQ-'.$run, 'name' => 'DEMO-EG RFQ branch '.$run]);
    $creator = User::factory()->create(['email' => $email('creator'), 'name' => $name('creator'), 'password' => Hash::make($creatorPassword), 'active' => true, 'email_verified_at' => now(), 'session_version' => 1]);
    $reviewer = User::factory()->create(['email' => $email('reviewer'), 'name' => $name('reviewer'), 'password' => Hash::make($reviewerPassword), 'active' => true, 'email_verified_at' => now(), 'session_version' => 1]);
    foreach ([$creator, $reviewer] as $user) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $permissions = [
        [$creator, ['M01.branch_view', 'M02.product_view', 'M03.supplier_view', 'M05.rfq_create', 'M05.rfq_view', 'M05.rfq_update', 'M05.rfq_issue', 'M05.rfq_dispatch', 'M05.rfq_standalone', 'M05.offer_create', 'M05.offer_view', 'M05.offer_update', 'M05.award_propose', 'M05.comparison_view', 'M05.export', 'M05.order_view', 'M05.order_create', 'M05.order_update', 'M05.order_submit']],
        [$reviewer, ['M01.branch_view', 'M02.product_view', 'M03.supplier_view', 'M05.offer_view', 'M05.award_approve', 'M05.order_view', 'M05.order_approve']],
    ];
    foreach ($permissions as [$user, $codes]) {
        foreach ($codes as $code) {
            if (! DB::table('permission_catalog')->where('code', $code)->exists()) {
                throw new RuntimeException('Required permission is not registered: '.$code);
            }
            DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $code, 'branch_id' => in_array($code, ['M02.product_view', 'M03.supplier_view'], true) ? null : $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $product = Product::factory()->create(['sku' => 'DEMO-EG-RFQ-'.$run, 'name' => 'DEMO-EG Standalone Edge Product '.$run, 'name_en' => 'DEMO-EG Standalone Edge Product '.$run, 'kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $supplier = Supplier::factory()->create(['code' => 'DEMO-EG-RFQ-SUP-'.$run, 'name' => 'DEMO-EG RFQ Supplier '.$run, 'status' => 'active']);
    $unitName = '#'.$product->base_unit_id;
    if (DB::table('user_warehouse_scopes')->where('user_id', $creator->id)->exists() || DB::table('access_grants')->where('user_id', $creator->id)->where('permission_code', 'like', 'M06.%')->exists()) {
        throw new RuntimeException('Standalone RFQ browser actor unexpectedly has warehouse scope or Inventory grants.');
    }
    DB::commit();
    fwrite(STDOUT, json_encode(['run' => $run, 'creator' => ['email' => $creator->email], 'reviewer' => ['email' => $reviewer->email], 'branch_id' => (int) $branch->id, 'branch_name' => $branch->name, 'product_id' => (int) $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'base_unit_id' => (int) $product->base_unit_id, 'unit_name' => (string) $unitName, 'supplier_id' => (int) $supplier->id, 'supplier_name' => $supplier->name, 'request_date' => now('Africa/Cairo')->toDateString(), 'creator_has_warehouse_scope' => false, 'creator_has_inventory_grants' => false], JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    DB::rollBack();
    throw $e;
}
