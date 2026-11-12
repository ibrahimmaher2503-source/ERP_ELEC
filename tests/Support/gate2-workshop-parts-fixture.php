<?php

declare(strict_types=1);

use App\Application\PostPurchaseReceipt;
use App\Models\User;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a real quantity-stock source for the guarded M09 browser workflow.
 * The caller must first verify the exact ownership receipt, database identity, and run nonce.
 *
 * @return array<string, mixed>
 */
function gate2WorkshopPartsFixture(int $setupActorId, int $setupApproverId, int $branchId, string $runNonce): array
{
    if (! preg_match('/^[a-f0-9]{32}$/D', $runNonce)) {
        throw new RuntimeException('M09 parts fixture requires the 32-character guarded run nonce.');
    }

    $company = DB::table('companies')->where('id', 1)->where('setup_complete', true)->first();
    $branch = Branch::query()->whereKey($branchId)->where('company_id', 1)->where('active', true)->first();
    $baseActor = User::query()->whereKey($setupActorId)->where('active', true)->whereNotNull('email_verified_at')->first();
    $baseApprover = User::query()->whereKey($setupApproverId)->where('active', true)->whereNotNull('email_verified_at')->first();
    if (! $company || ! $branch || ! $baseActor || ! $baseApprover
        || ! DB::table('accounting_activation')->where('id', 1)->whereNotNull('activated_at')->exists()) {
        throw new RuntimeException('M09 parts fixture requires the verified finance company, active branch, and setup actors.');
    }

    $mappingRows = DB::table('accounting_account_mappings as m')->join('accounting_accounts as a', 'a.id', '=', 'm.account_id')
        ->whereIn('m.purpose', ['inventory', 'inventory_issue'])->where('a.active', true)->where('a.postable', true)
        ->get(['m.purpose', 'a.id', 'a.kind'])->keyBy('purpose');
    foreach (['inventory' => 'asset', 'inventory_issue' => 'expense'] as $purpose => $kind) {
        if (! isset($mappingRows[$purpose]) || $mappingRows[$purpose]->kind !== $kind) {
            throw new RuntimeException("M09 parts fixture requires the existing active {$purpose} M11 mapping; configure it through the owning financial setup before retrying.");
        }
    }
    if (isset(FinancialActivation::OPTIONAL_MAPPINGS['maintenance_parts'])) {
        throw new RuntimeException('Unexpected maintenance_parts mapping contract; review the owner mapping before using this fixture.');
    }

    $tag = strtoupper(substr($runNonce, 0, 12));
    $emailBase = 'g2ui-m09-parts-'.strtolower($tag);
    if (User::query()->whereIn('email', [$emailBase.'-buyer@example.test', $emailBase.'-storekeeper@example.test'])->exists()
        || DB::table('inventory_warehouses')->where('code', 'G2M09-'.$tag)->exists()) {
        throw new RuntimeException('M09 parts fixture already exists for this run nonce; keep the existing source and choose a new run.');
    }

    $branchScope = static function (User $actor) use ($branchId): void {
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
    };
    $grant = static function (User $actor, array $permissions, ?int $warehouseId = null) use ($branchId): void {
        foreach ($permissions as $permission) {
            $scope = PermissionCatalog::DEFINITIONS[$permission][1] ?? null;
            if ($scope === null) {
                throw new RuntimeException('Unknown M09 parts fixture permission '.$permission.'.');
            }
            $grantBranch = match ($scope) {
                'company' => null,
                'branch', 'warehouse' => $branchId,
                default => throw new RuntimeException('Unsupported M09 parts permission scope.'),
            };
            DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission,
                'branch_id' => $grantBranch, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($warehouseId !== null) {
            DB::table('user_warehouse_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branchId,
                'warehouse_id' => $warehouseId, 'created_at' => now(), 'updated_at' => now()]);
        }
    };
    $makeActor = static function (string $role) use ($emailBase, $tag): array {
        $password = Str::random(48);
        $name = 'DEMO-EG M09 parts '.$role.' '.$tag;
        $email = $emailBase.'-'.$role.'@example.test';
        $actor = User::factory()->create(['name' => $name, 'email' => $email, 'password' => $password,
            'active' => true, 'email_verified_at' => now()]);

        return [$actor, ['id' => (int) $actor->id, 'name' => $name, 'email' => $email, 'password' => $password]];
    };

    [$buyer, $buyerCredentials] = $makeActor('buyer');
    [$storekeeper, $storekeeperCredentials] = $makeActor('storekeeper');
    $branchScope($buyer);
    $branchScope($storekeeper);
    $grant($buyer, ['M01.branch_view', 'M02.product_view', 'M03.supplier_view', 'M05.order_create', 'M05.order_update',
        'M05.order_submit', 'M05.receipt_create', 'M06.warehouse_create', 'M11.tax_calculate']);
    $warehouse = app(InventoryActions::class)->saveWarehouse((int) $buyer->id, ['code' => 'G2M09-'.$tag,
        'name' => 'مخزن قطع الورشة التجريبي '.$tag, 'branch_id' => $branchId, 'active' => true]);
    $warehouseId = (int) $warehouse['id'];
    $grant($buyer, ['M06.receipt_create'], $warehouseId);
    $grant($storekeeper, ['M01.branch_view', 'M02.product_view', 'M03.supplier_view', 'M05.order_approve', 'M05.order_issue',
        'M05.receipt_view', 'M05.receipt_post', 'M06.receipt_post', 'M06.reservation_manage', 'M06.issue_post',
        'M06.correction_execute', 'M06.balance_view', 'M06.reservation_view', 'M06.movement_view', 'M11.tax_calculate',
        'M09.view', 'M09.parts_request', 'M09.parts_issue', 'M09.parts_return', 'M11.source_post', 'M11.journal_post'], $warehouseId);

    $supplier = Supplier::factory()->create(['status' => 'active']);
    $product = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $today = now('Africa/Cairo')->toDateString();
    $orders = app(PurchaseOrders::class);
    $orderId = $orders->save((int) $buyer->id, ['creation_key' => (string) Str::uuid(), 'branch_id' => $branchId,
        'supplier_id' => (int) $supplier->id, 'order_date' => $today, 'expected_date' => null, 'currency' => 'EGP',
        'payment_terms' => 'Synthetic guarded M09 acceptance source.', 'notes' => 'Two actual quantity units for M09 parts UI acceptance.',
        'tax_mode' => 'exclusive', 'reported_tax' => null, 'lines' => [['product_id' => (int) $product->id,
            'quantity' => '2.00000000', 'unit_price' => '20.00000000', 'discount_amount' => '0.00']]], null, 0);
    $orders->transition((int) $buyer->id, $orderId, 1, 'submit');
    $orders->transition((int) $storekeeper->id, $orderId, 2, 'approve');
    $orders->issue((int) $storekeeper->id, $orderId);

    $line = DB::table('purchasing_order_lines')->where('order_id', $orderId)->first();
    if (! $line) {
        throw new RuntimeException('M09 actual purchase order has no persisted source line.');
    }
    $receiving = app(PurchaseReceiving::class);
    $receiptId = $receiving->save((int) $buyer->id, ['creation_key' => (string) Str::uuid(), 'order_id' => $orderId,
        'warehouse_id' => $warehouseId, 'receipt_date' => $today, 'delivery_reference' => 'G2M09-'.$tag,
        'notes' => 'Real posted quantity stock for the guarded workshop parts journey.',
        'lines' => [['order_line_id' => (int) $line->id, 'quantity' => '2.00000000', 'serials' => []]]], null, 0);
    $receiving->submit((int) $buyer->id, $receiptId, 1);
    app(PostPurchaseReceipt::class)->execute((int) $storekeeper->id, $receiptId, 2);

    $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', $product->id)->first();
    $movement = DB::table('inventory_movements')->where('operation', 'purchase_receipt')->where('source_id', (string) $receiptId)
        ->where('product_id', $product->id)->first();
    $fifoCost = $movement ? DB::table('inventory_fifo_layers')->where('movement_id', $movement->id)->sum('original_value') : null;
    if (! $balance || bccomp((string) $balance->on_hand, '2.00000000', 8) !== 0 || ! $movement
        || bccomp((string) $fifoCost, '40.00', 2) !== 0) {
        throw new RuntimeException('M09 fixture stock must come from the posted purchase receipt with exact quantity 2 and FIFO value 40.00.');
    }

    return ['product_id' => (int) $product->id, 'product_name' => $product->name, 'warehouse_id' => $warehouseId,
        'purchase_order_id' => (int) $orderId, 'purchase_receipt_id' => (int) $receiptId,
        'receipt_movement_id' => (int) $movement->id, 'm11_mappings' => ['inventory' => (int) $mappingRows['inventory']->id,
            'inventory_issue' => (int) $mappingRows['inventory_issue']->id],
        'quantity' => '2.00000000', 'unit_cost' => '20.00', 'fifo_source_value' => '40.00', 'expected_final_stock' => '1.00000000',
        'actors' => ['buyer' => $buyerCredentials, 'storekeeper' => $storekeeperCredentials]];
}
