<?php

namespace App\Modules\Organization\Application\Contracts;

interface QuotationDelegation
{
    public function authorizeApproval(int $actorId, int $requesterId): void;
}
