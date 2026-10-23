<?php

namespace App\Modules\Inventory\Application;

use Illuminate\Support\Facades\DB;

final class InventoryReconciliation
{
    /** Quantity reconciliation only. Never repairs projections or invents movements. */
    public function warehouse(int $actor, int $warehouse): array
    {
        return DB::transaction(function () use ($actor, $warehouse) {
            InventoryActions::lock();
            foreach (['balance_view', 'movement_view', 'reservation_view'] as $permission) {
                app(InventoryAccess::class)->authorize($actor, $permission, $warehouse);
            }

            $ledger = DB::table('inventory_movements')->where('warehouse_id', $warehouse)
                ->selectRaw('product_id, sum(quantity) as ledger_quantity')->groupBy('product_id');
            $reservations = DB::table('inventory_reservations')->where('warehouse_id', $warehouse)
                ->selectRaw('product_id, sum(reserved - fulfilled - released) as outstanding')->groupBy('product_id');
            $isolations=DB::table('inventory_isolations')->where('warehouse_id',$warehouse)->selectRaw('product_id,sum(quantity-released) as isolated')->groupBy('product_id');
            $keys = DB::table('inventory_balances')->where('warehouse_id', $warehouse)->select('product_id')
                ->union(DB::table('inventory_movements')->where('warehouse_id', $warehouse)->select('product_id'))
                ->union(DB::table('inventory_reservations')->where('warehouse_id', $warehouse)->select('product_id'));
            $rows = DB::query()->fromSub($keys, 'k')
                ->leftJoin('inventory_balances as b', fn ($join) => $join->on('b.product_id', '=', 'k.product_id')->where('b.warehouse_id', $warehouse))
                ->leftJoinSub($isolations,'c','c.product_id','=','k.product_id')
                ->leftJoinSub($ledger, 'm', 'm.product_id', '=', 'k.product_id')
                ->leftJoinSub($reservations, 'r', 'r.product_id', '=', 'k.product_id')
                ->selectRaw('coalesce(b.unavailable,0)::text as unavailable,coalesce(c.isolated,0)::text as isolated,k.product_id, b.id as balance_id, coalesce(b.on_hand,0)::text as on_hand, coalesce(m.ledger_quantity,0)::text as ledger_quantity, coalesce(b.reserved,0)::text as reserved, coalesce(r.outstanding,0)::text as outstanding')
                ->orderBy('k.product_id')->get();
            $discrepancies = $rows->filter(fn ($row) => $row->balance_id === null
                || bccomp($row->on_hand, $row->ledger_quantity, 8) !== 0
                || bccomp($row->reserved, $row->outstanding, 8) !== 0
                || bccomp($row->unavailable,$row->isolated,8)!==0)->map(fn ($row) => (array) $row)->values()->all();

            return ['warehouse_id' => $warehouse, 'products_checked' => $rows->count(), 'reconciled' => $discrepancies === [], 'discrepancies' => $discrepancies];
        });
    }
}
