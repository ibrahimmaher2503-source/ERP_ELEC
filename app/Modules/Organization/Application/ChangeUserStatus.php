<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeUserStatus
{
    public function execute(User $actor, int $id, bool $active, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $id, $active, $expectedVersion) {
            app(RbacGuard::class)->lock();
            $access = app(UserAccess::class);
            $access->lockAccounts($actor, $id);
            $target = $access->query($actor)->where('u.id', $id)->first();
            abort_unless($target, 404);
            $access->authorize($actor, 'M01.user_status', $target);
            if ($target->version !== $expectedVersion) {
                throw ValidationException::withMessages(['status' => 'تغير الحساب. حدّث الصفحة وأعد المحاولة.']);
            }
            if ($active && $target->branch_id && ! DB::table('branches')->where('id', $target->branch_id)->where('active', true)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['status' => 'فعّل الفرع الرئيسي أولًا أو عدّل ارتباط الحساب.']);
            }
            DB::table('users')->where('id', $id)->update(['active' => $active, 'version' => $target->version + 1, 'updated_at' => now()]);
            if (app(PermissionAccess::class)->securityDesignated($id)) {
                app(RbacGuard::class)->assertAdministratorRemains();
            }
            app(RevokeUserSessions::class)->execute($id);
            if (! $active) {
                DB::table('password_reset_tokens')->where('email', $target->email)->delete();
            }
            event(new MasterChanged($actor->id, 'user', $id, $active ? 'activated' : 'deactivated', ['active' => $target->active], ['active' => $active]));
        });
    }
}
