<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use Illuminate\Support\Facades\DB;

class PermissionAccess
{
    public function allows(User $actor, string $code, ?int $branchId = null, ?int $warehouseId = null): bool
    {
        if (! DB::table('users')->where('id', $actor->id)->where('active', true)->exists()) {
            return false;
        }
        $definition = PermissionCatalog::DEFINITIONS[$code] ?? null;
        if (($definition[1] ?? null) === 'warehouse') {
            if ($warehouseId === null || $branchId === null || (! $this->roleCapability($actor->id, $code) && ! DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', $code)->where('branch_id', $branchId)->exists())) {
                return false;
            }
            $warehouse = app(InventoryApi::class)->warehouse($warehouseId);

            return $warehouse && $warehouse['branch_id'] === $branchId
                && DB::table('user_branch_scopes')->where('user_id', $actor->id)->where('branch_id', $branchId)->exists()
                && DB::table('user_warehouse_scopes')->where('user_id', $actor->id)->where('warehouse_id', $warehouseId)->where('branch_id', $branchId)->exists();
        }
        if ($warehouseId !== null) {
            return false;
        }
        if (! $definition || ($definition[1] === 'branch' && $branchId === null) || ($definition[1] === 'company' && $branchId !== null)) {
            return false;
        }
        if (DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', $code)->where('branch_id', $branchId)->exists()) {
            return true;
        }
        if (! $this->roleCapability($actor->id, $code)) {
            return false;
        }

        return $definition[1] === 'company' || DB::table('user_branch_scopes')->where('user_id', $actor->id)->where('branch_id', $branchId)->exists();
    }

    public function roleCapability(int $userId, string $code): bool
    {
        if (! isset(PermissionCatalog::DEFINITIONS[$code])) {
            return false;
        }
        if (DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $userId)->where('r.code', 'Administrator')->exists()) {
            return true;
        }

        return DB::table('user_roles as ur')->join('role_permissions as rp', 'ur.role_id', '=', 'rp.role_id')
            ->where('ur.user_id', $userId)->where('rp.permission_code', $code)->exists();
    }

    public function securityDesignated(int $userId): bool
    {
        return DB::table('access_grants')->where('user_id', $userId)->where('permission_code', 'M01.security_admin')->whereNull('branch_id')->exists()
            || $this->roleCapability($userId, 'M01.security_admin');
    }

    public function branchIds(User $actor, string $code): array
    {
        if ((PermissionCatalog::DEFINITIONS[$code][1] ?? null) !== 'branch' || ! DB::table('users')->where('id', $actor->id)->where('active', true)->exists()) {
            return [];
        }
        $ids = DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', $code)->whereNotNull('branch_id')->pluck('branch_id')->all();
        if ($this->roleCapability($actor->id, $code)) {
            $ids = array_merge($ids, DB::table('user_branch_scopes')->where('user_id', $actor->id)->pluck('branch_id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
