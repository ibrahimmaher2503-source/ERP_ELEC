<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\System\Application\RecordAudit;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ContractInvoices
{
    public function create(int $actor, array $data): int
    {
        $d = Validator::make($data, ['billing_source_id' => 'required|integer|min:1', 'creation_key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $d) {
            app(AccountingApi::class)->lockPosting();
            $s = app(ServiceContractsApi::class)->billingSource($actor, (int) $d['billing_source_id']);
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'customer_invoice_manage', $s['branch_id']);
            $old = DB::table('rp_customer_invoices')->where('service_contract_source_id', $d['billing_source_id'])->orWhere('creation_key', $d['creation_key'])->first();
            if ($old) {
                abort_unless((int) $old->service_contract_source_id === (int) $d['billing_source_id'] && (int) $old->service_contract_id === $s['contract_id'], 409);

                return (int) $old->id;
            }
            $customer = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, $s['customer_id']));
            app(AccountingApi::class)->requirePostingDate($s['invoice_date']);
            $id = DB::table('rp_customer_invoices')->insertGetId(['creation_key' => $d['creation_key'], 'branch_id' => $s['branch_id'], 'customer_id' => $s['customer_id'], 'customer_name' => $customer->name,
                'service_contract_id' => $s['contract_id'], 'service_contract_source_id' => $d['billing_source_id'], 'invoice_date' => $s['invoice_date'], 'due_date' => $s['due_date'],
                'net_amount' => $s['net_amount'], 'tax_amount' => $s['tax_amount'], 'total_amount' => $s['total_amount'], 'created_by' => $actor, 'source_snapshot' => json_encode($s, JSON_THROW_ON_ERROR)]);
            foreach ($s['lines'] as $line) {
                DB::table('rp_customer_invoice_lines')->insert(['invoice_id' => $id, 'service_contract_source_id' => $d['billing_source_id'], 'product_id' => $line['product_id'], 'description' => $line['description'],
                    'quantity' => $line['quantity'], 'net_amount' => $line['net_amount'], 'tax_amount' => $line['tax_amount'], 'total_amount' => $line['total_amount'], 'tax_rule_id' => $line['tax']['tax_rule_id'], 'tax_snapshot' => json_encode($line['tax'], JSON_THROW_ON_ERROR)]);
            }
            app(RecordAudit::class)->execute($actor, 'customer_invoice', $id, 'contract_invoice.created', null, ['contract_id' => $s['contract_id'], 'source_id' => $d['billing_source_id']], 'succeeded', 'ReceivablesPayables');

            return $id;
        }, 3);
    }

    public function validateSource(int $actor, object $invoice): void
    {
        $s = json_decode($invoice->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
        app(ServiceContractsApi::class)->validateBillingEvidence($actor, (int) $invoice->service_contract_source_id, (int) $invoice->branch_id, (int) $invoice->customer_id);
        abort_unless(($s['contract_id'] ?? null) === (int) $invoice->service_contract_id && ($s['billing_source_id'] ?? null) === (int) $invoice->service_contract_source_id
            && ($s['net_amount'] ?? null) === $invoice->net_amount && ($s['tax_amount'] ?? null) === $invoice->tax_amount && ($s['total_amount'] ?? null) === $invoice->total_amount, 409);
    }

    public function revenuePurpose(object $invoice): string
    {
        if ($invoice->service_contract_source_id === null) {
            return 'sales';
        }
        $s = json_decode($invoice->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(in_array($s['revenue_purpose'] ?? null, ['sales', 'service_contract_deferred']), 409);

        return $s['revenue_purpose'];
    }

    private function invoices(int $contract, int $branch, string $to)
    {
        return DB::table('rp_customer_invoices')->where('service_contract_id', $contract)->where('branch_id', $branch)->whereIn('status', ['posted', 'partially_collected', 'collected', 'credited'])->where('invoice_date', '<=', $to);
    }

    public function summary(int $actor, int $contract, int $branch, ?string $to): array
    {
        app(ReceivablesPayablesAccess::class)->authorize($actor, 'ar_view', $branch);
        $to ??= now('Africa/Cairo')->toDateString();
        Validator::make(compact('to'), ['to' => 'required|date_format:Y-m-d'])->validate();
        $rows = $this->invoices($contract, $branch, $to)->get();
        $invoiced = $credited = $collected = $remaining = $deferred = '0.00';
        foreach ($rows as $invoice) {
            $notes = DB::table('rp_credit_notes')->where('kind', 'customer')->where('original_invoice_id', $invoice->id)->where('status', 'posted')->where('note_date', '<=', $to);
            $credit = (string) (clone $notes)->sum('amount');
            $movements = app(TreasuryApi::class)->settlementMovementIds($actor, 'customer', (int) $invoice->customer_id, $branch, $to);
            $paid = (string) DB::table('rp_settlement_allocations as a')->join('rp_open_items as i', 'i.id', '=', 'a.open_item_id')->where('i.source_type', 'customer_invoice')->where('i.source_id', $invoice->id)->whereIn('a.movement_id', $movements)->sum('a.amount');
            $applied = (string) DB::table('rp_customer_advance_application_lines as l')->join('rp_customer_advance_applications as a', 'a.id', '=', 'l.application_id')
                ->join('rp_open_items as i', 'i.id', '=', 'l.open_item_id')->where('i.source_type', 'customer_invoice')->where('i.source_id', $invoice->id)->where('a.effective_date', '<=', $to)->sum('l.amount');
            $paid = bcadd($paid, $applied, 2);
            $invoiced = bcadd($invoiced, $invoice->total_amount, 2);
            $credited = bcadd($credited, $credit, 2);
            $collected = bcadd($collected, $paid, 2);
            $balance = bcsub(bcsub($invoice->total_amount, $credit, 2), $paid, 2);
            $remaining = bcadd($remaining, bccomp($balance, '0', 2) > 0 ? $balance : '0.00', 2);
            if ($this->revenuePurpose($invoice) === 'service_contract_deferred') {
                $deferred = bcadd($deferred, bcsub($invoice->net_amount, (string) (clone $notes)->sum('net_amount'), 2), 2);
            }
        }

        return compact('invoiced', 'credited', 'collected', 'remaining') + ['deferred_billed' => $deferred];
    }

    public function deferred(int $actor, array $branches, string $to): string
    {
        Validator::make(compact('to'), ['to' => 'required|date_format:Y-m-d'])->validate();
        foreach ($branches as $branch) {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.report_view', (int) $branch));
        }
        $invoices = DB::table('rp_customer_invoices')->whereIn('branch_id', $branches)->whereNotNull('service_contract_source_id')->whereIn('status', ['posted', 'partially_collected', 'collected', 'credited'])->where('invoice_date', '<=', $to)->whereRaw("source_snapshot->>'revenue_purpose' = 'service_contract_deferred'");
        $total = (string) (clone $invoices)->sum('net_amount');
        $credits = (string) DB::table('rp_credit_notes')->where('kind', 'customer')->whereIn('original_invoice_id', (clone $invoices)->select('id'))->where('status', 'posted')->where('note_date', '<=', $to)->sum('net_amount');

        return bcsub($total, $credits, 2);
    }
}
