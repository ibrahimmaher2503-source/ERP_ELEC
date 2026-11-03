<?php

namespace App\Modules\FieldMaintenance\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MaintenanceBilling
{
    public function handoff(int $actor, int $work): ?array
    {
        $w = DB::table('maintenance_work_orders')->find($work);
        abort_unless($w, 404);

        return app(ReceivablesPayablesApi::class)->serviceBillingHandoff($actor, $work, $w->branch_id);
    }

    public function recognizeLabor(int $actor, int $work, int $entry): int
    {
        return DB::transaction(function () use ($actor, $work, $entry) {
            $a = app(MaintenanceActions::class);
            $w = $a->work($actor, $work, 'rate_approve');
            $a->project($actor, $w);
            abort_unless(in_array($w->status, ['in_progress', 'work_completed', 'verified']), 409);
            $labor = DB::table('maintenance_labor_entries')->where('work_order_id', $work)->find($entry);
            abort_unless($labor && $labor->rate_id !== null && $labor->cost !== null, 409, 'لا يوجد مرجع تكلفة معتمد لهذا الوقت.');
            $old = DB::table('maintenance_labor_recognitions')->where('labor_entry_id', $entry)->first();
            if ($old) {
                return $old->journal_id;
            }
            $journal = app(AccountingApi::class)->postSourceDocument($actor, $labor->created_by, $w->branch_id, now('Africa/Cairo')->toDateString(), 'maintenance-labor:'.$entry, 'تكلفة عمل صيانة فعلية #'.$entry, [['purpose' => 'maintenance_labor', 'debit' => $labor->cost, 'credit' => '0.00'], ['purpose' => 'maintenance_labor_accrual', 'debit' => '0.00', 'credit' => $labor->cost]], $w->project_id);
            DB::table('maintenance_labor_recognitions')->insert(['labor_entry_id' => $entry, 'journal_id' => $journal, 'created_by' => $actor]);
            $a->touch($actor, $w, 'labor.recognized', ['labor_id' => $entry, 'journal_id' => $journal]);

            return $journal;
        }, 5);
    }

    public function linkExpense(int $actor, int $work, int $journal, string $reference): void
    {
        Validator::make(compact('reference'), ['reference' => 'required|string|min:5|max:2000'], [], __('maintenance.fields', [], 'ar'))->validate();
        DB::transaction(function () use ($actor, $work, $journal, $reference) {
            $a = app(MaintenanceActions::class);
            $w = $a->work($actor, $work, 'rate_approve');
            $a->project($actor, $w);
            abort_if(in_array($w->status, ['closed', 'cancelled']), 409);
            $source = app(AccountingApi::class)->claimServiceExpense($actor, $journal, $w->branch_id, $w->project_id, 'FieldMaintenance', $work);
            DB::table('maintenance_expense_sources')->insert(['work_order_id' => $work, 'journal_id' => $journal, 'amount' => $source['amount'], 'reference' => $reference, 'created_by' => $actor]);
            $a->touch($actor, $w, 'expense.linked', $source + ['reference' => $reference]);
        }, 5);
    }

    public function approve(int $actor, int $proposer, int $work, int $version, array $input): int
    {
        $d = Validator::make($input, ['product_id' => 'required|integer|min:1', 'amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'], 'date' => 'required|date_format:Y-m-d', 'price_reference' => 'required|string|min:5|max:2000'], [], __('maintenance.fields', [], 'ar'))->validate();

        return DB::transaction(function () use ($actor, $proposer, $work, $version, $d) {
            $a = app(MaintenanceActions::class);
            $w = $a->work($actor, $work, 'billing_approve', $version);
            abort_unless($w->billable && in_array($w->status, ['work_completed', 'verified']) && $actor !== $proposer, 409, 'يلزم عمل مكتمل واعتماد تجاري مستقل.');
            app(MaintenanceAccess::class)->authorize($proposer, $w->branch_id, 'billing_request');
            $a->project($actor, $w);
            $proposal = app(MaintenanceCostProposals::class)->matching($actor, $proposer, 'service', $w->branch_id, $work, $d);
            $p = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $d['product_id']))->product;
            abort_unless($p['active'] && $p['kind'] === 'service' && bccomp($d['amount'], '0', 2) > 0, 422, 'اختر خدمة فعلية وسعرًا معتمدًا.');
            $tax = app(AccountingApi::class)->calculateTax($actor, 'sale', $d['date'], 'exclusive', [['product_id' => (int) $d['product_id'], 'quantity' => '1', 'unit_price' => $d['amount'], 'discount_amount' => '0.00']]);
            $line = $tax['lines'][0];
            $line['approved_tax_date'] = $d['date'];
            $id = DB::table('maintenance_commercial_approvals')->insertGetId(['work_order_id' => $work, 'product_id' => $d['product_id'], 'net_amount' => $line['net'], 'tax_amount' => $line['tax'], 'tax_snapshot' => json_encode($line, JSON_THROW_ON_ERROR), 'price_reference' => $d['price_reference'], 'proposed_by' => $proposer, 'approved_by' => $actor, 'proposal_id' => $proposal]);
            $a->touch($actor, $w, 'billing.approved', ['approval_id' => $id, 'price_reference' => $d['price_reference']]);

            return $id;
        }, 5);
    }

    public function request(int $actor, int $work, string $key, string $date, string $due): int
    {
        return app(ReceivablesPayablesApi::class)->createServiceInvoice($actor, ['work_order_id' => $work, 'creation_key' => $key, 'invoice_date' => $date, 'due_date' => $due]);
    }

    public function invoice(int $actor, int $work): ?array
    {
        $w = DB::table('maintenance_work_orders')->find($work);
        abort_unless($w, 404);
        app(MaintenanceAccess::class)->authorize($actor, $w->branch_id);

        return app(ReceivablesPayablesApi::class)->serviceInvoiceStatus($actor, $work, $w->branch_id);
    }

    public function costs(int $actor, int $work): array
    {
        $w = DB::table('maintenance_work_orders')->find($work);
        abort_unless($w, 404);
        app(MaintenanceAccess::class)->authorize($actor, $w->branch_id, 'cost_view');
        $requirements = DB::table('maintenance_part_requirements')->where('work_order_id', $work)->pluck('id');
        $issues = DB::table('maintenance_part_issues')->whereIn('requirement_id', $requirements);
        $partCost = (string) (clone $issues)->sum('amount');
        $returned = (string) DB::table('maintenance_part_returns')->whereIn('issue_id', (clone $issues)->select('id'))->sum('amount');
        $labor = DB::table('maintenance_labor_entries')->where('work_order_id', $work);
        $supportedLabor = bcadd((string) (clone $labor)->sum('cost'), '0', 2);
        $laborCost = (clone $labor)->whereNull('cost')->exists() ? null : $supportedLabor;
        $parts = bcsub($partCost, $returned, 2);
        $other = (string) DB::table('maintenance_expense_sources')->where('work_order_id', $work)->sum('amount');
        $commercial = DB::table('maintenance_commercial_approvals')->where('work_order_id', $work)->first();

        return ['parts' => $parts, 'labor' => $laborCost, 'supported_labor' => $supportedLabor, 'billable' => $w->billable, 'billable_gross' => $commercial ? bcadd($commercial->net_amount, $commercial->tax_amount, 2) : null, 'minutes' => (int) (clone $labor)->sum('duration_minutes'), 'supported_total' => bcadd(bcadd($parts, $supportedLabor, 2), $other, 2), 'complete' => $laborCost !== null, 'estimated' => $w->estimated_cost, 'other_expenses' => $other, 'project_id' => $w->project_id];
    }
}
