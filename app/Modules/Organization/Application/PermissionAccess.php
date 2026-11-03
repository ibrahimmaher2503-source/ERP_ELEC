<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use Closure;
use Illuminate\Support\Facades\DB;

class PermissionAccess
{
    private const SNAPSHOT = 'organization.authorization.snapshot';

    public static function forgetRequestSnapshot(): void
    {
        if (app()->bound('request')) {
            app('request')->attributes->remove(self::SNAPSHOT);
        }
    }

    private function remember(string $key, Closure $load): mixed
    {
        // CLI workers have no HTTP request boundary; keep their authorization reads fresh.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return $load();
        }
        $request = app('request');
        $connection = DB::connection();
        $key = $connection->getName().':'.$connection->getDatabaseName().':'.spl_object_id($connection->getPdo()).':'.$key;
        $values = $request->attributes->get(self::SNAPSHOT, []);
        if (! array_key_exists($key, $values)) {
            $value = $load();
            $values = $request->attributes->get(self::SNAPSHOT, []);
            $values[$key] = $value;
            $request->attributes->set(self::SNAPSHOT, $values);
        }

        return $values[$key];
    }

    public function actor(int $id): ?User
    {
        $user = $this->remember('actor:'.$id, fn () => $id > 0 ? User::find($id) : null);

        return $user ? clone $user : null;
    }

    private function authority(int $id): array
    {
        return $this->remember('authority:'.$id, function () use ($id) {
            $roles = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $id)->get(['r.id', 'r.code']);
            $permissions = $roles->isEmpty() ? [] : DB::table('role_permissions')->whereIn('role_id', $roles->pluck('id'))->pluck('permission_code')->all();
            $grants = DB::table('access_grants')->where('user_id', $id)->get(['permission_code', 'branch_id']);
            $direct = [];
            foreach ($grants as $grant) {
                $direct[$grant->permission_code][$grant->branch_id ?? 'company'] = true;
            }

            return ['administrator' => $roles->contains('code', 'Administrator'),
                'permissions' => array_fill_keys($permissions, true),
                'direct' => $direct];
        });
    }

    public function scopedBranches(int $id): array
    {
        return $this->remember('branches:'.$id, fn () => DB::table('user_branch_scopes as s')
            ->join('branches as b', 'b.id', '=', 's.branch_id')->where('s.user_id', $id)
            ->orderBy('b.name')->get(['b.id', 'b.company_id', 'b.code', 'b.name', 'b.address', 'b.active'])->keyBy('id')->all());
    }

    public function permissionBranches(int $id, string $code): array
    {
        $user = $this->actor($id);
        if (! $user) {
            return [];
        }
        $ids = $this->branchIds($user, $code);

        return $this->remember('branch-rows:'.$id.':'.implode(',', $ids), fn () => DB::table('branches')
            ->where('company_id', 1)->whereIn('id', $ids)->orderBy('name')
            ->get(['id', 'code', 'name', 'address', 'active'])->map(fn ($row) => (array) $row)->all());
    }

    public function inventoryScopes(int $id): array
    {
        return $this->remember('inventory-scopes:'.$id, function () use ($id) {
            $authority = $this->authority($id);
            $catalog = array_keys(array_filter(PermissionCatalog::DEFINITIONS, fn ($definition) => $definition[1] === 'warehouse'));
            $codes = $authority['administrator'] ? $catalog : array_values(array_intersect($catalog, array_keys($authority['permissions'])));
            $scopes = DB::table('user_warehouse_scopes as w')->join('user_branch_scopes as b', fn ($join) => $join->on('b.user_id', '=', 'w.user_id')->on('b.branch_id', '=', 'w.branch_id'))
                ->join('branches as br', 'br.id', '=', 'w.branch_id')->where('w.user_id', $id)->get(['w.warehouse_id', 'w.branch_id', 'br.active', 'br.name as branch_name']);

            return $scopes->map(function ($scope) use ($authority, $codes, $catalog) {
                $permissions = $codes;
                foreach ($catalog as $code) {
                    if (isset($authority['direct'][$code][$scope->branch_id])) {
                        $permissions[] = $code;
                    }
                }
                $permissions = array_values(array_unique($permissions));
                if (! $scope->active) {
                    $permissions = array_values(array_filter($permissions, fn ($code) => str_ends_with($code, '_view') || $code === 'M06.export'));
                }

                return ['warehouse_id' => $scope->warehouse_id, 'branch_id' => $scope->branch_id, 'branch_name' => $scope->branch_name, 'permissions' => $permissions];
            })->all();
        });
    }

    public function allows(User $actor, string $code, ?int $branchId = null, ?int $warehouseId = null): bool
    {
        if (! $this->actor($actor->id)?->active) {
            return false;
        }
        $definition = PermissionCatalog::DEFINITIONS[$code] ?? null;
        if (($definition[1] ?? null) === 'warehouse') {
            if ($warehouseId === null || $branchId === null || (! $this->roleCapability($actor->id, $code) && ! isset($this->authority($actor->id)['direct'][$code][$branchId]))) {
                return false;
            }
            $warehouse = $this->remember('warehouse:'.$warehouseId, fn () => app(InventoryApi::class)->warehouse($warehouseId));
            $scopes = $this->remember('warehouse-scopes:'.$actor->id, fn () => DB::table('user_warehouse_scopes')->where('user_id', $actor->id)->pluck('branch_id', 'warehouse_id')->all());

            return $warehouse && $warehouse['branch_id'] === $branchId
                && isset($this->scopedBranches($actor->id)[$branchId])
                && isset($scopes[$warehouseId]) && (int) $scopes[$warehouseId] === $branchId;
        }
        if ($warehouseId !== null) {
            return false;
        }
        if (! $definition || ($definition[1] === 'branch' && $branchId === null) || ($definition[1] === 'company' && $branchId !== null)) {
            return false;
        }
        if (isset($this->authority($actor->id)['direct'][$code][$branchId ?? 'company'])) {
            return true;
        }
        if (! $this->roleCapability($actor->id, $code)) {
            return false;
        }

        return $definition[1] === 'company' || isset($this->scopedBranches($actor->id)[$branchId]);
    }

    public function roleCapability(int $userId, string $code): bool
    {
        if (! isset(PermissionCatalog::DEFINITIONS[$code])) {
            return false;
        }
        $authority = $this->authority($userId);

        return $authority['administrator'] || isset($authority['permissions'][$code]);
    }

    public function securityDesignated(int $userId): bool
    {
        return isset($this->authority($userId)['direct']['M01.security_admin']['company'])
            || $this->roleCapability($userId, 'M01.security_admin');
    }

    public function branchIds(User $actor, string $code): array
    {
        if ((PermissionCatalog::DEFINITIONS[$code][1] ?? null) !== 'branch' || ! $this->actor($actor->id)?->active) {
            return [];
        }
        $ids = array_filter(array_keys($this->authority($actor->id)['direct'][$code] ?? []), fn ($id) => $id !== 'company');
        if ($this->roleCapability($actor->id, $code)) {
            $ids = array_merge($ids, array_keys($this->scopedBranches($actor->id)));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
