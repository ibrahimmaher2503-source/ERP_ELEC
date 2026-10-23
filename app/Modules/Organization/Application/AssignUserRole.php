<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignUserRole
{
    public function execute(User $actor, int $roleId, int $userId, bool $assign, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $roleId, $userId, $assign, $expectedVersion) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $guard->authorize($actor);
            abort_if($actor->id === $userId, 403, 'لا يمكن تغيير أدوار حسابك بنفسك.');
            $role = DB::table('roles')->where('id', $roleId)->lockForUpdate()->first();
            abort_unless($role, 404);
            if ($role->version !== $expectedVersion) {
                throw ValidationException::withMessages(['assignment' => 'تغير الدور. حدّث الصفحة وأعد المحاولة.']);
            }
            $target = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id', 'active']);
            abort_unless($target, 404);
            if ($assign && ! $target->active) {
                throw ValidationException::withMessages(['assignment' => 'لا يمكن إسناد دور جديد لحساب غير نشط.']);
            }
            $before = DB::table('user_roles')->where('user_id', $userId)->orderBy('role_id')->pluck('role_id')->all();
            if ($assign) {
                DB::table('user_roles')->insertOrIgnore(['user_id' => $userId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('user_roles')->where('user_id', $userId)->where('role_id', $roleId)->delete();
            }
            DB::table('roles')->where('id', $roleId)->update(['version' => $role->version + 1, 'updated_at' => now()]);
            $guard->assertAdministratorRemains();
            $guard->audit($actor, $userId, $assign ? 'role.assigned' : 'role.revoked', ['role_id' => $roleId, 'roles' => $before], ['role_id' => $roleId, 'roles' => DB::table('user_roles')->where('user_id', $userId)->orderBy('role_id')->pluck('role_id')->all()]);
        });
    }
}
