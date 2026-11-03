<?php

namespace App\Modules\WorkshopRepairs\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
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

    public function requestInvoice(int $actor, int $job, string $key, string $date, string $due): int
    {
        return app(ReceivablesPayablesApi::class)->createWorkshopInvoice($actor, ['job_id' => $job, 'creation_key' => $key, 'invoice_date' => $date, 'due_date' => $due]);
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
