<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class InventoryFinancialHistory
{
    private function cutoff(int $actor, array $branches, ?string $to): string
    {
        abort_if($branches === [], 403);
        $to ??= now('Africa/Cairo')->toDateString();
        Validator::make(['to' => $to], ['to' => 'required|date_format:Y-m-d'])->validate();
        foreach ($branches as $branch) {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.report_view', (int) $branch));
        }

        return $to;
    }

    public function value(int $actor, array $branches, ?string $to): string
    {
        $to = $this->cutoff($actor, $branches, $to);
        // FIFO age survives returns/transfers; recognition of those new receipts does not move backwards with it.
        $received = (string) DB::table('inventory_fifo_layers as f')->join('inventory_movements as m', 'm.id', '=', 'f.movement_id')
            ->join('inventory_warehouses as w', 'w.id', '=', 'f.warehouse_id')->whereIn('w.branch_id', $branches)
            ->whereRaw("(CASE WHEN m.operation IN ('financial_opening','purchase_receipt') THEN f.received_on ELSE (m.posted_at AT TIME ZONE 'Africa/Cairo')::date END) <= ?::date", [$to])
            ->sum('f.original_value');
        $issued = (string) DB::table('inventory_fifo_allocations as a')->join('inventory_movements as m', 'm.id', '=', 'a.movement_id')
            ->join('inventory_warehouses as w', 'w.id', '=', 'm.warehouse_id')->whereIn('w.branch_id', $branches)
            ->whereRaw("(m.posted_at AT TIME ZONE 'Africa/Cairo')::date <= ?::date", [$to])->sum('a.amount');

        return bcsub($received, $issued, 2);
    }

    public function transit(int $actor, array $branches, ?string $to): string
    {
        $to = $this->cutoff($actor, $branches, $to);
        $dispatched = (string) DB::table('inventory_transfer_lots as t')->join('inventory_fifo_allocations as a', 'a.id', '=', 't.allocation_id')
            ->join('inventory_movements as m', 'm.id', '=', 'a.movement_id')->join('inventory_warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->whereIn('w.branch_id', $branches)->whereRaw("(m.posted_at AT TIME ZONE 'Africa/Cairo')::date <= ?::date", [$to])->sum('t.original_value');
        $received = (string) DB::table('inventory_transfer_allocations as a')->join('inventory_movements as m', 'm.id', '=', 'a.movement_id')
            ->join('inventory_warehouses as w', 'w.id', '=', 'm.warehouse_id')->whereIn('w.branch_id', $branches)
            ->whereRaw("(m.posted_at AT TIME ZONE 'Africa/Cairo')::date <= ?::date", [$to])->sum('a.amount');

        return bcsub($dispatched, $received, 2);
    }
}
