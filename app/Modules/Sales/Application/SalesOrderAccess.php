<?php

namespace App\Modules\Sales\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SalesOrderAccess
{
    public function branchIds(int $actor, string $action = 'view'): array
    {
        try {
            return array_column(app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M04.sales_order_'.$action)), 'id');
        } catch (ContractException $e) {
            abort($e->getCode(), 'غير مسموح بعرض نطاق أوامر البيع.');
        }
    }

    public function authorize(int $actor, string $action = 'view', ?int $branch = null): void
    {
        if ($branch === null) {
            abort_unless($this->branchIds($actor, $action) !== [], 403, 'امنح صلاحية أوامر البيع ونطاق الفرع أولًا.');

            return;
        }
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M04.sales_order_'.$action, $branch));
        } catch (ContractException $e) {
            abort($e->getCode(), 'غير مسموح بهذا الإجراء على فرع أمر البيع.');
        }
    }

    public function allows(int $actor, string $action = 'view', ?int $branch = null): bool
    {
        try {
            $this->authorize($actor, $action, $branch);

            return true;
        } catch (HttpException) {
            return false;
        }
    }
}
