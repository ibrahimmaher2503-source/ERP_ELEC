<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TreasuryAccess
{
    public function allows(int $actor, string $permission, ?int $branch = null): bool
    {
        try {
            $this->authorize($actor, $permission, $branch);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    public function authorize(int $actor, string $permission, ?int $branch = null): void
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M13.'.$permission, $branch));
        } catch (ContractException $e) {
            abort($e->getCode(), 'صلاحية الخزينة أو نطاق الفرع غير متاح.');
        }
    }

    public function navigation(int $actor): array
    {
        return array_filter(['accounts' => ['البنك والخزينة', 'account_view'], 'payments' => ['مدفوعات الموردين', 'payment_prepare'],
            'collections' => ['متحصلات العملاء', 'collection_execute'], 'transfers' => ['التحويل بين البنك والخزينة', 'transfer_execute'], 'statements' => ['كشوف البنك والمطابقة', 'statement_view']],
            fn ($item) => $item[1] === 'account_view' ? $this->allows($actor, $item[1]) : $this->branches($actor, $item[1]) !== []);
    }

    public function branches(int $actor, string $permission): array
    {
        try {
            return app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M13.'.$permission));
        } catch (ContractException) {
            return [];
        }
    }
}
