<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;

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

    /** True once the company activated financial posting (go-live date, taxes and approved openings). */
    public function activated(): bool
    {
        return (bool) DB::table('accounting_activation')->where('id', 1)->value('activated_at');
    }

    public function navigation(int $actor): array
    {
        return array_filter(['setup' => 'تجهيز النظام المالي', 'dashboard' => 'ملخص المحاسبة', 'accounts' => 'دليل الحسابات', 'periods' => 'الفترات المالية', 'journals' => 'القيود اليومية',
            'ledger' => 'الأستاذ العام', 'trial-balance' => 'ميزان المراجعة', 'income-statement' => 'قائمة الدخل', 'balance-sheet' => 'الميزانية',
            'grni' => 'استلامات لم تُفوتر بعد', 'ppv' => 'فروق أسعار الشراء', 'reconciliation' => 'مطابقة الحسابات', 'closing' => 'إقفال الفترة المالية'], fn ($label, $screen) => match ($screen) {
                'setup' => $this->allows($actor, 'setup_view'), 'accounts' => $this->allows($actor, 'account_view'), 'periods' => $this->allows($actor, 'period_view'), default => $this->branches($actor) !== []
            }, ARRAY_FILTER_USE_BOTH);
    }
}
