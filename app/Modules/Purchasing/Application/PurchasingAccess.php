<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PurchasingAccess
{
    public const NAVIGATION = [
        'dashboard' => ['لوحة المشتريات', 'dashboard_view'],
        'requisitions.index' => ['طلبات الاحتياج', 'requisition_view'],
        'rfqs.index' => ['طلبات عروض الموردين', 'rfq_view'],
        'offers.index' => ['عروض الموردين', 'offer_view'],
        'comparison' => ['مقارنة العروض', 'comparison_view'],
        'orders.index' => ['أوامر الشراء', 'order_view'],
        'receipts.index' => ['استلام المشتريات', 'receipt_view'],
        'returns' => ['مرتجعات المشتريات', 'return_view'],
        'reports' => ['تقارير المشتريات', 'report_view'],
    ];

    public function navigation(int $actor): array
    {
        return array_filter(self::NAVIGATION, fn ($entry) => $this->branches($actor, $entry[1]) !== []);
    }

    public function branches(int $actor, string $action = 'requisition_view'): array
    {
        try {
            $rows = app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M05.'.$action));

            return str_ends_with($action, '_view') ? $rows : array_values(array_filter($rows, fn ($b) => $b['active']));
        } catch (ContractException) {
            return [];
        }
    }

    public function authorize(int $actor, string $action, int $branch): void
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M05.'.$action, $branch));
            if (! str_ends_with($action, '_view')) {
                abort_unless(in_array($branch, array_column($this->branches($actor, $action), 'id'), true), 403, 'الفرع غير نشط.');
            }
        } catch (ContractException $e) {
            abort($e->getCode(), 'صلاحية المشتريات أو نطاق الفرع غير متاح.');
        }
    }

    public function allows(int $actor, string $action, int $branch): bool
    {
        try {
            $this->authorize($actor, $action, $branch);

            return true;
        } catch (HttpException) {
            return false;
        }
    }
}
