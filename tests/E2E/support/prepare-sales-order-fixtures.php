<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\Sales\Domain\SalesOrder;
use Database\Factories\BranchFactory;
use Database\Factories\ProductFactory;
use Database\Factories\SalesOrderFactory;
use Database\Factories\SalesOrderLineFactory;
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
    throw new RuntimeException('E2E fixtures require the exact separate PostgreSQL test database.');
}

$requiredTables = [
    'companies', 'branches', 'users', 'permission_catalog', 'access_grants', 'user_roles',
    'customers', 'units_of_measure', 'products', 'sales_orders', 'sales_order_lines', 'sales_order_reviews', 'sessions',
];
foreach ($requiredTables as $table) {
    if (! Schema::hasTable($table)) {
        throw new RuntimeException("Run test database migrations before creating E2E fixtures ({$table}).");
    }
}

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? '';

if ($mode === 'cleanup') {
    $actorIds = array_values(array_filter(array_map('intval', $input['actor_ids'] ?? [])));
    if ($actorIds !== []) {
        DB::table('sessions')->whereIn('user_id', $actorIds)->delete();
    }
    fwrite(STDOUT, json_encode(['sessions_cleared' => true], JSON_THROW_ON_ERROR));
    exit(0);
}

if ($mode === 'verify') {
    $order = SalesOrder::query()->with('lines')->findOrFail((int) ($input['order_id'] ?? 0));
    $reviews = DB::table('sales_order_reviews')
        ->where('sales_order_id', $order->id)
        ->orderBy('id')
        ->get();
    $line = $order->lines->first();
    if ((int) $order->created_by !== (int) ($input['creator_id'] ?? 0)
        || (int) $order->branch_id !== (int) ($input['branch_id'] ?? 0)
        || (int) $order->customer_id !== (int) ($input['customer_id'] ?? 0)
        || $order->customer_reference !== ($input['customer_reference'] ?? null)
        || $order->status !== 'cancelled'
        || $order->lines->count() !== 1
        || $reviews->count() !== 2
        || $line === null
        || (int) $line->product_id !== (int) ($input['product_id'] ?? 0)
        || (int) $line->unit_id !== (int) ($input['unit_id'] ?? 0)
        || (string) $line->quantity !== '2.50000000'
        || (string) $line->requested_price !== '123.45000000') {
        throw new RuntimeException('The persisted order does not match the completed browser workflow.');
    }
    $reviewStatuses = $reviews->pluck('status')->all();
    if ($reviewStatuses !== ['withdrawn', 'rejected']
        || (int) $reviews[0]->requested_by !== (int) ($input['creator_id'] ?? 0)
        || (int) $reviews[0]->decided_by !== (int) ($input['creator_id'] ?? 0)
        || (int) $reviews[1]->requested_by !== (int) ($input['creator_id'] ?? 0)
        || (int) $reviews[1]->decided_by !== (int) ($input['reviewer_id'] ?? 0)
        || trim((string) $reviews[1]->reason) === '') {
        throw new RuntimeException('The persisted review sequence or reviewer attribution is incorrect.');
    }
    fwrite(STDOUT, json_encode([
        'status' => $order->status,
        'review_statuses' => $reviewStatuses,
        'line_quantity' => (string) $line->quantity,
        'line_requested_price' => (string) $line->requested_price,
    ], JSON_THROW_ON_ERROR));
    exit(0);
}

if ($mode !== 'prepare') {
    throw new InvalidArgumentException('Expected prepare or cleanup mode.');
}

$creatorPassword = (string) ($input['creator_password'] ?? '');
$reviewerPassword = (string) ($input['reviewer_password'] ?? '');
if (strlen($creatorPassword) < 32 || strlen($reviewerPassword) < 32 || $creatorPassword === $reviewerPassword) {
    throw new InvalidArgumentException('Temporary E2E passwords must be distinct and at least 32 characters.');
}

DB::transaction(function () use ($creatorPassword, $reviewerPassword): void {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        DB::table('companies')->insert([
            'id' => 1,
            'code' => 'E2E-SO-COMPANY',
            'name' => 'شركة اختبارية لأوامر البيع',
            'currency' => 'EGP',
            'setup_complete' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $branchCode = 'E2E-SO-CAI';
    $branch = Branch::query()->where('code', $branchCode)->first();
    if ($branch === null) {
        $attributes = BranchFactory::new()->raw([
            'company_id' => 1,
            'code' => $branchCode,
            'name' => 'فرع اختبار أوامر البيع - القاهرة',
            'address' => 'عنوان اختباري، القاهرة، مصر',
            'active' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId($attributes);
        $branch = Branch::query()->findOrFail($branchId);
    }
    if ($branch->name !== 'فرع اختبار أوامر البيع - القاهرة' || ! $branch->active || (int) $branch->company_id !== 1) {
        throw new RuntimeException('The reserved E2E branch code is occupied by a different or inactive branch.');
    }

    $makeUser = static function (string $email, string $name, string $password): User {
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $user = User::factory()->create([
                'email' => $email,
                'name' => $name,
                'active' => true,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'gender' => 'male',
                'version' => 1,
                'session_version' => 1,
            ]);
        } else {
            if ($user->name !== $name) {
                throw new RuntimeException('A reserved E2E email is occupied by a different user.');
            }
            $user->forceFill([
                'active' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'password' => Hash::make($password),
            ])->save();
        }

        if (DB::table('user_roles')->where('user_id', $user->id)->exists()
            || DB::table('access_grants')->where('user_id', $user->id)->where('permission_code', 'M01.security_admin')->exists()) {
            throw new RuntimeException('Reserved E2E users must not have roles or security-administration permission.');
        }

        return $user;
    };

    $creator = $makeUser('sales-order-e2e-creator@example.test', 'Synthetic E2E Sales Order Creator', $creatorPassword);
    $reviewer = $makeUser('sales-order-e2e-reviewer@example.test', 'Synthetic E2E Sales Order Reviewer', $reviewerPassword);
    DB::table('sessions')->whereIn('user_id', [$creator->id, $reviewer->id])->delete();

    $customerCode = 'E2E-SO-CUSTOMER';
    $customer = Customer::query()->where('code', $customerCode)->first();
    if ($customer === null) {
        $customer = Customer::factory()->create([
            'code' => $customerCode,
            'name' => 'شركة تجريبية لمشروعات الكهرباء',
            'name_en' => 'Synthetic Electrical Projects Customer',
            'status' => 'active',
            'city' => 'القاهرة',
            'country' => 'مصر',
        ]);
    }
    if ($customer->name !== 'شركة تجريبية لمشروعات الكهرباء' || $customer->status !== 'active') {
        throw new RuntimeException('The reserved E2E customer code is occupied by a different or inactive customer.');
    }

    $unitCode = 'E2E-SO-EACH';
    $unit = Unit::query()->where('code', $unitCode)->first();
    if ($unit === null) {
        $unit = Unit::factory()->create([
            'code' => $unitCode,
            'name' => 'قطعة اختبارية',
            'dimension' => 'count',
            'active' => true,
            'version' => 1,
        ]);
    }
    if ($unit->name !== 'قطعة اختبارية' || $unit->dimension !== 'count' || ! $unit->active) {
        throw new RuntimeException('The reserved E2E unit code is occupied by a different or inactive unit.');
    }

    $sku = 'E2E-SO-CABLE-001';
    $product = Product::query()->where('sku', $sku)->first();
    if ($product === null) {
        $product = ProductFactory::new()->create([
            'sku' => $sku,
            'name' => 'كابل طاقة تجريبي للمشروعات',
            'name_en' => 'Synthetic project power cable',
            'short_description' => 'صنف اصطناعي لاختبار واجهة أوامر البيع فقط.',
            'kind' => 'bulk',
            'tracking' => 'quantity',
            'base_unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sales_unit_id' => $unit->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'active' => true,
        ]);
    }
    if ($product->name !== 'كابل طاقة تجريبي للمشروعات' || ! $product->active || (int) $product->base_unit_id !== (int) $unit->id) {
        throw new RuntimeException('The reserved E2E product SKU is occupied by a different or inactive product.');
    }

    $creatorActions = ['view', 'create', 'edit', 'submit', 'withdraw', 'cancel', 'history', 'export'];
    $reviewerActions = ['view', 'reject', 'history'];
    $grants = [
        [$creator->id, 'M02.product_view', null],
        [$creator->id, 'M03.customer_view', null],
        [$reviewer->id, 'M02.product_view', null],
        [$reviewer->id, 'M03.customer_view', null],
    ];
    foreach ($creatorActions as $action) {
        $grants[] = [$creator->id, 'M04.sales_order_'.$action, $branch->id];
    }
    foreach ($reviewerActions as $action) {
        $grants[] = [$reviewer->id, 'M04.sales_order_'.$action, $branch->id];
    }
    foreach ($grants as [$userId, $permission, $branchId]) {
        if (! array_key_exists($permission, PermissionCatalog::DEFINITIONS)
            || ! DB::table('permission_catalog')->where('code', $permission)->exists()) {
            throw new RuntimeException("Required test permission is not registered: {$permission}");
        }
        DB::table('access_grants')->insertOrIgnore([
            'user_id' => $userId,
            'permission_code' => $permission,
            'branch_id' => $branchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $expectedGrants = [
        (int) $creator->id => [
            ['permission_code' => 'M02.product_view', 'branch_id' => null],
            ['permission_code' => 'M03.customer_view', 'branch_id' => null],
        ],
        (int) $reviewer->id => [
            ['permission_code' => 'M02.product_view', 'branch_id' => null],
            ['permission_code' => 'M03.customer_view', 'branch_id' => null],
        ],
    ];
    foreach ($creatorActions as $action) {
        $expectedGrants[(int) $creator->id][] = ['permission_code' => 'M04.sales_order_'.$action, 'branch_id' => (int) $branch->id];
    }
    foreach ($reviewerActions as $action) {
        $expectedGrants[(int) $reviewer->id][] = ['permission_code' => 'M04.sales_order_'.$action, 'branch_id' => (int) $branch->id];
    }
    foreach ($expectedGrants as $userId => $expected) {
        $actual = DB::table('access_grants')
            ->where('user_id', $userId)
            ->get(['permission_code', 'branch_id'])
            ->map(fn ($grant): array => [
                'permission_code' => $grant->permission_code,
                'branch_id' => $grant->branch_id === null ? null : (int) $grant->branch_id,
            ])
            ->all();
        $sortGrants = static fn (array $left, array $right): int => ($left['permission_code'] <=> $right['permission_code'])
            ?: ((int) ($left['branch_id'] ?? 0) <=> (int) ($right['branch_id'] ?? 0));
        usort($actual, $sortGrants);
        usort($expected, $sortGrants);
        if ($actual !== $expected) {
            throw new RuntimeException('Reserved E2E users must have only their explicitly scoped test permissions.');
        }
    }

    for ($index = 1; $index <= 10; $index++) {
        $reference = sprintf('E2E-SO-PAGE-%03d', $index);
        if (SalesOrder::query()->where('branch_id', $branch->id)->where('customer_reference', $reference)->exists()) {
            continue;
        }

        $order = SalesOrderFactory::new()->create([
            'branch_id' => $branch->id,
            'branch_snapshot' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name, 'address' => $branch->address],
            'customer_id' => $customer->id,
            'customer_snapshot' => ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name, 'entityType' => $customer->entity_type, 'status' => $customer->status],
            'customer_reference' => $reference,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
        SalesOrderLineFactory::new()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'description' => $product->name,
            'quantity' => '1.00000000',
            'requested_price' => null,
        ]);
    }

    $GLOBALS['e2e_fixture_result'] = [
        'creator' => ['id' => (int) $creator->id, 'email' => $creator->email],
        'reviewer' => ['id' => (int) $reviewer->id, 'email' => $reviewer->email],
        'branch' => ['id' => (int) $branch->id, 'code' => $branch->code, 'name' => $branch->name],
        'customer' => ['id' => (int) $customer->id, 'code' => $customer->code, 'name' => $customer->name],
        'product' => ['id' => (int) $product->id, 'sku' => $product->sku, 'name' => $product->name],
        'unit' => ['id' => (int) $unit->id, 'code' => $unit->code, 'name' => $unit->name],
    ];
});

fwrite(STDOUT, json_encode($GLOBALS['e2e_fixture_result'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
