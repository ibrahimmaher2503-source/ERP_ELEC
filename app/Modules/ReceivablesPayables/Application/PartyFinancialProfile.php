<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** M12 owns all debt arithmetic; M03 only renders this projection. */
final class PartyFinancialProfile
{
    public function read(int $actor, string $type, int $party, array $filters = []): array
    {
        return $this->snapshot($actor, $type, $party, $filters, false);
    }

    public function export(int $actor, string $type, int $party, array $filters = []): array
    {
        return $this->snapshot($actor, $type, $party, $filters, true);
    }

    private function snapshot(int $actor, string $type, int $party, array $filters, bool $export): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->projection($actor, $type, $party, $filters, $export);
        }

        return DB::transaction(function () use ($actor, $type, $party, $filters, $export) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $this->projection($actor, $type, $party, $filters, $export);
        });
    }

    private function projection(int $actor, string $type, int $party, array $filters, bool $export): array
    {
        abort_unless(in_array($type, ['customer', 'supplier'], true) && $party > 0, 422);
        if ($type === 'customer') {
            app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, $party, false));
        } else {
            app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, $party, false));
        }
        Validator::make($filters, ['from' => 'nullable|date_format:Y-m-d', 'to' => array_filter(['nullable', 'date_format:Y-m-d', ! empty($filters['from']) ? 'after_or_equal:from' : null]),
            'branch' => 'nullable|integer|min:1', 'invoices_page' => 'sometimes|integer|min:1|max:100000',
            'movements_page' => 'sometimes|integer|min:1|max:100000', 'statement_page' => 'sometimes|integer|min:1|max:100000'])->validate();
        $access = app(ReceivablesPayablesAccess::class);
        $branches = $access->branches($actor, $type === 'supplier' ? 'ap_view' : 'ar_view');
        $ids = array_map('intval', array_column($branches, 'id'));
        if (! $ids) {
            return ['authorized' => false];
        }
        if (! empty($filters['branch'])) {
            abort_unless(in_array((int) $filters['branch'], $ids, true), 403);
            $ids = [(int) $filters['branch']];
        }
        $open = DB::table('rp_open_items')->where('party_type', $type)->where('party_id', $party)->whereIn('branch_id', $ids);
        $invoiceBranches = array_map('intval', array_column($access->branches($actor, $type.'_invoice_view'), 'id'));
        $invoices = DB::table('rp_'.$type.'_invoices as i')->where('i.'.$type.'_id', $party)->whereIn('i.branch_id', $ids);
        $invoices->where(fn ($q) => $q->whereNotNull('i.posted_at')->orWhereIn('i.branch_id', $invoiceBranches));
        $invoiceTotals = (clone $invoices)->selectRaw('count(*) filter (where i.posted_at is not null) as posted_count, count(*) filter (where i.posted_at is null) as pending_count, coalesce(sum(i.total_amount) filter (where i.posted_at is not null),0)::numeric(20,2) as posted_total, max(i.invoice_date) filter (where i.posted_at is not null) as last_date')->first();
        $today = now('Africa/Cairo')->toDateString();
        $totals = (clone $open)->selectRaw('coalesce(sum(original_amount),0)::numeric(20,2) as original, coalesce(sum(settled_amount),0)::numeric(20,2) as settled, coalesce(sum(credited_amount),0)::numeric(20,2) as credited, coalesce(sum(remaining_amount),0)::numeric(20,2) as remaining, coalesce(sum(remaining_amount) filter (where due_date < ?),0)::numeric(20,2) as overdue', [$today])->first();
        $credits = DB::table('rp_credit_notes')->where('kind', $type)->where('party_id', $party)->whereIn('branch_id', $ids)->where('status', 'posted');
        $creditTotal = $this->money((clone $credits)->sum('amount'));
        $partyCredit = $this->money(DB::table('rp_party_credits')->where('party_type', $type)->where('party_id', $party)->whereIn('branch_id', $ids)->sum('remaining_amount'));
        $advance = $type === 'customer' ? $this->money(DB::table('rp_customer_advances')->where('customer_id', $party)->whereIn('branch_id', $ids)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rp_cash_reversals')->whereColumn('original_movement_id', 'rp_customer_advances.movement_id'))->sum('original_amount')) : null;
        if ($type === 'customer') {
            $advanceIds = DB::table('rp_customer_advances')->where('customer_id', $party)->whereIn('branch_id', $ids)->select('id');
            $advance = bcsub(bcsub($advance, (string) DB::table('rp_customer_advance_applications')->whereIn('advance_id', clone $advanceIds)->sum('amount'), 2),
                (string) DB::table('rp_customer_advance_refunds')->whereIn('advance_id', clone $advanceIds)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rp_cash_reversals')->whereColumn('original_movement_id', 'rp_customer_advance_refunds.movement_id'))->sum('amount'), 2);
        }
        // Cutover registrations are immutable evidence, not settlement-enabled open items.
        // Surface their missing handoff separately instead of inventing a second AP balance.
        $unmappedOpening = $type === 'supplier' ? $this->money(DB::table('payable_opening_items as p')->where('p.supplier_id', $party)->whereIn('p.branch_id', $ids)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rp_open_items as o')->where('o.source_type', 'opening_payable')->whereColumn('o.source_id', 'p.id'))
            ->sum('p.amount')) : '0.00';
        $aging = (clone $open)->where('remaining_amount', '>', '0')->selectRaw("case when due_date >= ?::date then 'current' when ?::date-due_date <= 30 then '1_30' when ?::date-due_date <= 60 then '31_60' when ?::date-due_date <= 90 then '61_90' else 'over_90' end as bucket, sum(remaining_amount)::numeric(20,2) as amount", [$today, $today, $today, $today])->groupBy('bucket')->pluck('amount', 'bucket')->all();
        $invoiceRows = (clone $invoices)->leftJoin('rp_open_items as o', function ($join) use ($type) {
            $join->on('o.source_id', '=', 'i.id')->where('o.source_type', $type.'_invoice');
        })->orderByDesc('i.invoice_date')->orderByDesc('i.id')->paginate(10, ['i.id', 'i.branch_id', 'i.official_number', 'i.invoice_date', 'i.due_date', 'i.total_amount', 'i.status', 'i.posted_at', 'o.settled_amount', 'o.credited_amount', 'o.remaining_amount'], 'invoices', $filters['invoices_page'] ?? 1);
        $invoiceRows->getCollection()->transform(function ($row) use ($invoiceBranches) {
            $row->can_open = in_array((int) $row->branch_id, $invoiceBranches, true);

            return $row;
        });
        $movements = app(TreasuryApi::class)->partyMovements($actor, $type, $party, $ids, $filters['movements_page'] ?? 1);
        if ($movements['authorized']) {
            $movementIds = array_column($movements['rows']['items'], 'id');
            $allocations = DB::table('rp_settlement_allocations as a')->joinSub(clone $open, 'o', 'o.id', '=', 'a.open_item_id')
                ->whereIn('a.movement_id', $movementIds)->groupBy('a.movement_id')->selectRaw('a.movement_id, sum(a.amount)::numeric(20,2) as amount')->pluck('amount', 'movement_id');
            foreach ($movements['rows']['items'] as $row) {
                $row->allocated_amount = $allocations[$row->id] ?? '0.00';
                $row->unallocated_amount = bcsub($row->amount, $row->allocated_amount, 2);
            }
        }
        $statement = $this->statement($open, $credits, $filters, $export);

        return ['authorized' => true, 'branches' => $branches, 'currency' => 'EGP', 'as_of' => $today,
            'summary' => ['posted_invoice_count' => (int) $invoiceTotals->posted_count, 'pending_invoice_count' => (int) $invoiceTotals->pending_count,
                'posted_invoices' => $invoiceTotals->posted_total, 'credit_notes' => $creditTotal, 'allocated_settlements' => $totals->settled,
                'outstanding' => $totals->remaining, 'overdue' => $totals->overdue, 'unapplied_credits' => $partyCredit,
                'net_outstanding' => bcsub($totals->remaining, $partyCredit, 2), 'customer_advances' => $advance,
                'unmapped_opening_payables' => $unmappedOpening,
                'last_invoice_date' => $invoiceTotals->last_date, 'last_movement_date' => $movements['last_date'] ?? null],
            'aging' => $aging, 'invoices' => $this->page($invoiceRows), 'movements' => $movements, 'statement' => $statement];
    }

    private function statement(Builder $open, Builder $credits, array $filters, bool $export): array
    {
        // Recognition dates come from immutable M12 event timestamps in Cairo.
        // Treasury cash dates/amounts remain separate, and advances do not reduce AR.
        $issues = (clone $open)->selectRaw("id, 1 as event_order, source_id, source_type as kind, source_number as reference, created_at as recorded_at, (created_at at time zone 'Africa/Cairo')::date as date, original_amount as increase, 0::numeric as decrease");
        $notes = (clone $credits)->selectRaw("id, 2 as event_order, original_invoice_id as source_id, 'credit_note' as kind, official_number as reference, posted_at as recorded_at, (posted_at at time zone 'Africa/Cairo')::date as date, 0::numeric as increase, amount as decrease");
        $allocations = DB::table('rp_settlement_allocations as a')->joinSub(clone $open, 'o', 'o.id', '=', 'a.open_item_id')
            ->selectRaw("a.id, 3 as event_order, a.movement_id as source_id, 'settlement' as kind, o.source_number as reference, a.created_at as recorded_at, (a.created_at at time zone 'Africa/Cairo')::date as date, 0::numeric as increase, a.amount as decrease");
        $applications = DB::table('rp_customer_advance_application_lines as l')->join('rp_customer_advance_applications as a', 'a.id', '=', 'l.application_id')->joinSub(clone $open, 'o', 'o.id', '=', 'l.open_item_id')
            ->selectRaw("l.id, 4 as event_order, a.id as source_id, 'advance_application' as kind, o.source_number as reference, a.created_at as recorded_at, a.effective_date as date, 0::numeric as increase, l.amount as decrease");
        $refunds = DB::table('rp_party_credit_refunds as r')->join('rp_party_credits as c', 'c.id', '=', 'r.credit_id')
            ->joinSub(clone $credits, 'n', 'n.id', '=', 'c.credit_note_id')
            ->selectRaw("r.id, 5 as event_order, r.movement_id as source_id, 'credit_refund' as kind, n.official_number as reference, r.created_at as recorded_at, r.refund_date as date, r.amount as increase, 0::numeric as decrease");
        $settlementReversals = DB::table('rp_cash_reversals as r')->join('rp_settlement_allocations as a', 'a.movement_id', '=', 'r.original_movement_id')
            ->joinSub(clone $open, 'o', 'o.id', '=', 'a.open_item_id')
            ->selectRaw("a.id, 6 as event_order, r.movement_id as source_id, 'settlement_reversal' as kind, o.source_number as reference, r.created_at as recorded_at, r.effective_date as date, a.amount as increase, 0::numeric as decrease");
        $refundReversals = DB::table('rp_cash_reversals as r')->join('rp_party_credit_refunds as f', 'f.movement_id', '=', 'r.original_movement_id')
            ->join('rp_party_credits as c', 'c.id', '=', 'f.credit_id')->joinSub(clone $credits, 'n', 'n.id', '=', 'c.credit_note_id')
            ->selectRaw("r.id, 7 as event_order, r.movement_id as source_id, 'credit_refund_reversal' as kind, n.official_number as reference, r.created_at as recorded_at, r.effective_date as date, 0::numeric as increase, f.amount as decrease");
        $events = DB::query()->fromSub($issues->unionAll($notes)->unionAll($allocations)->unionAll($applications)->unionAll($refunds)->unionAll($settlementReversals)->unionAll($refundReversals), 'events');
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $opening = $from ? $this->money((clone $events)->where('date', '<', $from)->selectRaw('coalesce(sum(increase-decrease),0)::numeric(20,2) as balance')->value('balance')) : '0.00';
        $range = (clone $events)->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to));
        $change = $this->money((clone $range)->selectRaw('coalesce(sum(increase-decrease),0)::numeric(20,2) as balance')->value('balance'));
        $window = $range->selectRaw('events.*, (?::numeric + sum(increase-decrease) over (order by recorded_at, event_order, id rows unbounded preceding))::numeric(20,2) as balance', [$opening]);
        $rows = DB::query()->fromSub($window, 'statement')->orderBy('recorded_at')->orderBy('event_order')->orderBy('id')
            ->paginate($export ? 9999 : 20, ['*'], 'statement', $export ? 1 : ($filters['statement_page'] ?? 1));
        if ($export && $rows->total() > 9998) {
            throw ValidationException::withMessages(['export' => 'الكشف يتجاوز حد التصدير؛ اختر فترة أقصر لا تزيد على 9998 حركة.']);
        }

        return ['opening' => $opening, 'closing' => bcadd($opening, $change, 2), 'rows' => $this->page($rows), 'basis' => 'm12_recognition_cairo'];
    }

    private function money(mixed $amount): string
    {
        return bcadd((string) $amount, '0', 2);
    }

    private function page($paginator): array
    {
        return ['items' => $paginator->items(), 'page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()];
    }
}
