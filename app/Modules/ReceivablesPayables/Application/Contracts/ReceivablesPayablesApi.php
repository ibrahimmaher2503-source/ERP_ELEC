<?php

namespace App\Modules\ReceivablesPayables\Application\Contracts;

interface ReceivablesPayablesApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array;

    public function applyCustomerAdvance(int $actor, int $advanceId, string $creationKey, string $date, array $allocations): array;

    public function prepareAdvanceRefund(int $actor, int $advance, string $amount, string $date): array;

    public function completeAdvanceRefund(int $actor, int $advance, int $movement, string $amount, string $date, string $key): void;

    public function preparePartyCreditRefund(int $actor, int $credit, string $amount, string $date): array;

    public function completePartyCreditRefund(int $actor, int $credit, int $movement, string $amount, string $date, string $key): void;

    public function prepareCashReversal(int $actor, int $originalMovement, string $date): void;

    public function completeCashReversal(int $actor, int $originalMovement, int $movement, string $date): void;

    public function createContractInvoice(int $actor, array $data): int;

    public function createContractCreditNote(int $actor, int $correction): int;

    public function contractFinancialSummary(int $actor, int $contract, int $branch, ?string $to = null): array;

    public function contractDeferredValue(int $actor, array $branches, string $to): string;

    /** Read-only M03 projection, limited to current AR/AP branch authority. */
    public function partyFinancialProfile(int $actor, string $partyType, int $partyId, array $filters = []): array;

    /** Complete filtered statement, bounded by the existing System workbook limit. */
    public function partyStatementExport(int $actor, string $partyType, int $partyId, array $filters = []): array;

    public function createWorkshopInvoice(int $actor, array $input): int;

    public function workshopInvoiceStatus(int $actor, int $job, int $branch): ?array;

    public function workshopBillingHandoff(int $actor, int $job, int $branch): ?array;

    /** M08 closure projection, no AR amounts or authority granted. */
    public function serviceBillingHandoff(int $actor, int $work, int $branch): ?array;

    public function createServiceInvoice(int $actor, array $data): int;

    public function serviceInvoiceStatus(int $actor, int $work, int $branch): ?array;

    public function projectSummary(int $actor, int $project, int $branch, array $purchaseOrders, array $salesOrders): array;

    public function createSupplierInvoice(int $actor, array $data): int;

    public function validateSupplierInvoice(int $actor, int $invoice, int $version): void;

    public function matchSupplierInvoice(int $actor, int $invoice, int $version): void;

    public function approveSupplierInvoice(int $actor, int $invoice, int $version): void;

    public function postSupplierInvoice(int $actor, int $invoice, int $version): array;

    public function createCustomerInvoice(int $actor, array $data): int;

    public function validateCustomerInvoice(int $actor, int $invoice, int $version): void;

    public function postCustomerInvoice(int $actor, int $invoice, int $version): array;

    /** Lock and validate current open items before the Treasury movement posts. */
    public function prepareSettlement(int $actor, string $partyType, int $partyId, array $allocations): array;

    /** Persist allocations and settlement status in the same transaction as the Treasury movement. */
    public function completeSettlement(int $actor, int $movementId, string $partyType, array $allocations): void;

    public function recordCustomerAdvance(int $actor, int $movementId, int $branch, int $customer, string $amount): void;

    public function createCreditNote(int $actor, array $data): int;

    public function approveCreditNote(int $actor, int $creditNote): void;

    public function postCreditNote(int $actor, int $creditNote): array;

    /** Subledger control totals and GRNI clearing for an M11 reconciliation scope. */
    public function reconciliationValues(int $actor, array $branchIds, ?string $to = null): array;

    public function ppvRows(int $actor, array $branchIds, ?string $from, ?string $to): array;

    public function openItems(int $actor, array $branchIds): array;
}
