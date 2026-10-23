<?php

namespace Database\Seeders;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class SalesOrderDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->assertSafeTarget();

        $actorId = (int) env('SALES_ORDER_DEMO_ACTOR_ID', 0);
        if ($actorId < 1) {
            throw new RuntimeException('Set SALES_ORDER_DEMO_ACTOR_ID to an existing active, verified account with the required M01, M02, M03, and M04 access. No access is granted by this seeder.');
        }

        $branches = app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actorId, 'M04.sales_order_create'));
        $branch = collect($branches)->firstWhere('code', 'DEMO-BR-CAI');
        if ($branch === null) {
            throw new RuntimeException('Sales order demo seeding requires an active, visible DEMO-BR-CAI branch; run the Organization demo seeder and check the actor branch scope.');
        }

        $customer = collect(app(CustomersApi::class)->searchCustomers(new SearchCustomersRequest($actorId, 'DEMO-CUS-CAIRO-001', 50)))
            ->firstWhere('code', 'DEMO-CUS-CAIRO-001');
        if ($customer === null) {
            throw new RuntimeException('Sales order demo seeding requires DEMO-CUS-CAIRO-001 visible through the M03 customer contract.');
        }

        $productsApi = app(ProductsApi::class);
        $productRow = collect($productsApi->listMasters(new ListMastersRequest($actorId, 'products', 'DEMO-PROD-001', true, 1, 50))->items)
            ->firstWhere('sku', 'DEMO-PROD-001');
        if ($productRow === null) {
            throw new RuntimeException('Sales order demo seeding requires active DEMO-PROD-001 visible through the M02 product contract.');
        }

        $product = $productsApi->getProduct(new GetProductRequest($actorId, (int) $productRow['id']))->product;
        $unitId = (int) $product['base_unit_id'];
        $unit = collect($productsApi->listMasters(new ListMastersRequest($actorId, 'units', '', true, 1, 100))->items)
            ->firstWhere('id', $unitId);
        if ($unit === null) {
            throw new RuntimeException('The sales order demo product base unit is unavailable through the M02 contract.');
        }

        $branchSnapshot = [
            'id' => (int) $branch['id'],
            'code' => (string) $branch['code'],
            'name' => (string) $branch['name'],
            'address' => $branch['address'],
        ];
        $customerSnapshot = [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'entityType' => $customer->entityType,
            'status' => $customer->status,
        ];
        $productSnapshot = [
            'id' => (int) $product['id'],
            'sku' => (string) $product['sku'],
            'name' => (string) $product['name'],
            'description' => (string) ($product['short_description'] ?? ''),
            'unit_id' => $unitId,
            'unit_code' => (string) $unit['code'],
            'unit_name' => (string) $unit['name'],
            'version' => (int) $product['version'],
        ];

        DB::transaction(function () use ($branchSnapshot, $customerSnapshot, $productSnapshot): void {
            $draftKey = Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-elec-new:demo:sales-order:draft:001')->toString();
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['erp-elec-new:demo:sales-order:draft:001']);
            if (DB::table('sales_orders')->where('draft_key', $draftKey)->exists()) {
                return;
            }

            $now = now();
            $orderId = DB::table('sales_orders')->insertGetId([
                'draft_key' => $draftKey,
                'number' => null,
                'branch_id' => $branchSnapshot['id'],
                'branch_snapshot' => json_encode($branchSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'customer_id' => $customerSnapshot['id'],
                'customer_snapshot' => json_encode($customerSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'source_type' => 'direct',
                'project_name' => 'Synthetic Cairo electrical project',
                'customer_reference' => 'DEMO-SO-001',
                'order_date' => now('Africa/Cairo')->toDateString(),
                'requested_delivery_date' => null,
                'currency' => 'EGP',
                'status' => 'draft',
                'version' => 1,
                'discount_mode' => 'percent',
                'discount_value' => '0.00000000',
                'payment_terms' => null,
                'delivery_terms' => null,
                'notes' => 'Synthetic local draft. No price, approval, stock reservation, delivery, invoice, or posting is implied.',
                'calculation' => json_encode([], JSON_THROW_ON_ERROR),
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('sales_order_lines')->insert([
                'sales_order_id' => $orderId,
                'position' => 1,
                'product_id' => $productSnapshot['id'],
                'unit_id' => $productSnapshot['unit_id'],
                'product_snapshot' => json_encode($productSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'description' => $productSnapshot['name'],
                'quantity' => '1.00000000',
                'suggested_price' => null,
                'requested_price' => null,
                'price_source' => null,
                'discount_mode' => 'percent',
                'discount_value' => '0.00000000',
                'override_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function assertSafeTarget(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Sales order demo seeding is restricted to local/testing PostgreSQL databases.');
        }

        if (app()->environment('testing') && DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
            throw new RuntimeException('Testing sales order demo seeding requires the exact ERP test database.');
        }
    }
}
