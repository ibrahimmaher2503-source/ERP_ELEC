<?php

namespace App\Modules\ServiceContracts\Application\Contracts;

interface ServiceContractsApi
{
    public function createTenderDraft(int $actor, int $tender, int $result, array $mapping): int;

    public function billingSource(int $actor, int $source): array;

    public function validateBillingEvidence(int $actor, int $source, int $branch, int $customer): void;

    public function recognizedValue(int $actor, array $branches, string $to): string;

    public function creditSource(int $actor, int $correction): array;

    public function validateBillingCredit(int $actor, int $correction, int $source, int $branch, string $net, string $amount, string $date): void;

    /** Coverage is eligibility only; consuming work must be registered by its owning coordinator. */
    public function coverage(int $actor, int $contract, int $customer, int $branch, string $equipmentRef, ?int $site, string $date, string $kind): array;

    /** Scoped active identities; reserveWork revalidates actual entitlement. */
    public function workshopOptions(int $actor, int $customer, int $branch): array;

    public function reserveWork(int $actor, int $contract, string $owner, int $source): int;

    public function consumeWork(int $actor, string $owner, int $source): int;

    public function recordWorkUsage(int $actor, string $owner, int $source): void;

    public function releaseWork(int $actor, string $owner, int $source): void;
}
