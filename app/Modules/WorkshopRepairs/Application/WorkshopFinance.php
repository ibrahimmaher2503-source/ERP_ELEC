<?php

namespace App\Modules\WorkshopRepairs\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class WorkshopFinance
{
    public function invoice(int $actor, int $job): ?array
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize($actor, $w->branch_id);

        return app(ReceivablesPayablesApi::class)->workshopBillingHandoff($actor, $job, $w->branch_id);
    }

    public function ledger(int $actor, int $job): array
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize($actor, $w->branch_id);

        return app(ReceivablesPayablesApi::class)->workshopFinancialLedger($actor, $job, (int) $w->branch_id);
    }

    public function requestInvoice(int $actor, int $job, string $key, string $date, string $due): int
    {
        return app(ReceivablesPayablesApi::class)->createWorkshopInvoice($actor, ['job_id' => $job, 'creation_key' => $key, 'invoice_date' => $date, 'due_date' => $due]);
    }

    public function collectDeposit(int $actor, int $job, string $key, array $input): array
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        abort_unless($w->customer_id !== null && in_array($w->billing_mode, ['billable', 'partial'], true), 422, 'العربون متاح لأوامر إصلاح العملاء المدفوعة فقط.');
        app(WorkshopAccess::class)->authorize($actor, (int) $w->branch_id);

        $res = app(TreasuryApi::class)->executeCustomerCollection($actor, [
            'idempotency_key' => $key,
            'branch_id' => (int) $w->branch_id,
            'customer_id' => (int) $w->customer_id,
            'account_id' => (int) ($input['account_id'] ?? 0),
            'date' => (string) ($input['date'] ?? ''),
            'amount' => (string) ($input['amount'] ?? ''),
            'reference' => (string) ($input['reference'] ?? ''),
            'allow_advance' => true,
            'workshop_job_id' => $job,
        ], []);

        app(WorkshopActions::class)->audit($actor, (int) $w->intake_id, (int) $w->id, 'deposit.collected', [
            'movement_id' => $res['movement_id'],
            'amount' => (string) ($input['amount'] ?? ''),
            'reference' => (string) ($input['reference'] ?? ''),
        ]);

        return $res;
    }

    public function applyDeposit(int $actor, int $job, int $advanceId, string $key, string $date, string $amount): array
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize($actor, (int) $w->branch_id);
        $invoice = $this->invoice($actor, $job);
        abort_unless($invoice && ($invoice['balance_available'] ?? false) && ! empty($invoice['open_item_id']), 409, 'تطبيق العربون يتطلب فاتورة مرحلة وذمة عميل مفتوحة.');

        $res = app(ReceivablesPayablesApi::class)->applyCustomerAdvance($actor, $advanceId, $key, $date, [
            ['open_item_id' => (int) $invoice['open_item_id'], 'amount' => $amount],
        ]);

        app(WorkshopActions::class)->audit($actor, (int) $w->intake_id, (int) $w->id, 'deposit.applied', [
            'advance_id' => $advanceId,
            'application_id' => $res['application_id'],
            'amount' => $res['amount'],
        ]);

        return $res;
    }

    public function refundDeposit(int $actor, int $job, int $advanceId, int $accountId, string $amount, string $date, string $key, string $reference): array
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize($actor, (int) $w->branch_id);

        $res = app(TreasuryApi::class)->refundCustomerAdvance($actor, $advanceId, $accountId, $amount, $date, $key, $reference);

        app(WorkshopActions::class)->audit($actor, (int) $w->intake_id, (int) $w->id, 'deposit.refunded', [
            'advance_id' => $advanceId,
            'movement_id' => $res['movement_id'],
            'amount' => $amount,
            'reference' => $reference,
        ]);

        return $res;
    }

    public function costs(int $actor, int $job): array
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize($actor, $w->branch_id, 'cost_view');
        $r = DB::table('workshop_part_requirements')->where('job_id', $job)->select('id');
        $i = DB::table('workshop_part_issues')->whereIn('requirement_id', $r);
        $parts = bcsub((string) (clone $i)->sum('amount'), (string) DB::table('workshop_part_returns')->whereIn('issue_id', (clone $i)->select('id'))->sum('amount'), 2);
        $l = DB::table('workshop_labor_entries')->where('job_id', $job);
        $labor = bcadd((string) (clone $l)->sum('cost'), '0', 2);
        $missing = (clone $l)->whereNull('cost')->exists();
        $other = bcadd((string) DB::table('workshop_expense_sources')->where('job_id', $job)->sum('amount'), '0', 2);

        return ['parts' => $parts, 'supported_labor' => $labor, 'labor' => $missing ? null : $labor, 'minutes' => (int) (clone $l)->sum('duration_minutes'), 'other' => $other, 'supported_total' => bcadd(bcadd($parts, $labor, 2), $other, 2), 'complete' => ! $missing];
    }

    public function profitability(int $actor, int $job): array
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        $costs = $this->costs($actor, $job);
        $latestEstimate = DB::table('workshop_estimates')->where('job_id', $job)->orderByDesc('revision')->first();
        $cancellation = DB::table('workshop_cancellations')->where('job_id', $job)->first();
        $accepted = $latestEstimate ? DB::table('workshop_estimate_approvals')->where('estimate_id', $latestEstimate->id)->where('customer_accepted', true)->exists() : false;
        $canHandoff = app(WorkshopAccess::class)->billingHandoff($actor, $w->branch_id);
        $invoice = $canHandoff ? $this->invoice($actor, $job) : null;
        $ledger = $canHandoff ? $this->ledger($actor, $job) : ['available' => false, 'totals' => null];

        if ($invoice && ($invoice['net'] ?? null) !== null) {
            $sellingNet = bcadd((string) $invoice['net'], '0', 2);
            $taxAmount = bcadd((string) ($invoice['tax'] ?? '0.00'), '0', 2);
            $sellingSource = $invoice['number'] ? 'الفاتورة الرسمية '.$invoice['number'] : 'فاتورة العميل #'.$invoice['id'];
        } elseif ($cancellation && bccomp((string) $cancellation->fee_amount, '0', 2) > 0) {
            $sellingNet = bcadd((string) $cancellation->fee_amount, '0', 2);
            $taxAmount = bcadd((string) $cancellation->tax_amount, '0', 2);
            $sellingSource = 'رسوم تشخيص/إلغاء متفق عليها';
        } elseif ($latestEstimate && in_array($w->billing_mode, ['billable', 'partial'], true)) {
            $sellingNet = bcadd((string) $latestEstimate->billable_amount, '0', 2);
            $taxAmount = bcadd((string) $latestEstimate->tax_amount, '0', 2);
            $sellingSource = $accepted
                ? 'العرض المقبول (مراجعة '.$latestEstimate->revision.')'
                : 'العرض المقترح (مراجعة '.$latestEstimate->revision.' — لم يُعتمد نهائيًا بعد)';
        } else {
            $sellingNet = null;
            $taxAmount = null;
            $sellingSource = match ($w->billing_mode) {
                'warranty' => 'إصلاح ضمان (غير مفوتر للعميل)',
                'nonbillable' => 'إصلاح داخلي / مجاملة (غير مفوتر للعميل)',
                'contract' => 'تغطية ضمن عقد الخدمة',
                default => 'لم يُعدّ عرض سعر بعد',
            };
        }

        $profit = $sellingNet !== null ? bcsub($sellingNet, $costs['supported_total'], 2) : null;
        $cashAvailable = (bool) (($invoice && ($invoice['balance_available'] ?? false)) || ($ledger['available'] ?? false));
        $invoiceTotal = ($invoice && ($invoice['balance_available'] ?? false)) ? bcadd((string) $invoice['total'], '0', 2) : null;
        $remaining = $invoiceTotal !== null ? ($invoice['remaining'] !== null ? bcadd((string) $invoice['remaining'], '0', 2) : $invoiceTotal) : null;
        $collected = $invoiceTotal !== null ? bcsub($invoiceTotal, $remaining, 2) : null;
        $totals = $ledger['totals'] ?? null;

        return [
            'parts_cost' => $costs['parts'],
            'labor_cost' => $costs['labor'],
            'supported_labor_cost' => $costs['supported_labor'],
            'other_cost' => $costs['other'],
            'total_cost' => $costs['supported_total'],
            'cost_complete' => $costs['complete'],
            'selling_net' => $sellingNet,
            'tax_amount' => $taxAmount,
            'selling_source' => $sellingSource,
            'profit' => $profit,
            'profit_complete' => $sellingNet !== null && $costs['complete'],
            'cash_available' => $cashAvailable,
            'invoice_total' => $invoiceTotal,
            'collected' => $collected,
            'remaining' => $remaining,
            'advances_collected' => $totals['advances_collected'] ?? '0.00',
            'advances_applied' => $totals['advances_applied'] ?? '0.00',
            'advances_refunded' => $totals['advances_refunded'] ?? '0.00',
            'advances_remaining' => $totals['advances_remaining'] ?? '0.00',
            'total_collected' => $totals['total_collected'] ?? ($collected ?? '0.00'),
            'total_refunded' => $totals['total_refunded'] ?? '0.00',
            'net_cash_received' => $totals['net_cash_received'] ?? ($collected ?? '0.00'),
            'invoice_status' => $invoice['status'] ?? null,
            'invoice_number' => $invoice['number'] ?? null,
        ];
    }

    public function recognizeLabor(int $actor, int $job, int $entry): int
    {
        return DB::transaction(function () use ($actor, $job, $entry) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'rate_approve');
            $a->project($actor, $w);
            abort_unless(in_array($w->status, ['repair', 'testing', 'verified']), 409);
            $l = DB::table('workshop_labor_entries')->where('job_id', $job)->find($entry);
            abort_unless($l && $l->rate_id !== null && $l->cost !== null && bccomp($l->cost, '0', 2) > 0, 409);
            $old = DB::table('workshop_labor_recognitions')->where('labor_entry_id', $entry)->first();
            if ($old) {
                return $old->journal_id;
            }
            $journal = app(AccountingApi::class)->postSourceDocument($actor, $l->created_by, $w->branch_id, now('Africa/Cairo')->toDateString(), 'workshop-labor:'.$entry, 'تكلفة إصلاح ورشة #'.$entry, [['purpose' => 'maintenance_labor', 'debit' => $l->cost, 'credit' => '0.00'], ['purpose' => 'maintenance_labor_accrual', 'debit' => '0.00', 'credit' => $l->cost]], $w->project_id);
            DB::table('workshop_labor_recognitions')->insert(['labor_entry_id' => $entry, 'journal_id' => $journal, 'created_by' => $actor]);
            $a->touch($actor, $w, 'labor.recognized', ['entry_id' => $entry, 'journal_id' => $journal]);

            return $journal;
        }, 5);
    }

    public function linkExpense(int $actor, int $job, int $journal, string $reference): void
    {
        Validator::make(compact('reference'), ['reference' => 'required|string|min:5|max:2000'])->validate();
        DB::transaction(function () use ($actor, $job, $journal, $reference) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'rate_approve');
            $a->project($actor, $w);
            abort_if(in_array($w->status, ['delivered', 'returned_unrepaired']), 409);
            $source = app(AccountingApi::class)->claimServiceExpense($actor, $journal, $w->branch_id, $w->project_id, 'WorkshopRepairs', $job);
            DB::table('workshop_expense_sources')->insert(['job_id' => $job, 'journal_id' => $journal, 'amount' => $source['amount'], 'reference' => $reference, 'created_by' => $actor]);
            $a->touch($actor, $w, 'expense.linked', $source);
        }, 5);
    }
}
