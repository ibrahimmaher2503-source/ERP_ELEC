<?php

namespace App\Modules\Attendance\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;

final class AttendanceAccess
{
    public function authorize(int $actor, int $branch, string $permission = 'view'): void
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M15.'.$permission, $branch));
            abort_unless(app(OrganizationApi::class)->projectBranchMember($actor, $branch), 403);
        } catch (ContractException $e) {
            abort($e->getCode(), 'صلاحية الحضور أو نطاق الفرع غير متاح.');
        }
    }

    public function branches(int $actor, string $permission = 'view'): array
    {
        try {
            return app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M15.'.$permission));
        } catch (ContractException) {
            return [];
        }
    }
}
