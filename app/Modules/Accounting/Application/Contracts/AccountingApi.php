<?php

namespace App\Modules\Accounting\Application\Contracts;

interface AccountingApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array;

    /** Acquire the common posting/close gate before locking any source rows. Same transaction only. */
    public function lockPosting(): void;

    public function claimServiceExpense(int $actor, int $journal, int $branch, ?int $project, string $owner, int $source): array;

    /** Frozen attribution of a posted Inventory source, for its exact-source return. */
    public function inventorySourceProject(int $actor, int $movement, int $branch): ?int;

    public function maintenanceExpense(int $actor, int $journal, int $branch, ?int $project): array;

    /** Selection is server-side by transaction, product and effective date. No rate override. */
    public function calculateTax(int $actor, string $transaction, string $date, string $mode, array $lines, string $documentDiscount = '0'): array;

    /** Tender estimates may contain unmapped external BOQ lines. Only dated company-wide tax rules apply to those lines. No posting or rate override. */
    public function calculateTenderTax(int $actor, string $date, string $mode, array $lines, string $documentDiscount = '0'): array;

    public function requirePostingDate(string $date): void;

    /** Validated source owners only; the caller must share the business transaction. */
    public function postReceipt(int $actor, int $receipt): array;

    public function postInventoryMovement(int $actor, int $movement): ?int;

    /** Post a balanced journal from an approved owner document in the caller's transaction. */
    public function postSourceDocument(int $actor, int $creator, int $branch, string $date, string $sourceKey, string $description, array $lines, ?int $project = null): int;

    public function projectSummary(int $actor, int $project, int $branch): array;

    /** Create an immutable, source-linked reversing journal; the original remains unchanged. */
    public function reversePostedJournal(int $actor, int $journal, string $date, string $reason): int;

    public function reverseTreasurySource(int $actor, int $movement, string $date, string $key, string $reason): int;
}
