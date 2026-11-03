<?php

namespace App\Modules\WorkshopRepairs\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class WorkshopAccess
{
    public function authorize(int $actor, int $branch, string $permission = 'view'): void
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M09.'.$permission, $branch));
            abort_unless(app(OrganizationApi::class)->projectBranchMember($actor, $branch), 403);
        } catch (ContractException $e) {
            abort($e->getCode(), 'صلاحية الورشة أو نطاق الفرع غير متاح.');
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
        try {
            $branches = app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M09.'.$permission));
            $scopes = array_map('intval', app(OrganizationApi::class)->projectScopedBranches($actor));

            return array_values(array_filter($branches, static fn (array $branch): bool => in_array((int) $branch['id'], $scopes, true)));
        } catch (ContractException) {
            return [];
        }
    }

    public function billingHandoff(int $actor, int $branch): bool
    {
        foreach (['job_manage', 'verify', 'deliver', 'billing_request'] as $permission) {
            if ($this->allows($actor, $branch, $permission)) {
                return true;
            }
        }
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M12.ar_view', $branch));

            return $this->allows($actor, $branch);
        } catch (ContractException) {
            return false;
        }
    }

    public function query(int $actor, string $table = 'workshop_intakes')
    {
        abort_unless(in_array($table, ['workshop_intakes', 'workshop_jobs', 'workshops']), 422);

        return DB::table($table)->whereIn('branch_id', array_column($this->branches($actor), 'id'));
    }

    public function technician(int $actor, object $job): void
    {
        $this->authorize($actor, $job->branch_id, 'execute');
        abort_unless($job->technician_id === $actor && DB::table('workshop_members')->where('workshop_id', $job->workshop_id)->where('technician_id', $actor)->where('active', true)->exists(), 403, 'التنفيذ للفني المعين النشط فقط.');
    }
}
