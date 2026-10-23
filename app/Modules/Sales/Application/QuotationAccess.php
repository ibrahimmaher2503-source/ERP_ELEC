<?php

namespace App\Modules\Sales\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Symfony\Component\HttpKernel\Exception\HttpException;

class QuotationAccess
{
    public function authorize(int $actor, string $action = 'view'): void
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M04.quotation_'.$action));
        } catch (ContractException $e) {
            abort($e->getCode(), 'غير مسموح بتنفيذ هذه العملية.');
        }
    }

    public function allows(int $actor, string $action = 'view'): bool
    {
        try {
            $this->authorize($actor, $action);

            return true;
        } catch (HttpException) {
            return false;
        }
    }
}
