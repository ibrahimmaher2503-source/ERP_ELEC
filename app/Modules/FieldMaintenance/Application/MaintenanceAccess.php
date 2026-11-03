<?php

namespace App\Modules\FieldMaintenance\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MaintenanceAccess
{
    public function authorize(int $actor, int $branch, string $permission = 'view'): void
    {
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M08.'.$permission, $branch));
            abort_unless(app(OrganizationApi::class)->projectBranchMember($actor, $branch), 403);
        } catch (ContractException $e) {
            abort($e->getCode(), 'صلاحية الصيانة أو نطاق الفرع غير متاح.');
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
            return app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M08.'.$permission));
        } catch (ContractException) {
            return [];
        }
    }

    public function query(int $actor, string $table = 'maintenance_work_orders')
    {
        abort_unless(in_array($table, ['maintenance_work_orders', 'maintenance_requests']), 422);

        $branches = array_column($this->branches($actor), 'id');
        $limited = array_values(array_filter($branches, fn ($b) => $this->allows($actor, $b, 'visit_record') && ! $this->allows($actor, $b, 'workorder_manage') && ! $this->allows($actor, $b, 'assign') && ! $this->allows($actor, $b, 'verify')));
        $full = array_values(array_diff($branches, $limited));
        if ($limited === []) {
            return DB::table($table)->whereIn('branch_id', $full);
        }
        $assigned = DB::table('maintenance_assignments')->where('technician_id', $actor)->select('work_order_id');

        return DB::table($table)->where(function ($q) use ($table, $full, $limited, $assigned) {
            $q->whereIn('branch_id', $full)->orWhere(function ($q) use ($table, $limited, $assigned) {
                $q->whereIn('branch_id', $limited)->whereIn('id', $table === 'maintenance_work_orders' ? $assigned : DB::table('maintenance_work_orders')->whereIn('id', $assigned)->whereNotNull('request_id')->select('request_id'));
            });
        });
    }

    public function technicians(int $actor, int $branch): array
    {
        $this->authorize($actor, $branch);

        return array_values(array_filter(app(OrganizationApi::class)->projectAssignees($actor, $branch), fn ($u) => $this->allows($u['id'], $branch, 'visit_record')));
    }
}
