<?php

namespace App\Modules\ServiceContracts\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ServiceContractAccess
{
    public function allows(int $actor, int $branch, string $permission): bool
    {
        try {
            $this->authorize($actor, $branch, $permission);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    public function authorize(int $actor, int $branch, string $permission = 'contract_view'): void
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M10.'.$permission, $branch));
            abort_unless(app(OrganizationApi::class)->projectBranchMember($actor, $branch), 403);
        } catch (ContractException $e) {
            abort($e->getCode(), 'صلاحية العقد أو نطاق الفرع غير متاح.');
        }
    }

    public function branches(int $actor, string $permission = 'contract_view'): array
    {
        try {
            return app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M10.'.$permission));
        } catch (ContractException) {
            return [];
        }
    }
}
