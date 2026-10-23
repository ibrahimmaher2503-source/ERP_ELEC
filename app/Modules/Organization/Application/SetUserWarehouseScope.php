<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetUserWarehouseScope
{
    public function execute(User $actor, int $userId, int $warehouseId, bool $grant): void
    {
        DB::transaction(function () use ($actor, $userId, $warehouseId, $grant) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $guard->authorize($actor);
            abort_if($actor->id === $userId, 403, 'لا يمكن توسيع نطاق حسابك بنفسك.');
            $target = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $warehouse = app(InventoryApi::class)->warehouse($warehouseId);
            abort_unless($warehouse, 404);
            $branch = DB::table('branches')->where('id', $warehouse['branch_id'])->first();
            if ($grant && (! $target->active || ! $warehouse['active'] || ! $branch->active || ! DB::table('user_branch_scopes')->where('user_id', $userId)->where('branch_id', $branch->id)->exists())) {
                throw ValidationException::withMessages(['selectedWarehouse' => 'يلزم حساب ومستودع وفرع نشطون ونطاق الفرع أولًا.']);
            }
            $before = DB::table('user_warehouse_scopes')->where('user_id', $userId)->pluck('warehouse_id')->all();
            if ($grant) {
                DB::table('user_warehouse_scopes')->insertOrIgnore(['user_id' => $userId, 'warehouse_id' => $warehouseId, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('user_warehouse_scopes')->where('user_id', $userId)->where('warehouse_id', $warehouseId)->delete();
            }
            $guard->audit($actor, $userId, $grant ? 'scope.warehouse_granted' : 'scope.warehouse_revoked', ['warehouses' => $before], ['warehouses' => DB::table('user_warehouse_scopes')->where('user_id', $userId)->pluck('warehouse_id')->all()]);
        });
    }
}
