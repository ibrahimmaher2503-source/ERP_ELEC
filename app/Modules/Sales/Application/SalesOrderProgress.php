<?php

namespace App\Modules\Sales\Application;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Domain\SalesOrder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class SalesOrderProgress
{
    public function read(int $actor, SalesOrder $order): array
    {
        app(SalesOrderAccess::class)->authorize($actor, 'view', $order->branch_id);
        $lines = [];
        $requested = '0.00000000';
        $delivered = '0.00000000';
        $backorder = '0.00000000';
        $restricted = false;
        foreach (DB::table('sales_order_allocations')->where('order_id', $order->id)->get() as $allocation) {
            $fulfilled = (string) DB::table('sales_delivery_instruction_lines as l')->join('sales_delivery_instructions as d', 'd.id', '=', 'l.instruction_id')
                ->where('d.order_id', $order->id)->where('d.status', 'confirmed')->where('l.line_id', $allocation->line_id)->sum('l.quantity');
            $line = ['base_quantity' => $allocation->base_quantity, 'delivered' => bcadd($fulfilled, '0', 8),
                'warehouse_id' => $allocation->warehouse_id, 'reservation_id' => $allocation->reservation_id, 'outstanding' => null, 'backorder' => null];
            try {
                $reservation = app(InventoryApi::class)->reservationStatus($actor, $allocation->reservation_id);
                $line['outstanding'] = $reservation['outstanding'];
                $line['backorder'] = $reservation['unreserved'];
                $backorder = bcadd($backorder, $reservation['unreserved'], 8);
            } catch (HttpExceptionInterface $e) {
                if ($e->getStatusCode() !== 403) {
                    throw $e;
                }
                $restricted = true;
            }
            $requested = bcadd($requested, $allocation->base_quantity, 8);
            $delivered = bcadd($delivered, $fulfilled, 8);
            $lines[$allocation->line_id] = $line;
        }
        $delivery = bccomp($delivered, '0', 8) === 0 ? 'not_started' : (bccomp($delivered, $requested, 8) === 0 ? 'fulfilled' : 'partial');
        $reservation = $lines === [] ? 'not_requested' : ($restricted ? 'restricted'
            : (bccomp($backorder, '0', 8) > 0 ? 'backorder' : ($delivery === 'fulfilled' ? 'fulfilled' : 'reserved')));

        $invoices = app(ReceivablesPayablesApi::class)->salesOrderInvoices($actor, (int) $order->branch_id, $order->lines()->pluck('id')->all());

        return ['reservation' => $reservation, 'delivery' => $delivery, 'invoicing' => $invoices['state'], 'invoices' => $invoices['invoices'], 'lines' => $lines];
    }
}
