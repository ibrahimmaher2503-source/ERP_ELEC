<?php

namespace App\Modules\Projects\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TenderAccess
{
    public function authorize(int $actor, int $branch, string $permission = 'view'): void
    {
        abort_unless(config('release.tenders_enabled'), 404);
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M07.tender_'.$permission, $branch));
            abort_unless(app(OrganizationApi::class)->projectBranchMember($actor, $branch), 403);
        } catch (ContractException $e) {
            abort($e->getCode(), 'صلاحية المناقصة أو نطاق الفرع غير متاح.');
        }
    }

    public function allows(int $actor, int $branch, string $permission = 'view'): bool
    {
        try {
            $this->authorize($actor, $branch, $permission);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    public function branches(int $actor, string $permission = 'view'): array
    {
        if (! config('release.tenders_enabled')) {
            return [];
        }
        try {
            $branches = app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M07.tender_'.$permission));
            $scopes = array_map('intval', app(OrganizationApi::class)->projectScopedBranches($actor));

            return array_values(array_filter($branches, static fn (array $branch): bool => in_array((int) $branch['id'], $scopes, true)));
        } catch (ContractException) {
            return [];
        }
    }

    public function query(int $actor, string $permission = 'view')
    {
        abort_unless(config('release.tenders_enabled'), 404);

        return DB::table('project_tenders')->whereIn('branch_id', array_column($this->branches($actor), 'id'))->whereIn('branch_id', array_column($this->branches($actor, $permission), 'id'));
    }
}
