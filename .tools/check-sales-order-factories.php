<?php

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\AuthorizeResult;
use App\Modules\Organization\Application\Contracts\GetBranchRequest;
use App\Modules\Organization\Application\Contracts\GetBranchResult;
use App\Modules\Organization\Application\Contracts\ListAllowedWarehousesRequest;
use App\Modules\Organization\Application\Contracts\ListAllowedWarehousesResult;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomerIdentity;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Application\Contracts\ConvertQuantityRequest;
use App\Modules\Products\Application\Contracts\ConvertQuantityResult;
use App\Modules\Products\Application\Contracts\FindByBarcodeRequest;
use App\Modules\Products\Application\Contracts\FindByBarcodeResult;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\GetProductResult;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ListMastersResult;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderLine;
use App\Modules\Sales\Domain\SalesOrderReview;
use Database\Factories\BranchFactory;
use Database\Factories\CompanyFactory;
use Database\Seeders\SalesOrderDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Sales order factory checks require the exact separate PostgreSQL test database.');
}

foreach (['companies', 'branches', 'sales_orders', 'sales_order_lines', 'sales_order_reviews'] as $table) {
    if (! Schema::hasTable($table)) {
        throw new RuntimeException("Run the test database migrations before checking {$table}.");
    }
}

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
};
$normalizeObjects = static function (array $value) use (&$normalizeObjects): array {
    foreach ($value as &$item) {
        if (is_array($item)) {
            $item = $normalizeObjects($item);
        }
    }
    unset($item);

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
};

DB::beginTransaction();

try {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        DB::table('companies')->insert(CompanyFactory::new()->raw([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    $branchCode = 'DEMO-BR-SO-CHECK-'.Str::upper(Str::random(6));
    $branch = [
        'code' => $branchCode,
        'name' => 'Synthetic Sales Order Check Branch',
        'address' => 'Synthetic test address, Cairo',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $branchId = (int) DB::table('branches')->insertGetId(BranchFactory::new()->raw($branch));

    $customer = Customer::factory()->create([
        'code' => 'DEMO-CUS-SO-CHECK-'.Str::upper(Str::random(6)),
        'name' => 'Synthetic Sales Order Check Customer',
    ]);
    $unit = Unit::factory()->create(['code' => 'SO-CHECK-'.Str::upper(Str::random(8)), 'name' => 'وحدة فحص تجريبية', 'dimension' => 'count']);
    $product = Product::factory()->create([
        'sku' => 'DEMO-SO-CHECK-'.Str::upper(Str::random(6)),
        'name' => 'Synthetic Sales Order Check Product',
        'base_unit_id' => $unit->id,
    ]);

    $order = SalesOrder::factory()->create([
        'branch_id' => $branchId,
        'branch_snapshot' => ['id' => $branchId, 'code' => $branchCode, 'name' => $branch['name'], 'address' => $branch['address']],
        'customer_id' => $customer->id,
        'order_date' => '2026-10-21',
    ]);
    $verify($order->exists && $order->status === 'draft' && $order->number === null && $order->currency === 'EGP'
        && $order->source_type === 'direct' && $order->version === 1 && $order->calculation === []
        && $order->created_by === null && $order->updated_by === null
        && $order->branch_snapshot['id'] === $branchId && $order->customer_snapshot['id'] === $customer->id,
        'Sales order factory persists an unattributed direct draft with branch and customer snapshots');

    $line = SalesOrderLine::factory()->create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'quantity' => '1.25000000',
        'description' => $product->name,
    ]);
    $verify((int) $line->sales_order_id === (int) $order->id && $line->position === 1
        && $line->quantity === '1.25000000' && $line->suggested_price === null && $line->requested_price === null
        && $line->product_snapshot['id'] === $product->id && $line->product_snapshot['unit_id'] === $unit->id
        && $line->product_snapshot['unit_code'] === $unit->code,
        'Sales order line factory persists an exact quantity and matching product/unit snapshot without inventing a price');

    $review = SalesOrderReview::factory()->create(['sales_order_id' => $order->id]);
    $reviewOrder = $order->fresh()->load('lines');
    $expectedSnapshot = app(SalesOrderActions::class)->snapshot($reviewOrder);
    $persistedExpectedSnapshot = json_decode(json_encode($expectedSnapshot, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $verify($review->exists && (int) $review->sales_order_id === (int) $order->id && $review->status === 'pending'
        && $review->target_version === 2 && preg_match('/^[a-f0-9]{64}$/', $review->content_hash) === 1
        && $normalizeObjects($review->snapshot) === $normalizeObjects($persistedExpectedSnapshot)
        && $review->content_hash === hash('sha256', json_encode($expectedSnapshot, JSON_THROW_ON_ERROR))
        && $order->fresh()->status === 'pending_approval' && $order->fresh()->version === 2
        && $review->requested_by === null && $review->decided_by === null && $review->decided_at === null,
        'Sales order review factory persists an unattributed pending fixture matching the submitted snapshot and version without decision history');

    $demoBranch = DB::table('branches')->where('code', 'DEMO-BR-CAI')->first();
    if (! $demoBranch) {
        $demoBranchId = (int) DB::table('branches')->insertGetId(BranchFactory::new()->raw([
            'code' => 'DEMO-BR-CAI',
            'name' => 'Synthetic Cairo Demo Branch',
            'address' => 'Synthetic test address, Cairo',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $demoBranch = DB::table('branches')->where('id', $demoBranchId)->first();
    }
    if (! $demoBranch->active) {
        throw new RuntimeException('The DEMO-BR-CAI test fixture must be active.');
    }

    $demoCustomer = Customer::query()->where('code', 'DEMO-CUS-CAIRO-001')->first();
    if (! $demoCustomer) {
        $demoCustomer = Customer::factory()->create([
            'code' => 'DEMO-CUS-CAIRO-001',
            'name' => 'Synthetic Cairo Demo Customer',
        ]);
    }
    $demoProduct = Product::query()->where('sku', 'DEMO-PROD-001')->first();
    if (! $demoProduct) {
        $demoProduct = Product::factory()->create([
            'sku' => 'DEMO-PROD-001',
            'name' => 'Synthetic Cairo Demo Product',
            'base_unit_id' => $unit->id,
        ]);
    }
    $demoUnit = Unit::query()->findOrFail($demoProduct->base_unit_id);
    $demoBranchIdentity = [
        'id' => (int) $demoBranch->id,
        'code' => (string) $demoBranch->code,
        'name' => (string) $demoBranch->name,
        'address' => $demoBranch->address,
        'active' => true,
    ];
    $demoCustomerIdentity = new CustomerIdentity(
        (int) $demoCustomer->id,
        (string) $demoCustomer->code,
        (string) $demoCustomer->name,
        (string) $demoCustomer->entity_type,
        (string) $demoCustomer->status,
    );
    $demoProductIdentity = [
        'id' => (int) $demoProduct->id,
        'sku' => (string) $demoProduct->sku,
        'name' => (string) $demoProduct->name,
        'short_description' => (string) ($demoProduct->short_description ?? ''),
        'base_unit_id' => (int) $demoProduct->base_unit_id,
        'version' => (int) $demoProduct->version,
    ];
    $demoUnitIdentity = ['id' => (int) $demoUnit->id, 'code' => (string) $demoUnit->code, 'name' => (string) $demoUnit->name];
    $demoActorId = 900001;
    putenv('SALES_ORDER_DEMO_ACTOR_ID='.$demoActorId);
    $_ENV['SALES_ORDER_DEMO_ACTOR_ID'] = (string) $demoActorId;
    $_SERVER['SALES_ORDER_DEMO_ACTOR_ID'] = (string) $demoActorId;

    app()->instance(OrganizationApi::class, new class($demoActorId, $demoBranchIdentity) implements OrganizationApi
    {
        public function __construct(private int $actorId, private array $branch) {}

        public function employeeIdentity(int $actor, int $employee): array
        {
            throw new RuntimeException('Unexpected employee identity lookup in the Sales Order demo fixture.');
        }

        public function employees(int $actor, int $branch, string $search = '', bool $activeOnly = true): array
        {
            throw new RuntimeException('Unexpected employee list lookup in the Sales Order demo fixture.');
        }

        public function employee(int $actor, int $employee, int $branch, string $date): array
        {
            throw new RuntimeException('Unexpected employee lookup in the Sales Order demo fixture.');
        }

        public function financialClosingBranches(int $actor): array
        {
            throw new RuntimeException('Unexpected financial closing lookup in the Sales Order demo fixture.');
        }

        public function projectParticipantNames(int $actor, int $branch, array $users): array
        {
            throw new RuntimeException('Unexpected project participant lookup in the Sales Order demo fixture.');
        }

        public function documentCompany(int $actor): array
        {
            if ($actor !== $this->actorId) {
                throw new RuntimeException('Unexpected Organization test actor.');
            }

            return (array) DB::table('companies')->where('id', 1)->first(['id', 'code', 'name', 'legal_name', 'tax_registration', 'address', 'currency']);
        }

        public function projectScopedBranches(int $actor): array
        {
            if ($actor !== $this->actorId) {
                throw new RuntimeException('Unexpected Organization test actor.');
            }

            return [$this->branch];
        }

        public function projectBranchMember(int $actor, int $branch): bool
        {
            if ($actor !== $this->actorId) {
                throw new RuntimeException('Unexpected Organization test actor.');
            }

            return $branch === (int) $this->branch['id'];
        }

        public function projectAssignees(int $actor, int $branch): array
        {
            if ($actor !== $this->actorId || $branch !== (int) $this->branch['id']) {
                throw new RuntimeException('Unexpected project assignee lookup context.');
            }

            return [];
        }

        public function inventoryScopes(int $actor): array
        {
            return [];
        }

        public function authorizeInventoryLookup(int $actor): void
        {
            if ($actor !== $this->actorId) {
                throw new RuntimeException('Unexpected inventory lookup actor.');
            }
        }

        public function authorize(AuthorizeRequest $request): AuthorizeResult
        {
            if ($request->actorId !== $this->actorId) {
                throw new RuntimeException('Unexpected Organization test actor.');
            }

            return new AuthorizeResult([], [], []);
        }

        public function getBranch(GetBranchRequest $request): GetBranchResult
        {
            return new GetBranchResult($this->branch);
        }

        public function listBranchesForPermission(AuthorizeRequest $request): array
        {
            if ($request->actorId !== $this->actorId || $request->permissionCode !== 'M04.sales_order_create') {
                throw new RuntimeException('Demo seeder did not request the expected scoped branch list.');
            }

            return [$this->branch];
        }

        public function securityRoles(int $actorId): array
        {
            return [];
        }

        public function hasRole(int $actorId, int $roleId): bool
        {
            return false;
        }

        public function listAllowedWarehouses(ListAllowedWarehousesRequest $request): ListAllowedWarehousesResult
        {
            return new ListAllowedWarehousesResult([], 1, 100, 0);
        }
    });
    app()->instance(CustomersApi::class, new class($demoActorId, $demoCustomerIdentity) implements CustomersApi
    {
        public function __construct(private int $actorId, private CustomerIdentity $customer) {}

        public function maintenanceContext(int $actor, int $customer, ?int $site = null, ?int $contact = null): array
        {
            if ($actor !== $this->actorId || $customer !== $this->customer->id || $site !== null || $contact !== null) {
                throw new RuntimeException('Unexpected maintenance context lookup in the Sales Order demo fixture.');
            }

            return ['sites' => [], 'contacts' => []];
        }

        public function getCustomer(GetCustomerRequest $request): CustomerIdentity
        {
            return $this->customer;
        }

        public function commercialProfile(int $actor, int $customer): array
        {
            if ($actor !== $this->actorId || $customer !== $this->customer->id) {
                throw new RuntimeException('Unexpected customer commercial profile lookup in the Sales Order demo fixture.');
            }

            return ['credit_limit' => null, 'payment_terms' => 0];
        }

        public function searchCustomers(SearchCustomersRequest $request): array
        {
            if ($request->actorId !== $this->actorId || $request->search !== 'DEMO-CUS-CAIRO-001') {
                throw new RuntimeException('Demo seeder did not request the expected customer through M03.');
            }

            return [$this->customer];
        }
    });
    app()->instance(ProductsApi::class, new class($demoActorId, $demoProductIdentity, $demoUnitIdentity) implements ProductsApi
    {
        public function __construct(private int $actorId, private array $product, private array $unit) {}

        public function inventoryProduct(int $actor, int $product): array
        {
            if ($actor !== $this->actorId || $product !== $this->product['id']) {
                throw new RuntimeException('Unexpected inventory product lookup.');
            }

            return $this->product + ['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true, 'unit_name' => $this->unit['name'], 'category_name' => ''];
        }

        public function inventoryProductBySku(int $actor, string $sku): array
        {
            if ($actor !== $this->actorId || $sku !== $this->product['sku']) {
                throw new RuntimeException('Unexpected inventory product SKU lookup.');
            }

            return $this->inventoryProduct($actor, $this->product['id']);
        }

        public function inventoryProducts(int $actor, string $search = ''): array
        {
            if ($actor !== $this->actorId || ($search !== '' && ! str_contains(mb_strtolower($this->product['name']), mb_strtolower($search)))) {
                throw new RuntimeException('Unexpected inventory product list lookup.');
            }

            return [$this->inventoryProduct($actor, $this->product['id'])];
        }

        public function getProduct(GetProductRequest $request): GetProductResult
        {
            if ($request->actorId !== $this->actorId || $request->productId !== $this->product['id']) {
                throw new RuntimeException('Demo seeder did not request the expected product through M02.');
            }

            return new GetProductResult($this->product);
        }

        public function listMasters(ListMastersRequest $request): ListMastersResult
        {
            if ($request->actorId !== $this->actorId) {
                throw new RuntimeException('Unexpected Products test actor.');
            }

            $items = match ($request->entityType) {
                'products' => [$this->product],
                'units' => [$this->unit],
                default => [],
            };

            return new ListMastersResult($items, count($items), $request->page, $request->pageSize);
        }

        public function findByBarcode(FindByBarcodeRequest $request): FindByBarcodeResult
        {
            throw new RuntimeException('Unexpected barcode lookup in the demo fixture.');
        }

        public function convertQuantity(ConvertQuantityRequest $request): ConvertQuantityResult
        {
            throw new RuntimeException('Unexpected quantity conversion in the demo fixture.');
        }
    });

    $demoDraftKey = Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-elec-new:demo:sales-order:draft:001')->toString();
    $beforeDemoOrderCount = DB::table('sales_orders')->where('draft_key', $demoDraftKey)->count();
    $beforeDemoOrder = DB::table('sales_orders')->where('draft_key', $demoDraftKey)->first();
    $beforeDemoLineCount = $beforeDemoOrder
        ? DB::table('sales_order_lines')->where('sales_order_id', $beforeDemoOrder->id)->count()
        : 0;
    $beforeDemoReviewCount = $beforeDemoOrder
        ? DB::table('sales_order_reviews')->where('sales_order_id', $beforeDemoOrder->id)->count()
        : 0;
    if (DB::table('sales_orders')->where('customer_reference', 'DEMO-SO-001')->where('draft_key', '<>', $demoDraftKey)->exists()) {
        throw new RuntimeException('DEMO-SO-001 is already owned by a different test order.');
    }

    app(SalesOrderDemoSeeder::class)->run();
    $afterFirstRun = DB::table('sales_orders')->where('draft_key', $demoDraftKey)->first();
    $afterFirstLineCount = $afterFirstRun
        ? DB::table('sales_order_lines')->where('sales_order_id', $afterFirstRun->id)->count()
        : 0;
    $afterFirstReviewCount = $afterFirstRun
        ? DB::table('sales_order_reviews')->where('sales_order_id', $afterFirstRun->id)->count()
        : 0;
    app(SalesOrderDemoSeeder::class)->run();
    $afterSecondRun = DB::table('sales_orders')->where('draft_key', $demoDraftKey)->first();
    $afterSecondLineCount = $afterSecondRun
        ? DB::table('sales_order_lines')->where('sales_order_id', $afterSecondRun->id)->count()
        : 0;
    $afterSecondReviewCount = $afterSecondRun
        ? DB::table('sales_order_reviews')->where('sales_order_id', $afterSecondRun->id)->count()
        : 0;
    $demoLine = $afterSecondRun
        ? DB::table('sales_order_lines')->where('sales_order_id', $afterSecondRun->id)->first()
        : null;
    $verify($afterFirstRun && $afterSecondRun && DB::table('sales_orders')->where('draft_key', $demoDraftKey)->count() === max(1, $beforeDemoOrderCount)
        && $afterFirstRun->customer_reference === 'DEMO-SO-001' && $afterFirstRun->status === 'draft' && $afterFirstRun->number === null
        && $afterFirstLineCount === max(1, $beforeDemoLineCount) && $afterFirstReviewCount === $beforeDemoReviewCount
        && $afterSecondLineCount === $afterFirstLineCount && $afterSecondReviewCount === $afterFirstReviewCount
        && $demoLine && $demoLine->quantity === '1.00000000' && $demoLine->suggested_price === null && $demoLine->requested_price === null,
        'Sales order demo seeder is repeat-safe, creates a draft-only order and null-priced line, and fabricates no review history');
} finally {
    DB::rollBack();
}
