<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Application\Contracts\SalesDeliverySources;
use App\Modules\Sales\Domain\SalesOrder;
use Illuminate\Support\Facades\DB;

final class SalesDeliveryService implements SalesDeliverySources
{
    public function authorizeIssue(int $actor, string $order, string $line, string $key, int $warehouse, string $quantity): array
    {
        abort_unless(DB::transactionLevel() > 0 && ctype_digit($order) && ctype_digit($line), 422);
        $r = SalesOrder::findOrFail((int) $order);
        app(SalesOrderAccess::class)->authorize($actor, 'view', $r->branch_id);
        app(SalesOrderAccess::class)->authorize($actor, 'delivery_confirm', $r->branch_id);
        $source = DB::table('sales_delivery_instruction_lines as l')->join('sales_delivery_instructions as d', 'd.id', '=', 'l.instruction_id')
            ->join('sales_order_allocations as a', 'a.line_id', '=', 'l.line_id')
            ->where('d.order_id', $r->id)->where('d.status', 'posting')->where('l.line_id', (int) $line)->where('l.issue_key', $key)
            ->where('a.warehouse_id', $warehouse)->whereColumn('a.reservation_id', 'l.reservation_id')->first(['l.quantity', 'd.id']);
        $approval = DB::table('sales_order_issues')->where('order_id', $r->id)->first(['approved_by']);
        abort_unless($r->status === 'approved' && $source && $approval && bccomp($quantity, $source->quantity, 8) === 0, 409, 'لا يوجد إذن تسليم حالي مطابق للحجز والكمية.');

        return ['branch_id' => $r->branch_id, 'customer_id' => (int) $r->customer_id, 'created_by' => $r->created_by, 'approved_by' => $approval->approved_by, 'instruction_id' => $source->id];
    }

    public function customerInvoiceSource(int $actor, array $deliveryLineIds): array
    {
        abort_unless(DB::transactionLevel() > 0 && $deliveryLineIds !== [], 422);
        $ids = array_values(array_unique(array_map('intval', $deliveryLineIds)));
        $rows = DB::table('sales_delivery_instruction_lines as dl')
            ->join('sales_delivery_instructions as d', 'd.id', '=', 'dl.instruction_id')
            ->join('sales_order_lines as l', 'l.id', '=', 'dl.line_id')
            ->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->join('sales_order_issues as i', 'i.order_id', '=', 'o.id')
            ->whereIn('dl.id', $ids)->orderBy('dl.id')->lockForUpdate()
            ->get(['dl.id as delivery_line_id', 'dl.quantity as delivered_quantity', 'd.id as delivery_id', 'd.status as delivery_status',
                'l.id as order_line_id', 'l.position', 'l.product_id', 'l.description', 'l.quantity as ordered_quantity',
                'o.id as order_id', 'o.branch_id', 'o.customer_id', 'o.customer_snapshot', 'i.tax_snapshot']);
        abort_unless($rows->count() === count($ids), 409, 'يلزم اختيار بنود تسليم مؤكدة فعلياً.');
        $first = $rows->first();
        app(SalesOrderAccess::class)->authorize($actor, 'view', $first->branch_id);
        abort_unless($rows->every(fn ($row) => $row->delivery_status === 'confirmed' && (int) $row->order_id === (int) $first->order_id),
            409, 'مصادر الفاتورة يجب أن تكون تسليمات مؤكدة لنفس أمر البيع.');
        $tax = json_decode($first->tax_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $orderedLines = DB::table('sales_order_lines')->where('sales_order_id', $first->order_id)->orderBy('position')->get(['id']);
        $taxByLine = [];
        foreach ($orderedLines as $index => $line) {
            $taxByLine[(int) $line->id] = $tax['lines'][$index];
        }

        return ['branch_id' => (int) $first->branch_id, 'customer_id' => (int) $first->customer_id,
            'customer' => json_decode($first->customer_snapshot, true, 512, JSON_THROW_ON_ERROR), 'order_id' => (int) $first->order_id,
            'lines' => $rows->map(function ($row) use ($taxByLine) {
                $line = (array) $row;
                unset($line['customer_snapshot'], $line['tax_snapshot']);
                $line['tax'] = $taxByLine[(int) $row->order_line_id];

                return $line;
            })->all()];
    }
}
