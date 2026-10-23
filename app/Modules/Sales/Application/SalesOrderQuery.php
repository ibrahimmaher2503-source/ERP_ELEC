<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SalesOrderQuery
{
    public function query(int $actor, array $filters = [], string $action = 'view'): Builder
    {
        app(SalesOrderAccess::class)->authorize($actor);
        app(SalesOrderAccess::class)->authorize($actor, $action);
        $data = Validator::make($filters, [
            'search' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in(['draft', 'pending_approval', 'cancelled'])],
            'branch' => ['nullable', 'integer', 'min:1'], 'source' => ['nullable', Rule::in(['direct'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        $access = app(SalesOrderAccess::class);
        $q = SalesOrder::whereIn('branch_id', array_intersect($access->branchIds($actor), $access->branchIds($actor, $action)));
        if (($data['search'] ?? '') !== '') {
            $s = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($data['search'])).'%';
            $q->where(fn ($q) => $q->where('number', 'ilike', $s)->orWhere('customer_reference', 'ilike', $s)->orWhere('project_name', 'ilike', $s)->orWhereRaw("customer_snapshot->>'name' ILIKE ?", [$s])->orWhereRaw('draft_key::text ILIKE ?', [$s]));
        }
        foreach (['status' => 'status', 'branch' => 'branch_id', 'source' => 'source_type'] as $filter => $column) {
            if (($data[$filter] ?? '') !== '') {
                $q->where($column, $data[$filter]);
            }
        }
        if (! empty($data['from'])) {
            $q->where('order_date', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $q->where('order_date', '<=', $data['to']);
        }

        return $q;
    }
}
