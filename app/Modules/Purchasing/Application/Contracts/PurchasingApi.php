<?php

namespace App\Modules\Purchasing\Application\Contracts;

interface PurchasingApi
{
    public function projectSummary(int $actor, int $branch, array $links): array;

    /** Locked, current source eligibility, quantities and capitalizable EGP amounts. */
    public function prepareReceiptPosting(int $actor, int $receipt): array;

    public function completeReceiptPosting(int $actor, int $receipt, int $version, array $stock, array $journal): void;

    /** Current authorized reference projection, never evidence of settlement or recognized cost. */
    public function projectDocument(int $actor, string $type, int $id): array;

    /** Locked, posted receipt evidence for M12 matching. Must be called inside the shared transaction. */
    public function supplierInvoiceSource(int $actor, array $receiptLineIds): array;

    /** Posted GRNI receipt value for an M11 reconciliation scope. */
    public function grniValue(int $actor, array $branchIds, ?string $to = null): string;
}
