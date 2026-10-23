<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Owner-approved Administrator defaults. Invoked by deployment migrations, never from ordinary web requests. */
final class SyncAdministratorPermissions
{
    public function execute(?int $actorId = null): array
    {
        return DB::transaction(function () use ($actorId) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            if ($actorId !== null) {
                $guard->authorize(User::findOrFail($actorId));
            }
            $role = DB::table('roles')->where('code', 'Administrator')->lockForUpdate()->first();
            if (! $role) {
                return [];
            }
            $before = DB::table('role_permissions')->where('role_id', $role->id)->orderBy('permission_code')->pluck('permission_code')->all();
            $registered = DB::table('permission_catalog')->whereIn('code', array_keys(PermissionCatalog::DEFINITIONS))->orderBy('code')->pluck('code')->all();
            $added = array_values(array_diff($registered, $before));
            if (! $added) {
                return [];
            }
            foreach ($added as $code) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_code' => $code, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('roles')->where('id', $role->id)->update(['version' => $role->version + 1, 'updated_at' => now()]);
            DB::table('access_audits')->insert([
                'actor_id' => $actorId, 'subject_user_id' => $actorId, 'action' => 'role.administrator_defaults_synced',
                'before_snapshot' => json_encode(['role_id' => $role->id, 'permissions' => $before], JSON_THROW_ON_ERROR),
                'after_snapshot' => json_encode(['role_id' => $role->id, 'permissions' => DB::table('role_permissions')->where('role_id', $role->id)->orderBy('permission_code')->pluck('permission_code')->all(), 'added' => $added, 'policy' => 'owner_all_registered_permissions_by_default'], JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'outcome' => 'succeeded',
            ]);

            return $added;
        });
    }
}
