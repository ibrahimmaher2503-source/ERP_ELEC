<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UpdateOwnPassword
{
    public function execute(User $actor, string $currentPassword, string $newPassword): void
    {
        Validator::make(['password' => $newPassword], ['password' => ['required', 'string', Password::min(12)->mixedCase()->numbers(), 'max:255']])->validate();
        DB::transaction(function () use ($actor, $currentPassword, $newPassword) {
            app(RbacGuard::class)->lock();
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active, 403);
            if (! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'كلمة المرور الحالية غير صحيحة.']);
            }
            $user->forceFill(['password' => $newPassword, 'version' => $user->version + 1])->save();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            app(RevokeUserSessions::class)->execute($user->id);
            event(new MasterChanged($user->id, 'user', $user->id, 'self_password_changed', null, ['sessions_revoked' => true]));
        });
    }
}
