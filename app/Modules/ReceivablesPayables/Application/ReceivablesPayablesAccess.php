<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;

final class ReceivablesPayablesAccess
{
    public function allows(int $actor, string $permission, ?int $branch = null): bool
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M12.'.$permission, $branch));

            return true;
        } catch (ContractException) {
            return false;
        }
    }

    public function authorize(int $actor, string $permission, int $branch): void
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M12.'.$permission, $branch));
        } catch (ContractException $e) {
            abort($e->getCode(), 'صلاحية الذمم أو نطاق الفرع غير متاح.');
        }
    }

    public function branches(int $actor, string $permission): array
    {
        try {
            return app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M12.'.$permission));
        } catch (ContractException) {
            return [];
        }
    }

    public function navigation(int $actor): array
    {
        $items = ['supplier-invoices' => ['فواتير الموردين', 'supplier_invoice_view'], 'ap' => ['حسابات الموردين', 'ap_view'],
            'customer-invoices' => ['فواتير العملاء', 'customer_invoice_view'], 'ar' => ['حسابات العملاء', 'ar_view'],
            'credit-notes' => ['الإشعارات الدائنة', 'credit_note_manage'], 'advances' => ['مقدمات العملاء', 'ar_view'], 'aging' => ['الأعمار والتخصيصات', 'ap_view']];

        return array_filter($items, fn ($item) => $this->branches($actor, $item[1]) !== []);
    }
}
