<?php

// Direct sales-order commercial review acceptance. Synthetic actions run inside one rollback-only transaction.
use App\Models\User;
use App\Modules\Organization\Application\AssignUserRole;
use App\Modules\Products\Application\SalePrices;
use App\Modules\Sales\Application\ApproveSalesOrder;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Sales\Application\SalesOrderApprovalPolicy;
use App\Modules\Sales\Application\SalesOrderCommercialReview;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_DATABASE=erp_elec_new_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
if (getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '55433') {
    throw new RuntimeException('ABORT: explicit isolated 55433 required.');
}
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = $root.'/.tmp/phase0-recovery-20261027/restore-storage';
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'erp_elec_new_test' || DB::selectOne('select current_database() db')->db !== 'erp_elec_new_test' || (int) config('database.connections.pgsql.port') !== 55433) {
    throw new RuntimeException('ABORT: exact isolated database guard failed.');
}
$need = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
$expect = static function (callable $action, ?int $status = null): bool {
    try {
        $action();
    } catch (Throwable $e) {
        if ($status !== null) {
            return $e instanceof HttpExceptionInterface && $e->getStatusCode() === $status;
        }

        return true;
    }

    return false;
};
$branch = 1;
$warehouse = 1;
$customer = 1;
$product = 2;
$unit = 2;
$altProduct = 3;
$altPriceId = 2;
$need(DB::table('accounting_activation')->where('id', 1)->whereNotNull('activated_at')->exists(), 'Activated financial fixture missing.');
$price = DB::table('product_sale_prices')->where('product_id', $product)->where('unit_id', $unit)->where('status', 'approved')->where('starts_on', '<=', '2026-10-28')->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', '2026-10-28'))->first();
$need($price && $price->amount === '10.00000000', 'Existing approved dated M02 price fixture missing.');
$need(DB::table('inventory_balances')->where('warehouse_id', $warehouse)->where('product_id', $product)->whereRaw('available >= 3')->exists(), 'Existing scoped stock fixture missing.');
$need(DB::table('customers')->where('id', $customer)->where('status', 'active')->exists(), 'Existing active synthetic customer missing.');
$payment = (string) DB::table('sales_quotation_terms')->where('kind', 'payment')->where('active', true)->orderBy('id')->value('body');
$delivery = (string) DB::table('sales_quotation_terms')->where('kind', 'delivery')->where('active', true)->orderBy('id')->value('body');
$need($payment !== '' && $delivery !== '', 'Existing active commercial terms fixture missing.');
$db->beginTransaction();
$actors = [];
$grant = static function (int $user, string $code, ?int $branchId = null): void {
    DB::table('access_grants')->insertOrIgnore(['user_id' => $user, 'permission_code' => $code, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
};
$createActor = static function (string $key, array $codes, bool $warehouseScope = true) use (&$actors, $branch, $warehouse, $grant): int {
    $user = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $id = (int) $user->id;
    $actors[$key] = $id;
    DB::table('user_branch_scopes')->insert(['user_id' => $id, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($codes as $code) {
        $scope = str_starts_with($code, 'M01.') || str_starts_with($code, 'M02.') || str_starts_with($code, 'M03.') ? null : $branch;
        $grant($id, $code, $scope);
    }
    if ($warehouseScope) {
        DB::table('user_warehouse_scopes')->insert(['user_id' => $id, 'warehouse_id' => $warehouse, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $id;
};
$maker = $createActor('maker', ['M04.sales_order_view', 'M04.sales_order_create', 'M04.sales_order_edit', 'M04.sales_order_submit', 'M04.sales_order_exception_approve', 'M02.product_view', 'M02.price_view', 'M03.customer_view']);
$reviewer = $createActor('reviewer', ['M04.sales_order_view', 'M04.sales_order_approve', 'M04.sales_order_exception_approve', 'M02.product_view', 'M02.price_view', 'M03.customer_view', 'M06.reservation_manage']);
$wrong = $createActor('wrong_role', ['M04.sales_order_view', 'M04.sales_order_approve', 'M04.sales_order_exception_approve', 'M02.product_view', 'M02.price_view', 'M03.customer_view']);
$unscoped = $createActor('missing_warehouse_scope', ['M04.sales_order_view', 'M04.sales_order_approve', 'M02.product_view', 'M02.price_view', 'M03.customer_view'], false);
$security = $createActor('security_admin', ['M01.security_admin'], false);
$priceOwner = $createActor('price_owner', ['M02.product_view', 'M02.price_view', 'M02.price_manage'], false);
$priceApprover = $createActor('price_approver', ['M02.product_view', 'M02.price_view', 'M02.price_approve'], false);
$branchPermissions = ['M04.sales_order_view', 'M04.sales_order_approve', 'M04.sales_order_exception_approve'];
foreach ([$maker, $reviewer, $wrong, $unscoped] as $actor) {
    foreach ($branchPermissions as $code) {
        $grant($actor, $code, $branch);
    }
}
$grant($maker, 'M02.price_manage');
$grant($priceOwner, 'M02.price_manage');
$grant($priceApprover, 'M02.price_approve');
$grant($reviewer, 'M11.tax_calculate');
$grant($unscoped, 'M11.tax_calculate');
$grant($wrong, 'M11.tax_calculate');
$role = DB::table('roles')->where('code', 'SalesClerk')->first();
$wrongRole = DB::table('roles')->where('code', 'Buyer')->first();
$need($role && $wrongRole, 'Existing sales/buyer role fixture missing.');
$secUser = User::findOrFail($security);
app(AssignUserRole::class)->execute($secUser, (int) $role->id, $reviewer, true, (int) $role->version);
app(AssignUserRole::class)->execute($secUser, (int) $wrongRole->id, $wrong, true, (int) $wrongRole->version);
$policy = app(SalesOrderApprovalPolicy::class);
$policy->save($security, (int) $role->id, null);
$policyVersion = (int) DB::table('sales_order_policies')->where('id', 1)->value('version');
$actions = app(SalesOrderActions::class);
$commercial = app(SalesOrderCommercialReview::class);
$approver = app(ApproveSalesOrder::class);
$baseInput = static fn (int $productId, int $unitId, array $overrides = []): array => array_replace([
    'creation_key' => (string) Str::uuid(), 'branch_id' => 1, 'customer_id' => 1, 'order_date' => '2026-10-28', 'requested_delivery_date' => null,
    'discount_mode' => 'percent', 'discount_value' => '0.00', 'payment_terms' => $payment, 'delivery_terms' => $delivery, 'notes' => 'Synthetic direct-order acceptance',
    'lines' => [['product_id' => $productId, 'unit_id' => $unitId, 'quantity' => '1.00000000', 'requested_price' => null, 'description' => 'Synthetic catalog-priced item', 'discount_mode' => 'percent', 'discount_value' => '0.00', 'override_reason' => null]],
], $overrides);
$makeSubmitted = static function (array $input) use ($actions, $maker): array {
    $order = $actions->save($maker, $input);
    $review = $actions->submit($maker, $order->id, $order->version);

    return [$order->id, $review->target_version, $review->id, (int) $order->lines->first()->id];
};
$effects = static function (int $id): array {
    return ['orders' => DB::table('sales_orders')->where('id', $id)->count(), 'issues' => DB::table('sales_order_issues')->where('order_id', $id)->count(), 'reservations' => DB::table('inventory_reservations')->where('source_module', 'sales')->where('source_id', (string) $id)->count(), 'documents' => DB::table('system_official_documents')->where('document_type', 'sales_order')->where('source_id', (string) $id)->count()];
};
try {
    $direct = $actions->save($maker, $baseInput($product, $unit));
    $direct = $actions->save($maker, $baseInput($product, $unit, ['creation_key' => (string) $direct->draft_key, 'notes' => 'Synthetic direct-order reopened draft']), $direct->id, $direct->version);
    [$directId,$directVersion,$directReview,$directLine] = $makeSubmitted($baseInput($product, $unit, ['creation_key' => (string) $direct->draft_key, 'notes' => 'Synthetic direct-order reopened draft']));
    $beforeDirect = $effects($directId);
    $need($beforeDirect['issues'] === 0 && $beforeDirect['reservations'] === 0 && $beforeDirect['documents'] === 0, 'Direct order created posting/reservation effects before approval.');
    $approver->execute($reviewer, $directId, $directVersion, [$directLine => $warehouse]);
    $directRow = DB::table('sales_orders')->find($directId);
    $directReservation = DB::table('inventory_reservations')->where('source_module', 'sales')->where('source_id', (string) $directId)->where('source_line', (string) $directLine)->first();
    $need($directRow->status === 'approved' && $directReservation && $directReservation->warehouse_id === $warehouse && DB::table('sales_order_issues')->where('order_id', $directId)->exists(), 'Compliant direct order did not use actual approval, official issue and scoped inventory reservation.');
    [$selfOrderId,$selfOrderVersion,$selfReview,$selfLine] = $makeSubmitted($baseInput($product, $unit));
    $selfApprovalDenied = $expect(fn () => $approver->execute($maker, $selfOrderId, $selfOrderVersion, [$selfLine => $warehouse]), 403);
    $need($selfApprovalDenied && $effects($selfOrderId)['issues'] === 0 && $effects($selfOrderId)['reservations'] === 0 && DB::table('sales_orders')->where('id', $selfOrderId)->value('status') === 'pending_approval', 'Order creator self-approved a submitted direct order.');

    $exceptionInput = $baseInput($product, $unit, ['lines' => [['product_id' => $product, 'unit_id' => $unit, 'quantity' => '1.00000000', 'requested_price' => '11.00000000', 'description' => 'Synthetic price exception', 'discount_mode' => 'fixed', 'discount_value' => '0.25000000', 'override_reason' => 'Synthetic reviewed commercial exception']], 'payment_terms' => 'Synthetic non-template payment term']);
    [$exceptionId,$exceptionVersion,$exceptionReview,$exceptionLine] = $makeSubmitted($exceptionInput);
    $beforeException = $effects($exceptionId);
    $normalDenied = $expect(fn () => $approver->execute($reviewer, $exceptionId, $exceptionVersion, [$exceptionLine => $warehouse]));
    $selfDenied = $expect(fn () => $commercial->decide($maker, $exceptionId, $exceptionVersion, 'Synthetic self approval denial'), 403);
    $wrongDenied = $expect(fn () => $commercial->decide($wrong, $exceptionId, $exceptionVersion, 'Synthetic wrong role denial'), 403);
    $immutable = static function (callable $mutation): bool {
        DB::beginTransaction();
        try {
            $mutation();
            DB::rollBack();

            return false;
        } catch (Throwable) {
            DB::rollBack();

            return true;
        }
    };
    $reviewSnapshotImmutable = $immutable(fn () => DB::table('sales_order_reviews')->where('id', $exceptionReview)->update(['content_hash' => str_repeat('0', 64)]));
    $staleContentDenied = $expect(fn () => $commercial->decide($reviewer, $exceptionId, $exceptionVersion - 1, 'Synthetic stale order version denial'));
    $need($normalDenied && $selfDenied && $wrongDenied && $staleContentDenied && $reviewSnapshotImmutable && $effects($exceptionId) === $beforeException && DB::table('sales_orders')->where('id', $exceptionId)->value('status') === 'pending_approval', 'Exception order failed prerequisite checks: '.json_encode(['normal' => $normalDenied, 'self' => $selfDenied, 'wrong' => $wrongDenied, 'stale' => $staleContentDenied, 'review_snapshot_immutable' => $reviewSnapshotImmutable, 'before' => $beforeException, 'after' => $effects($exceptionId), 'status' => DB::table('sales_orders')->where('id', $exceptionId)->value('status')], JSON_UNESCAPED_UNICODE));
    $commercial->decide($reviewer, $exceptionId, $exceptionVersion, 'Synthetic approved price, discount, and terms departure');
    $decision = DB::table('sales_order_exception_decisions')->where('review_id', $exceptionReview)->first();
    $pendingEffects = $effects($exceptionId);
    $need($decision && $decision->policy_version === $policyVersion && $decision->decided_by === $reviewer && $pendingEffects === $beforeException && DB::table('sales_order_reviews')->where('id', $exceptionReview)->value('status') === 'pending', 'Commercial decision itself approved or posted the order.');
    $decisionUpdateDenied = $immutable(fn () => DB::table('sales_order_exception_decisions')->where('review_id', $exceptionReview)->update(['reason' => 'tampered']));
    $decisionDeleteDenied = $immutable(fn () => DB::table('sales_order_exception_decisions')->where('review_id', $exceptionReview)->delete());
    $need($decisionUpdateDenied && $decisionDeleteDenied, 'Commercial decision record was mutable.');
    $approver->execute($reviewer, $exceptionId, $exceptionVersion, [$exceptionLine => $warehouse]);
    $need(DB::table('sales_orders')->where('id', $exceptionId)->where('status', 'approved')->exists() && DB::table('sales_order_issues')->where('order_id', $exceptionId)->exists() && $effects($exceptionId)['reservations'] === 1, 'Independently approved exception order did not issue and reserve.');

    $stalePolicyInput = $baseInput($product, $unit, ['lines' => [['product_id' => $product, 'unit_id' => $unit, 'quantity' => '1.00000000', 'requested_price' => '12.00000000', 'description' => 'Synthetic stale policy exception', 'discount_mode' => 'percent', 'discount_value' => '0.00', 'override_reason' => 'Policy staleness fixture']]]);
    [$stalePolicyId,$stalePolicyVersion,$stalePolicyReview,$stalePolicyLine] = $makeSubmitted($stalePolicyInput);
    $commercial->decide($reviewer, $stalePolicyId, $stalePolicyVersion, 'Synthetic stale policy decision');
    $policy->save($security, (int) $wrongRole->id, $policyVersion);
    $stalePolicyDenied = $expect(fn () => $approver->execute($reviewer, $stalePolicyId, $stalePolicyVersion, [$stalePolicyLine => $warehouse]));
    $need($stalePolicyDenied && $effects($stalePolicyId) === ['orders' => 1, 'issues' => 0, 'reservations' => 0, 'documents' => 0], 'Policy version change did not invalidate prior exception approval.');
    $policy->save($security, (int) $role->id, $policyVersion + 1);

    $sourceChangeInput = $baseInput($altProduct, $unit);
    [$changedPriceId,$changedPriceVersion,$changedPriceReview,$changedPriceLine] = $makeSubmitted($sourceChangeInput);
    $priceActions = app(SalePrices::class);
    $priceActions->decide($priceOwner, $altPriceId, 'withdraw');
    $newPrice = $priceActions->save($priceOwner, $altProduct, ['amount' => '12.00000000', 'unit_id' => $unit, 'starts_on' => '2026-10-28', 'ends_on' => null, 'reference' => 'Synthetic dated source-change acceptance']);
    $priceActions->decide($priceApprover, $newPrice, 'approve');
    $changedPriceDenied = $expect(fn () => $approver->execute($reviewer, $changedPriceId, $changedPriceVersion, [$changedPriceLine => $warehouse]));
    $changedDecisionDenied = $expect(fn () => $commercial->decide($reviewer, $changedPriceId, $changedPriceVersion, 'Synthetic changed price source denial'));
    $need($changedPriceDenied && $changedDecisionDenied && $effects($changedPriceId)['issues'] === 0 && $effects($changedPriceId)['reservations'] === 0, 'Changed approved source price did not block order/decision.');

    $unscopedInput = $baseInput($product, $unit);
    [$unscopedId,$unscopedVersion,$unscopedReview,$unscopedLine] = $makeSubmitted($unscopedInput);
    $unscopedDenied = $expect(fn () => $approver->execute($unscoped, $unscopedId, $unscopedVersion, [$unscopedLine => $warehouse]));
    $need($unscopedDenied && $effects($unscopedId)['issues'] === 0 && $effects($unscopedId)['reservations'] === 0 && DB::table('sales_orders')->where('id', $unscopedId)->value('status') === 'pending_approval', 'Missing warehouse-scope probe failed: '.json_encode(['denied' => $unscopedDenied, 'effects' => $effects($unscopedId), 'status' => DB::table('sales_orders')->where('id', $unscopedId)->value('status'), 'scopes' => DB::table('user_warehouse_scopes')->where('user_id', $unscoped)->count()], JSON_UNESCAPED_UNICODE));

    $result = ['database' => 'erp_elec_new_test', 'postgres_port' => 55433, 'migration' => '2026_10_28_171000_record_sales_order_exception_decisions',
        'direct_compliant' => ['order_id' => $directId, 'reopened_draft' => true, 'status' => 'approved', 'official_issue' => true, 'scoped_reservation_id' => $directReservation->id, 'passed' => true],
        'commercial_exception' => ['order_id' => $exceptionId, 'decision_review_id' => $exceptionReview, 'decision_immutable_update_denied' => $decisionUpdateDenied, 'decision_immutable_delete_denied' => $decisionDeleteDenied, 'decision_left_pending_without_effects' => true, 'normal_independent_approval_after_decision' => true, 'passed' => true],
        'exception_kinds' => array_column(json_decode($decision->exceptions, true, 512, JSON_THROW_ON_ERROR), 'type'),
        'denials' => ['normal_approval_without_separate_decision' => $normalDenied, 'self_exception_approval' => $selfDenied, 'self_sales_order_approval' => $selfApprovalDenied, 'wrong_configured_role_even_with_permission' => $wrongDenied, 'stale_order_content_version' => $staleContentDenied, 'review_snapshot_mutation' => $reviewSnapshotImmutable, 'stale_policy_version' => $stalePolicyDenied, 'changed_dated_price_source' => $changedPriceDenied && $changedDecisionDenied, 'missing_warehouse_scope' => $unscopedDenied],
        'all_pass' => true];
    file_put_contents($root.'/docs/qa/evidence/phase0-direct-sales.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally {
    if ($db->transactionLevel() > 0) {
        $db->rollBack();
    }
}
