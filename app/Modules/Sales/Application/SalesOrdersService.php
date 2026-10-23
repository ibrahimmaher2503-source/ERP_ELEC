<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use App\Modules\Sales\Domain\SalesOrder;

final class SalesOrdersService implements SalesOrdersApi
{
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
        // No approved lifecycle producer exists yet; a draft cannot authorize physical allocation.
        abort_unless($order->status === 'approved', 409, 'حجز المخزون يحتاج أمر بيع معتمد فعليًا؛ مسودة البيع لا تمنح هذا التفويض.');

        return ['eligible' => true, 'product_id' => $line->product_id, 'branch_id' => $order->branch_id, 'quantity' => $line->quantity];
    }

    public function getOrder(int $actorId, int $orderId): array
    {
        $r = SalesOrder::with('lines')->findOrFail($orderId);
        app(SalesOrderAccess::class)->authorize($actorId, 'view', $r->branch_id);

        return ['id' => $r->id, 'draftKey' => $r->draft_key, 'number' => $r->number, 'version' => $r->version, 'branchId' => $r->branch_id, 'sourceType' => $r->source_type, 'lifecycle' => $r->status, 'reservation' => 'not_requested', 'delivery' => 'not_started', 'invoicing' => 'not_requested', 'currency' => $r->currency, 'customer' => $r->customer_snapshot, 'calculation' => $r->calculation, 'lines' => $r->lines->map(fn ($l) => $l->only(['id', 'product_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'requested_price', 'discount_mode', 'discount_value']))->all()];
    }
}
