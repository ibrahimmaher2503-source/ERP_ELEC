<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\Contracts\QuotationDelegation;

final class QuotationDelegationService implements QuotationDelegation
{
    public function authorizeApproval(int $actorId, int $requesterId): void
    {
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actorId, 'M04.quotation_approve'));
        if ($actorId !== $requesterId) {
            return;
        }
        abort_unless(app(SelfApprovalPolicy::class)->allows($actorId, 'quotations'), 403, 'الموافقة الذاتية معطلة لهذا المستخدم وأدواره.');
    }
}
