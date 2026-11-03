<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use Illuminate\Support\Facades\DB;

final class ReceivablesPayablesService implements ReceivablesPayablesApi
{
    public function prepareCashReversal(int $actor, int $originalMovement, string $date): void
    {
        app(CashReversals::class)->prepare($actor, $originalMovement, $date);
    }

    public function completeCashReversal(int $actor, int $originalMovement, int $movement, string $date): void
    {
        app(CashReversals::class)->complete($actor, $originalMovement, $movement, $date);
    }

    public function preparePartyCreditRefund(int $actor, int $credit, string $amount, string $date): array
    {
        return app(PartyCreditRefunds::class)->prepare($actor, $credit, $amount, $date);
    }

    public function completePartyCreditRefund(int $actor, int $credit, int $movement, string $amount, string $date, string $key): void
    {
        app(PartyCreditRefunds::class)->complete($actor, $credit, $movement, $amount, $date, $key);
    }

    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array
    {
        $metrics = [];
        foreach (['customer' => 'ar_view', 'supplier' => 'ap_view'] as $party => $permission) {
            $ids = array_column(app(ReceivablesPayablesAccess::class)->branches($actor, $permission), 'id');
            if ($ids && ($branch === null || in_array($branch, $ids, true))) {
                $metrics[$party.'_invoiced'] = bcadd((string) DB::table('rp_'.$party.'_invoices')->whereIn('branch_id', $branch === null ? $ids : [$branch])
                    ->whereNotNull('posted_at')->whereBetween('invoice_date', [$from, $to])->sum('total_amount'), '0', 2);
            }
        }
        abort_unless($metrics, 403);

        return ['scope' => 'authorized_branches', 'metrics' => $metrics, 'basis' => 'إجمالي الفواتير المرحلة في الفترة قبل الإشعارات؛ مقياس مستقل عن الإيراد والتحصيل.'];
    }

    public function applyCustomerAdvance(int $actor, int $advanceId, string $creationKey, string $date, array $allocations): array
    {
        return app(CustomerAdvances::class)->apply($actor, $advanceId, $creationKey, $date, $allocations);
    }

    public function prepareAdvanceRefund(int $actor, int $advance, string $amount, string $date): array
    {
        return app(CustomerAdvances::class)->prepareRefund($actor, $advance, $amount, $date);
    }

    public function completeAdvanceRefund(int $actor, int $advance, int $movement, string $amount, string $date, string $key): void
    {
        app(CustomerAdvances::class)->completeRefund($actor, $advance, $movement, $amount, $date, $key);
    }

    public function createContractInvoice(int $actor, array $data): int
    {
        return app(ContractInvoices::class)->create($actor, $data);
    }

    public function createContractCreditNote(int $actor, int $correction): int
    {
        return DB::transaction(function () use ($actor, $correction) {
            app(AccountingApi::class)->lockPosting();
            $data = app(ServiceContractsApi::class)->creditSource($actor, $correction);

            return app(CreditNotes::class)->create($actor, $data, $correction);
        }, 3);
    }

    public function contractFinancialSummary(int $actor, int $contract, int $branch, ?string $to = null): array
    {
        return app(ContractInvoices::class)->summary($actor, $contract, $branch, $to);
    }

    public function contractDeferredValue(int $actor, array $branches, string $to): string
    {
        return app(ContractInvoices::class)->deferred($actor, $branches, $to);
    }

    public function partyFinancialProfile(int $actor, string $partyType, int $partyId, array $filters = []): array
    {
        return app(PartyFinancialProfile::class)->read($actor, $partyType, $partyId, $filters);
    }

    public function partyStatementExport(int $actor, string $partyType, int $partyId, array $filters = []): array
    {
        return app(PartyFinancialProfile::class)->export($actor, $partyType, $partyId, $filters);
    }

    public function createWorkshopInvoice(int $actor, array $input): int
    {
        return app(WorkshopInvoices::class)->create($actor, $input);
    }

    public function workshopInvoiceStatus(int $actor, int $job, int $branch): ?array
    {
        return app(WorkshopInvoices::class)->status($actor, $job, $branch);
    }

    public function workshopBillingHandoff(int $actor, int $job, int $branch): ?array
    {
        return app(WorkshopInvoices::class)->handoff($actor, $job, $branch);
    }

    public function serviceBillingHandoff(int $actor, int $work, int $branch): ?array
    {
        return app(ServiceInvoices::class)->handoff($actor, $work, $branch);
    }

    public function createServiceInvoice(int $actor, array $data): int
    {
        return app(ServiceInvoices::class)->create($actor, $data);
    }

    public function serviceInvoiceStatus(int $actor, int $work, int $branch): ?array
    {
        return app(ServiceInvoices::class)->status($actor, $work, $branch);
    }

    public function projectSummary(int $actor, int $project, int $branch, array $purchaseOrders, array $salesOrders): array
    {
        app(ProjectsApi::class)->authorizeFinancialView($actor, $project, $branch);
        $result = ['supplier_invoiced' => '0.00', 'customer_invoiced' => '0.00', 'grni_cleared' => '0.00', 'paid' => '0.00', 'collected' => '0.00', 'ap_due' => '0.00', 'ar_due' => '0.00', 'supplier_credit' => '0.00', 'customer_credit' => '0.00', 'unallocated_invoices' => 0, 'pending_invoices' => 0, 'invoices' => [], 'credits' => []];
        foreach (['supplier', 'customer'] as $kind) {
            app(ReceivablesPayablesAccess::class)->authorize($actor, $kind === 'supplier' ? 'ap_view' : 'ar_view', $branch);
            $q = DB::table('rp_'.$kind.'_invoices')->where('branch_id', $branch)->where(function ($q) use ($kind, $project, $purchaseOrders, $salesOrders) {
                $q->where('project_id', $project);
                if ($kind === 'customer') {
                    $q->orWhereIn(DB::raw("(source_snapshot->>'order_id')::bigint"), array_map('intval', $salesOrders));
                } else {
                    foreach ($purchaseOrders as $order) {
                        $q->orWhereRaw("(source_snapshot->'purchasing'->'lines') @> ?::jsonb", [json_encode([['order_id' => (int) $order]])]);
                    }
                }
            });
            foreach ($q->orderBy('id')->get() as $i) {
                if ($i->posted_at === null) {
                    $result['pending_invoices']++;

                    continue;
                }
                $open = DB::table('rp_open_items')->where('source_type', $kind.'_invoice')->where('source_id', $i->id)->first();
                abort_unless($open, 409);
                $result[$kind.'_invoiced'] = bcadd($result[$kind.'_invoiced'], $i->total_amount, 2);
                if ($kind === 'supplier') {
                    $result['grni_cleared'] = bcadd($result['grni_cleared'], $i->grni_amount, 2);
                }
                $result[$kind === 'supplier' ? 'paid' : 'collected'] = bcadd($result[$kind === 'supplier' ? 'paid' : 'collected'], $open->settled_amount, 2);
                $result[$kind === 'supplier' ? 'ap_due' : 'ar_due'] = bcadd($result[$kind === 'supplier' ? 'ap_due' : 'ar_due'], $open->remaining_amount, 2);
                if ($i->project_id === null) {
                    $result['unallocated_invoices']++;
                }
                $result['invoices'][] = ['kind' => $kind, 'id' => $i->id, 'number' => $i->official_number, 'total' => $i->total_amount, 'settled' => $open->settled_amount, 'remaining' => $open->remaining_amount, 'attributed' => (int) $i->project_id === $project];
                foreach (DB::table('rp_credit_notes')->where('kind', $kind)->where('original_invoice_id', $i->id)->get() as $credit) {
                    if ($credit->status !== 'posted') {
                        $result['pending_invoices']++;

                        continue;
                    }
                    $result[$kind.'_credit'] = bcadd($result[$kind.'_credit'], $credit->amount, 2);
                    $result['credits'][] = ['kind' => $kind, 'id' => $credit->id, 'number' => $credit->official_number, 'amount' => $credit->amount];
                }
            }
        }

        return $result;
    }

    public function createSupplierInvoice(int $actor, array $data): int
    {
        return app(SupplierInvoices::class)->create($actor, $data);
    }

    public function validateSupplierInvoice(int $actor, int $invoice, int $version): void
    {
        app(SupplierInvoices::class)->transition($actor, $invoice, $version, 'draft', 'validated');
    }

    public function matchSupplierInvoice(int $actor, int $invoice, int $version): void
    {
        app(SupplierInvoices::class)->transition($actor, $invoice, $version, 'validated', 'matched');
    }

    public function approveSupplierInvoice(int $actor, int $invoice, int $version): void
    {
        app(SupplierInvoices::class)->approve($actor, $invoice, $version);
    }

    public function postSupplierInvoice(int $actor, int $invoice, int $version): array
    {
        return app(SupplierInvoices::class)->post($actor, $invoice, $version);
    }

    public function createCustomerInvoice(int $actor, array $data): int
    {
        return app(CustomerInvoices::class)->create($actor, $data);
    }

    public function validateCustomerInvoice(int $actor, int $invoice, int $version): void
    {
        app(CustomerInvoices::class)->validate($actor, $invoice, $version);
    }

    public function postCustomerInvoice(int $actor, int $invoice, int $version): array
    {
        return app(CustomerInvoices::class)->post($actor, $invoice, $version);
    }

    public function prepareSettlement(int $actor, string $partyType, int $partyId, array $allocations): array
    {
        return app(Settlements::class)->prepare($actor, $partyType, $partyId, $allocations);
    }

    public function completeSettlement(int $actor, int $movementId, string $partyType, array $allocations): void
    {
        app(Settlements::class)->complete($actor, $movementId, $partyType, $allocations);
    }

    public function recordCustomerAdvance(int $actor, int $movementId, int $branch, int $customer, string $amount): void
    {
        abort_unless(DB::transactionLevel() > 0 && bccomp($amount, '0', 2) > 0, 422);
        app(ReceivablesPayablesAccess::class)->authorize($actor, 'ar_view', $branch);
        DB::table('rp_customer_advances')->insert(['movement_id' => $movementId, 'branch_id' => $branch,
            'customer_id' => $customer, 'original_amount' => $amount, 'created_at' => now()]);
    }

    public function createCreditNote(int $actor, array $data): int
    {
        return app(CreditNotes::class)->create($actor, $data);
    }

    public function approveCreditNote(int $actor, int $creditNote): void
    {
        app(CreditNotes::class)->approve($actor, $creditNote);
    }

    public function postCreditNote(int $actor, int $creditNote): array
    {
        return app(CreditNotes::class)->post($actor, $creditNote);
    }

    public function reconciliationValues(int $actor, array $branchIds, ?string $to = null): array
    {
        return app(SubledgerFinancialHistory::class)->values($actor, $branchIds, $to);
    }

    public function ppvRows(int $actor, array $branchIds, ?string $from, ?string $to): array
    {
        abort_unless($actor > 0 && $branchIds !== [], 403);

        return \DB::table('rp_supplier_invoices')->whereIn('branch_id', array_map('intval', $branchIds))
            ->whereIn('status', ['posted', 'partially_paid', 'paid', 'credited'])
            ->when($from, fn ($q) => $q->where('invoice_date', '>=', $from))->when($to, fn ($q) => $q->where('invoice_date', '<=', $to))
            ->orderByDesc('invoice_date')->get(['id', 'official_number', 'supplier_name', 'invoice_date', 'grni_amount', 'ppv_amount', 'total_amount'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function openItems(int $actor, array $branchIds): array
    {
        abort_unless($actor > 0, 403);

        return \DB::table('rp_open_items')->whereIn('branch_id', array_map('intval', $branchIds))->where('remaining_amount', '>', 0)
            ->orderBy('due_date')->get()->map(fn ($row) => (array) $row)->all();
    }
}
