<?php
namespace App\Modules\Accounting\Application;

use App\Modules\Organization\Application\Contracts\{OrganizationApi, AuthorizeRequest, ContractException};

final class AccountingAccess
{
    public function allows(int $actor, string $permission, ?int $branch = null): bool
    {
        try { app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.'.$permission, $branch)); return true; }
        catch (ContractException) { return false; }
    }
    public function authorize(int $actor, string $permission, ?int $branch = null): void
    { abort_unless($this->allows($actor, $permission, $branch), 403, 'صلاحية المحاسبة أو نطاق الفرع غير متاح.'); }
    public function branches(int $actor, string $permission = 'journal_view'): array
    {
        try { return app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M11.'.$permission)); }
        catch (ContractException) { return []; }
    }
    public function navigation(int $actor): array
    {
        return array_filter(['dashboard'=>'لوحة المحاسبة','accounts'=>'دليل الحسابات','periods'=>'الفترات المالية','journals'=>'القيود اليومية'], fn($label,$screen) => match($screen) {
            'accounts' => $this->allows($actor,'account_view'), 'periods' => $this->allows($actor,'period_view'), default => $this->branches($actor)!==[]
        }, ARRAY_FILTER_USE_BOTH);
    }
}
