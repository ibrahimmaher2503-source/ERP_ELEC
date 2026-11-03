<?php

namespace App\Modules\Treasury\Application\Contracts;

interface TreasuryApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array;

    public function refundCustomerAdvance(int $actor, int $advanceId, int $accountId, string $amount, string $date, string $idempotencyKey, string $reference): array;

    public function advanceMovementEvidence(int $actor, int $movement, int $branch, int $customer): array;

    public function advanceRefundAccounts(int $actor, int $branch): array;

    public function refundPartyCredit(int $actor, int $creditId, int $accountId, string $amount, string $date, string $idempotencyKey, string $reference): array;

    public function partyCreditMovementEvidence(int $actor, int $movement, int $branch, string $partyType, int $party): array;

    public function creditRefundAccounts(int $actor, int $branch): array;

    public function correctMovement(int $actor, int $movementId, string $date, string $key, string $reason): array;

    public function cashCorrectionSource(int $actor, int $movementId, string $date): array;

    public function assertCashCorrection(int $actor, int $original, int $movement, string $date): void;

    /** Dated settlement IDs only, authorized by M12 party-report branch permission. */
    public function settlementMovementIds(int $actor, string $partyType, int $party, int $branch, string $to): array;

    /** Read-only party cash movements; caller branch IDs are intersected with M13 authority. */
    public function partyMovements(int $actor, string $partyType, int $partyId, array $branchIds, int $page = 1): array;

    public function saveOperationalAccount(int $actor, array $data): int;

    public function prepareSupplierPayment(int $actor, array $data): int;

    public function approveSupplierPayment(int $actor, int $payment, int $version): void;

    public function executeSupplierPayment(int $actor, int $payment, int $version, string $idempotencyKey, array $allocations): array;

    public function executeCustomerCollection(int $actor, array $data, array $allocations): array;

    public function collectionAccounts(int $actor, int $branch): array;

    public function transfer(int $actor, array $data): array;

    public function reconciliationValues(int $actor, array $branchIds, string $to): array;

    /** Immutable source IDs/dates for M12's dated settlement/advance projection. M11 report scope required. */
    public function reconciliationMovements(int $actor, array $branchIds, string $to): array;
}
