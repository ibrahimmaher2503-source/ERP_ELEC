<?php

namespace App\Modules\Sales\Application;

use App\Modules\Products\Application\Contracts\ConvertQuantityRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use App\Modules\Sales\Domain\SalesOrder;
use Illuminate\Support\Facades\DB;

final class SalesOrdersService implements SalesOrdersApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array
    {
        $ids = app(SalesOrderAccess::class)->branchIds($actor);
        abort_unless($ids && ($branch === null || in_array($branch, $ids, true)), 403);
        $q = SalesOrder::query()->whereIn('branch_id', $branch === null ? $ids : [$branch])->whereBetween('order_date', [$from, $to]);

        return ['scope' => 'authorized_branches', 'metrics' => ['orders' => (clone $q)->count(), 'draft' => (clone $q)->where('status', 'draft')->count()], 'basis' => 'أوامر حسب تاريخ الطلب وحالتها الحالية؛ ليست إيرادًا محاسبيًا.'];
    }

    public function inventoryReleaseAllowed(int $actor, string $sourceId, string $sourceLine): bool
    {
        abort_unless(ctype_digit($sourceId) && ctype_digit($sourceLine), 422);
        $order = SalesOrder::with('lines')->findOrFail((int) $sourceId);
        app(SalesOrderAccess::class)->authorize($actor, 'cancel', $order->branch_id);
        abort_unless($order->lines->contains('id', (int) $sourceLine), 404);

        // Authorized amendment releases will be added by the Sales amendment producer, never inferred here.
        return $order->status === 'cancelled';
    }

    public function inventoryDemand(int $actor, string $sourceId, string $sourceLine): array
    {
        abort_unless(ctype_digit($sourceId) && ctype_digit($sourceLine), 422);
        $order = SalesOrder::with('lines')->findOrFail((int) $sourceId);
        app(SalesOrderAccess::class)->authorize($actor, 'view', $order->branch_id);
        $line = $order->lines->firstWhere('id', (int) $sourceLine);
        abort_unless($line, 404);
        abort_unless($order->status === 'approved' && DB::table('sales_order_issues')->where('order_id', $order->id)->exists(), 409, 'حجز المخزون يحتاج أمر بيع معتمد فعليًا؛ مسودة البيع لا تمنح هذا التفويض.');
        $base = app(ProductsApi::class)->convertQuantity(new ConvertQuantityRequest($actor, $line->product_id, $line->unit_id, $line->quantity));

        return ['eligible' => true, 'product_id' => $line->product_id, 'branch_id' => $order->branch_id, 'quantity' => $base->baseQuantity];
    }

    public function getOrder(int $actorId, int $orderId): array
    {
        $r = SalesOrder::with('lines')->findOrFail($orderId);
        app(SalesOrderAccess::class)->authorize($actorId, 'view', $r->branch_id);
        $progress = app(SalesOrderProgress::class)->read($actorId, $r);

        return ['id' => $r->id, 'draftKey' => $r->draft_key, 'number' => $r->number, 'version' => $r->version, 'branchId' => $r->branch_id, 'sourceType' => $r->source_type, 'lifecycle' => $r->status, 'reservation' => $progress['reservation'], 'delivery' => $progress['delivery'], 'invoicing' => $progress['invoicing'], 'fulfillment' => $progress['lines'], 'currency' => $r->currency, 'customer' => $r->customer_snapshot, 'calculation' => $r->calculation, 'lines' => $r->lines->map(fn ($l) => $l->only(['id', 'product_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'requested_price', 'discount_mode', 'discount_value']))->all()];
    }
}
