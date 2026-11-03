<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;

final class AccountingAccess
{
    public function allows(int $actor, string $permission, ?int $branch = null): bool
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.'.$permission, $branch));

            return true;
        } catch (ContractException) {
            return false;
        }
    }

    public function authorize(int $actor, string $permission, ?int $branch = null): void
    {
        abort_unless($this->allows($actor, $permission, $branch), 403, 'صلاحية المحاسبة أو نطاق الفرع غير متاح.');
    }

    public function branches(int $actor, string $permission = 'journal_view'): array
    {
        try {
            return app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M11.'.$permission));
        } catch (ContractException) {
            return [];
        }
    }

    public function closingBranches(int $actor): array
    {
        try {
            return app(OrganizationApi::class)->financialClosingBranches($actor);
        } catch (ContractException $error) {
            abort($error->getCode() === 403 ? 403 : 503, 'إقفال الشركة يتطلب صلاحية تقارير جميع الفروع وبياناتها المكتملة.');
        }
    }

    public function navigation(int $actor): array
    {
        return array_filter(['setup' => 'إعداد التفعيل المالي', 'dashboard' => 'لوحة المحاسبة', 'accounts' => 'دليل الحسابات', 'periods' => 'الفترات المالية', 'journals' => 'القيود اليومية',
            'ledger' => 'الأستاذ العام', 'trial-balance' => 'ميزان المراجعة', 'income-statement' => 'قائمة الدخل', 'balance-sheet' => 'الميزانية',
            'grni' => 'مصادقة GRNI', 'ppv' => 'فروق أسعار الشراء', 'reconciliation' => 'المصالحات المالية', 'closing' => 'إقفال الفترات'], fn ($label, $screen) => match ($screen) {
                'setup' => $this->allows($actor, 'setup_view'), 'accounts' => $this->allows($actor, 'account_view'), 'periods' => $this->allows($actor, 'period_view'), default => $this->branches($actor) !== []
            }, ARRAY_FILTER_USE_BOTH);
    }
}
