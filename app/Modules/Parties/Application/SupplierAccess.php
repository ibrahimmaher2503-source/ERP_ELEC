<?php

namespace App\Modules\Parties\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupplierAccess
{
    public function authorize(int $actorId, string $permission): void
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actorId, $permission));
        } catch (ContractException $exception) {
            abort($exception->getCode(), 'غير مسموح بتنفيذ هذه العملية.');
        }
    }

    public function allows(int $actorId, string $permission): bool
    {
        try {
            $this->authorize($actorId, $permission);

            return true;
        } catch (HttpException) {
            return false;
        }
    }
}
