<?php

namespace App\Modules\Purchasing\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Apply the caller's authorization scopes before executing this owner-local read. */
final class PurchaseOrderProjection
{
    public const RECEIVING_STATUSES = ['not_received' => 'لم يستلم', 'partially_received' => 'استلام جزئي', 'received' => 'مستلم بالكامل'];

    public function query(): Builder
    {
        $ordered = '(SELECT coalesce(sum(l.quantity),0) FROM purchasing_order_lines l WHERE l.order_id=purchasing_orders.id)';
        $received = "(SELECT coalesce(sum(l.quantity),0) FROM purchasing_receipt_lines l JOIN purchasing_receipts r ON r.id=l.receipt_id WHERE r.order_id=purchasing_orders.id AND r.status='posted')";

        return DB::table('purchasing_orders')->select('purchasing_orders.*')
            ->selectSub(DB::table('purchasing_order_issues')->select('official_number')->whereColumn('order_id', 'purchasing_orders.id'), 'official_number')
            ->selectRaw($this->receivingExpression().' AS receiving_status')
            ->selectRaw("($ordered-$received)::text AS remaining_quantity");
    }

    private function receivingExpression(): string
    {
        $ordered = '(SELECT coalesce(sum(l.quantity),0) FROM purchasing_order_lines l WHERE l.order_id=purchasing_orders.id)';
        $received = "(SELECT coalesce(sum(l.quantity),0) FROM purchasing_receipt_lines l JOIN purchasing_receipts r ON r.id=l.receipt_id WHERE r.order_id=purchasing_orders.id AND r.status='posted')";

        return "CASE WHEN $received=0 THEN 'not_received' WHEN $received<$ordered THEN 'partially_received' ELSE 'received' END";
    }

    public function filter(Builder $query, string $status = '', string $search = ''): void
    {
        if (isset(self::RECEIVING_STATUSES[$status])) {
            $query->whereRaw('('.$this->receivingExpression().') = ?', [$status]);
        } elseif ($status !== '') {
            $query->where('purchasing_orders.status', $status);
        }
        if ($search !== '') {
            $term = '%'.mb_substr($search, 0, 200).'%';
            $query->where(fn ($q) => $q->where('supplier_name', 'ilike', $term)
                ->orWhereRaw('purchasing_orders.id::text ilike ?', [$term])
                ->orWhereIn('purchasing_orders.id', DB::table('purchasing_order_issues')->where('official_number', 'ilike', $term)->select('order_id')));
        }
    }
}
