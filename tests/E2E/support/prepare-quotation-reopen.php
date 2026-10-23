<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationLine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Quotation E2E requires the exact separate PostgreSQL test database.');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$mode = (string) ($argv[1] ?? 'prepare');
if ($mode === 'verify') {
    $quotation = Quotation::query()->with('lines')->findOrFail((int) ($input['id'] ?? 0));
    fwrite(STDOUT, json_encode([
        'id' => (int) $quotation->id,
        'status' => $quotation->status,
        'version' => (int) $quotation->version,
        'valid_until' => $quotation->valid_until?->toDateString(),
        'notes' => (string) $quotation->notes,
        'project_name' => (string) $quotation->project_name,
        'discount_mode' => (string) $quotation->discount_mode,
        'discount_value' => (string) $quotation->discount_value,
        'payment_terms' => (string) $quotation->payment_terms,
        'delivery_terms' => (string) $quotation->delivery_terms,
        'line_count' => $quotation->lines->count(),
        'lines' => $quotation->lines->map(fn ($line) => [
            'product_id' => (int) $line->product_id,
            'unit_id' => (int) $line->unit_id,
            'sku' => (string) $line->product_snapshot['sku'],
            'quantity' => (string) $line->quantity,
            'requested_price' => (string) $line->requested_price,
            'discount_mode' => (string) $line->discount_mode,
            'discount_value' => (string) $line->discount_value,
            'description' => (string) $line->description,
        ])->values()->all(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
}
$tag = strtoupper(bin2hex(random_bytes(4)));
$email = 'round2-quotation-'.$tag.'@example.test';
$name = 'Synthetic Round2 Quotation E2E '.$tag;
$password = (string) ($input['password'] ?? '');
if (strlen($password) < 32) {
    throw new InvalidArgumentException('A temporary E2E password is required.');
}
DB::beginTransaction();
try {
    $unit = Unit::factory()->create(['code' => 'R2-QE-UOM-'.$tag]);
    $unitTwo = Unit::factory()->create(['code' => 'R2-QE-UOM2-'.$tag]);
    $customer = Customer::factory()->create(['code' => 'R2-QE-CUSTOMER-'.$tag, 'name' => 'Synthetic Round2 Customer '.$tag]);
    $product = Product::factory()->create(['sku' => 'R2-QE-PRODUCT-'.$tag, 'base_unit_id' => $unit->id]);
    $productTwo = Product::factory()->create(['sku' => 'R2-QE-PRODUCT2-'.$tag, 'base_unit_id' => $unitTwo->id]);
    $user = User::factory()->create([
        'email' => $email, 'name' => $name, 'password' => Hash::make($password),
        'active' => true, 'email_verified_at' => now(), 'session_version' => 1,
    ]);
    foreach (['M04.quotation_edit', 'M04.quotation_create', 'M04.quotation_view', 'M02.product_view', 'M03.customer_view'] as $permission) {
        if (! DB::table('permission_catalog')->where('code', $permission)->exists()) {
            throw new RuntimeException('Required registered E2E permission is unavailable.');
        }
        DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $quotation = Quotation::factory()->create([
        'customer_id' => $customer->id,
        'customer_snapshot' => ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name, 'entityType' => $customer->entity_type, 'status' => $customer->status],
        'quotation_date' => now('Africa/Cairo')->toDateString(),
        'valid_until' => null,
        'notes' => 'Synthetic Round2 reopen fixture.',
    ]);
    $line = QuotationLine::factory()->create([
        'quotation_id' => $quotation->id, 'product_id' => $product->id, 'unit_id' => $unit->id,
        'quantity' => '2.50000000', 'requested_price' => '123.45000000',
    ]);
    $result = [
        'id' => (int) $quotation->id, 'version' => (int) $quotation->version, 'line_id' => (int) $line->id,
        'email' => $email, 'customer_id' => (int) $customer->id,
        'product_id' => (int) $product->id, 'unit_id' => (int) $unit->id,
        'product_two_id' => (int) $productTwo->id, 'unit_two_id' => (int) $unitTwo->id,
        'run_tag' => $tag,
    ];
    DB::commit();
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    DB::rollBack();
    throw $exception;
}
