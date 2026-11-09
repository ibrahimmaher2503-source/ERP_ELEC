<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Domain\Warehouse;
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
use Illuminate\Support\Str;

$root = dirname(__DIR__, 3);
$private = $root.'/.tmp/master-remediation-20261101/ui-private';
$finance = $private.'/finance';
$cluster = realpath($root.'/.tmp/master-remediation-20261101/ui-pgdata');
$bootstrap = is_file($finance.'/gate2-finance-ui-bootstrap.json') ? json_decode(file_get_contents($finance.'/gate2-finance-ui-bootstrap.json'), true, 512, JSON_THROW_ON_ERROR) : [];
$nonce = (string) getenv('E2E_FINANCE_DB_NONCE');
$database = 'erp_elec_new_finance_ui_'.substr($nonce, 0, 32);
if (getenv('APP_ENV') !== 'testing' || getenv('E2E_DB_PORT') !== '49686' || getenv('DB_PORT') !== '49686'
    || ! preg_match('/^[a-f0-9]{64}$/D', $nonce) || getenv('DB_DATABASE') !== $database || $cluster === false
    || ! is_file($private.'/.codex-ui-owned.txt') || trim(file_get_contents($private.'/.codex-ui-owned.txt')) !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
    || ($bootstrap['database'] ?? null) !== $database || ! hash_equals((string) ($bootstrap['ownership_nonce'] ?? ''), $nonce)
    || (int) ($bootstrap['port'] ?? 0) !== 49686 || realpath((string) ($bootstrap['data_directory'] ?? '')) !== $cluster
    || ($bootstrap['app_key'] ?? null) !== getenv('APP_KEY')) {
    throw new RuntimeException('ABORT: Sales Order browser fixture requires the owned Gate2 finance UI database.');
}

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($finance.'/storage');
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne('select current_database() as db, host(inet_server_addr()) as host, inet_server_port() as port, current_user as db_user');
if (DB::connection()->getDriverName() !== 'pgsql' || $identity->db !== $database || $identity->host !== '127.0.0.1'
    || (int) $identity->port !== 49686 || $identity->db_user !== 'postgres'
    || realpath((string) DB::scalar('show data_directory')) !== $cluster
    || DB::table('codex_test_guard.ownership_receipts')->where('database', $database)->value('nonce') !== $nonce) {
    throw new RuntimeException('ABORT before writes: unexpected Sales Order browser PostgreSQL identity.');
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
$mode = $_SERVER['argv'][1] ?? '';

if ($mode === 'inspect-actors') {
    fwrite(STDOUT, json_encode(User::query()->whereIn('email', [
        'sales-order-e2e-creator@example.test', 'sales-order-e2e-reviewer@example.test',
    ])->orderBy('id')->get(['id', 'email', 'name', 'active'])->all(), JSON_THROW_ON_ERROR));
    exit(0);
}

if ($mode === 'cleanup') {
    $actorIds = array_values(array_filter(array_map('intval', $input['actor_ids'] ?? [])));
    if ($actorIds !== []) {
        DB::transaction(function () use ($actorIds): void {
            DB::table('sessions')->whereIn('user_id', $actorIds)->delete();
            DB::table('access_grants')->whereIn('user_id', $actorIds)->where('permission_code', 'M12.customer_invoice_view')->delete();
            foreach (User::query()->whereIn('id', $actorIds)->whereIn('email', [
                'sales-order-e2e-creator@example.test', 'sales-order-e2e-reviewer@example.test',
            ])->get() as $actor) {
                $actor->forceFill(['active' => false, 'email_verified_at' => null,
                    'password' => Hash::make(bin2hex(random_bytes(32))), 'session_version' => $actor->session_version + 1])->save();
            }
        });
    }
    fwrite(STDOUT, json_encode(['sessions_cleared' => true, 'actors_disabled' => true], JSON_THROW_ON_ERROR));
    exit(0);
}

if ($mode === 'prepare-invoice-tab') {
    $creator = User::query()->where('email', 'sales-order-e2e-creator@example.test')->where('name', 'Synthetic E2E Sales Order Creator')->where('active', true)->firstOrFail();
    $reviewer = User::query()->where('email', 'sales-order-e2e-reviewer@example.test')->where('name', 'Synthetic E2E Sales Order Reviewer')->where('active', true)->firstOrFail();
    $branch = Branch::query()->where('code', 'E2E-SO-CAI')->where('active', true)->firstOrFail();
    $customer = Customer::query()->where('code', 'E2E-SO-CUSTOMER')->where('status', 'active')->firstOrFail();
    $product = Product::query()->where('sku', 'E2E-SO-CABLE-001')->where('active', true)->firstOrFail();
    $unit = Unit::query()->where('code', 'E2E-SO-EACH')->firstOrFail();
    $result = DB::transaction(function () use ($creator, $reviewer, $branch, $customer, $product, $unit): array {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $creator->id, 'permission_code' => 'M12.customer_invoice_view',
            'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $order = SalesOrderFactory::new()->create(['branch_id' => $branch->id,
            'branch_snapshot' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name, 'address' => $branch->address],
            'customer_id' => $customer->id,
            'customer_reference' => 'E2E-INVOICE-TAB-'.Str::upper(Str::random(8)), 'created_by' => $creator->id]);
        $line = SalesOrderLineFactory::new()->create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'unit_id' => $unit->id]);
        $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id]);
        $reservation = DB::table('inventory_reservations')->insertGetId(['idempotency_key' => (string) Str::uuid(),
            'source_module' => 'Sales', 'source_id' => (string) $order->id, 'source_line' => (string) $line->id,
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'requested' => '1.00000000',
            'reserved' => '0.00000000', 'actor_id' => $creator->id, 'created_at' => now(), 'updated_at' => now()]);
        $instruction = DB::table('sales_delivery_instructions')->insertGetId(['order_id' => $order->id,
            'creation_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'reference' => 'TEST-INVOICE-TAB',
            'status' => 'draft', 'created_by' => $creator->id, 'created_at' => now()]);
        $deliveryLine = DB::table('sales_delivery_instruction_lines')->insertGetId(['instruction_id' => $instruction,
            'line_id' => $line->id, 'reservation_id' => $reservation, 'quantity' => '1.00000000', 'issue_key' => (string) Str::uuid()]);
        $tax = DB::table('accounting_tax_rules')->insertGetId(['code' => 'E2E-INV-TAB-'.Str::upper(Str::random(8)),
            'name' => 'قاعدة اختبار مصطنعة', 'transaction_type' => 'sale', 'rate' => '0.00000',
            'starts_on' => '2020-01-01', 'treatment' => 'exempt', 'tax_scale' => 2,
            'legal_reference' => 'Synthetic E2E draft only', 'created_by' => $creator->id]);
        $invoice = DB::table('rp_customer_invoices')->insertGetId(['creation_key' => (string) Str::uuid(),
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name,
            'invoice_date' => now('Africa/Cairo')->toDateString(), 'due_date' => now('Africa/Cairo')->toDateString(),
            'status' => 'draft', 'created_by' => $creator->id]);
        DB::table('rp_customer_invoice_lines')->insert(['invoice_id' => $invoice, 'sales_order_line_id' => $line->id,
            'delivery_line_id' => $deliveryLine, 'product_id' => $product->id, 'description' => 'بند فاتورة اختبار',
            'quantity' => '1.00000000', 'net_amount' => '0.00', 'tax_amount' => '0.00', 'total_amount' => '0.00',
            'tax_rule_id' => $tax, 'tax_snapshot' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR)]);

        return ['creator_id' => $creator->id, 'reviewer_id' => $reviewer->id, 'order_id' => $order->id, 'invoice_id' => $invoice];
    });
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}

if ($mode === 'verify-draft') {
    $order = SalesOrder::query()->with('lines')->findOrFail((int) ($input['order_id'] ?? 0));
    $line = $order->lines->sole();
    if ($order->status !== 'draft' || (int) $order->created_by !== (int) ($input['creator_id'] ?? 0)
        || (int) $order->branch_id !== (int) ($input['branch_id'] ?? 0)
        || (int) $order->customer_id !== (int) ($input['customer_id'] ?? 0)
        || $order->customer_reference !== ($input['customer_reference'] ?? null)
        || $order->project_name !== ($input['project_name'] ?? null)
        || (int) $line->product_id !== (int) ($input['product_id'] ?? 0)
        || (int) $line->unit_id !== (int) ($input['unit_id'] ?? 0)
        || (string) $line->quantity !== '2.50000000'
        || (string) $line->requested_price !== '123.45000000') {
        throw new RuntimeException('The saved draft or its exact decimal line does not match the browser flow.');
    }
    fwrite(STDOUT, json_encode(['status' => $order->status, 'version' => $order->version], JSON_THROW_ON_ERROR));
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
