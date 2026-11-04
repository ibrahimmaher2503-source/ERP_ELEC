<?php

namespace App\Modules\Sales\Application;

use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Exposure is open AR plus approved orders not yet invoiced, plus the order being approved. */
final class SalesOrderCreditLimit
{
    public function require(int $actor, SalesOrder $order, string $total, int $requester): void
    {
        $limit = app(CustomersApi::class)->commercialProfile($actor, (int) $order->customer_id)['credit_limit'];
        if ($limit === null) {
            return;
        }
        DB::select('SELECT pg_advisory_xact_lock(40422, ?)', [(int) $order->customer_id]);
        $orders = DB::table('sales_orders')->where('customer_id', $order->customer_id)->where('status', 'approved')->where('id', '<>', $order->id)->get(['id', 'calculation']);
        $committed = '0.00';
        foreach ($orders as $row) {
            $committed = bcadd($committed, json_decode($row->calculation, true, 512, JSON_THROW_ON_ERROR)['total'] ?? '0', 2);
        }
        $lines = DB::table('sales_order_lines')->whereIn('sales_order_id', $orders->pluck('id'))->pluck('id')->all();
        $exposure = app(ReceivablesPayablesApi::class)->customerCreditExposure((int) $order->customer_id, $lines);
        $projected = bcadd(bcadd($exposure['open'], bcsub($committed, $exposure['invoiced'], 2), 2), $total, 2);
        if (bccomp($projected, $limit, 2) <= 0) {
            return;
        }
        try {
            app(SalesOrderApprovalPolicy::class)->authorizeApprover($actor, $requester, (int) $order->branch_id, true);
        } catch (HttpExceptionInterface|ValidationException) {
            throw ValidationException::withMessages(['readiness' => 'الأمر يتجاوز حد ائتمان العميل '.$limit.' ج.م. المديونية المتوقعة '.$projected.' ج.م. يلزم اعتماد مسؤول الاستثناءات.']);
        }
        event(new SalesOrderChanged($actor, (int) $order->id, 'credit_limit_exception', null, ['limit' => $limit, 'projected' => $projected]));
    }
}
