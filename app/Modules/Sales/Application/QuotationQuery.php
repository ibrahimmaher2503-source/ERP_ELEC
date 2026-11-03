<?php

namespace App\Modules\Sales\Application;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Sales\Domain\Quotation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class QuotationQuery
{
    public function query(int $actor, array $filters = [])
    {
        app(QuotationAccess::class)->authorize($actor);
        $f = Validator::make($filters, [
            'search' => ['nullable', 'string', 'max:200'], 'customer' => ['nullable', 'integer', 'min:1'],
            'project' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in(['draft', 'pending_approval', 'issued', 'sent', 'accepted', 'cancelled', 'expired', 'rejected'])],
            'conversion' => ['nullable', Rule::in(['not_converted', 'partial', 'full'])], 'representative' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        $q = Quotation::query();
        $branches = app(OrganizationApi::class)->projectScopedBranches($actor);
        $q->whereNotIn('id', DB::table('sales_tender_sources')->whereNotIn('branch_id', $branches)->select('quotation_id'));
        if ($f['search'] ?? '') {
            $q->where(fn ($q) => $q->where('number', 'ilike', '%'.$f['search'].'%')->orWhere('draft_key', 'ilike', '%'.$f['search'].'%')->orWhere('customer_snapshot->name', 'ilike', '%'.$f['search'].'%')->orWhere('project_name', 'ilike', '%'.$f['search'].'%')->orWhere('rfq_reference', 'ilike', '%'.$f['search'].'%'));
        }
        foreach (['customer' => 'customer_id', 'status' => 'status', 'representative' => 'created_by'] as $key => $column) {
            if ($f[$key] ?? '') {
                $q->where($column, $f[$key]);
            }
        }
        if ($f['project'] ?? '') {
            $q->where('project_name', 'ilike', '%'.$f['project'].'%');
        }
        if ($f['from'] ?? '') {
            $q->whereDate('quotation_date', '>=', $f['from']);
        }
        if ($f['to'] ?? '') {
            $q->whereDate('quotation_date', '<=', $f['to']);
        }
        if (($f['conversion'] ?? '') === 'full') {
            $q->whereIn('id', DB::table('sales_quotation_conversions')->select('quotation_id'));
        } elseif (($f['conversion'] ?? '') === 'not_converted') {
            $q->whereNotIn('id', DB::table('sales_quotation_conversions')->select('quotation_id'));
        } elseif (($f['conversion'] ?? '') === 'partial') {
            $q->whereRaw('FALSE');
        }

        return $q;
    }
}
