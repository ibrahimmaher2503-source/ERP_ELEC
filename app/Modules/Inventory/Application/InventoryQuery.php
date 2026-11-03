<?php

namespace App\Modules\Inventory\Application;

use Illuminate\Support\Facades\DB;

final class InventoryQuery
{
    /** Shared by the screen and its export so selected filters mean the same thing. */
    public function filter($query, string $table, array $filters): void
    {
        $search = mb_substr((string) ($filters['search'] ?? ''), 0, 100);
        if ($search !== '') {
            if ($table === 'inventory_reservations') {
                $query->where('source_id', 'ilike', '%'.$search.'%');
            } else {
                $query->where(fn ($q) => $q->where('sku', 'ilike', '%'.$search.'%')->orWhere('product_name', 'ilike', '%'.$search.'%'));
            }
        }
        if ($table === 'inventory_movements' && in_array($filters['operation'] ?? '', ['financial_opening', 'purchase_receipt', 'opening_post', 'issue_post', 'adjustment_post', 'transfer_dispatch', 'transfer_receive', 'reservation_issue', 'correction'], true)) {
            $query->where('operation', $filters['operation']);
        }
        if ($table === 'inventory_balances') {
            match ($filters['status'] ?? '') {
                'low' => $query->whereNotNull('minimum')->whereColumn('available', '<', 'minimum'),
                'empty' => $query->where('available', '0'),
                'reserved' => $query->where('reserved', '>', '0'),
                default => null,
            };
            if (ctype_digit((string) ($filters['category'] ?? ''))) {
                $query->where('category_id', (int) $filters['category']);
            }
        }
    }

    public function warehouses(int $actor)
    {
        app(InventoryAccess::class)->authorize($actor, 'warehouse_view');

        return DB::table('inventory_warehouses');
    }

    public function scoped(int $actor, string $action, string $table, string $column = 'warehouse_id')
    {
        $ids = app(InventoryAccess::class)->ids($actor, $action);
        abort_unless($ids !== [], 403, 'يلزم منح صلاحية ونطاق فرع ومستودع.');

        return DB::table($table)->whereIn($column, $ids);
    }

    public function balances(int $actor)
    {
        return $this->scoped($actor, 'balance_view', 'inventory_balances');
    }

    public function movements(int $actor)
    {
        return $this->scoped($actor, 'movement_view', 'inventory_movements');
    }

    public function reservations(int $actor)
    {
        return $this->scoped($actor, 'reservation_view', 'inventory_reservations');
    }

    public function documents(int $actor, string $kind)
    {
        $scopes = [];
        foreach (['create', 'approve', ...($kind === 'transfer' ? ['dispatch', 'receive'] : ($kind === 'count' ? ['submit'] : ['post']))] as $action) {
            $ids = app(InventoryAccess::class)->ids($actor, app(InventoryDocuments::class)->permission($kind, $action));
            if ($ids !== []) {
                $scopes[] = $ids;
            }
        }
        abort_unless($scopes !== [], 403);

        return DB::table('inventory_documents')->where('kind', $kind)->where(function ($query) use ($scopes) {
            foreach ($scopes as $ids) {
                $query->orWhere(fn ($q) => $q->whereIn('warehouse_id', $ids)->where(fn ($destination) => $destination->whereNull('destination_id')->orWhereIn('destination_id', $ids)));
            }
        });
    }
}
