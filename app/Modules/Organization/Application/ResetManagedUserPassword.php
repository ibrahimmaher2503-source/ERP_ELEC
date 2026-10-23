<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ResetManagedUserPassword
{
    public function execute(User $actor, int $id, array $input, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $id, $input, $expectedVersion) {
            app(RbacGuard::class)->lock();
            $access = app(UserAccess::class);
            $access->lockAccounts($actor, $id);
            $target = $access->query($actor)->where('u.id', $id)->first();
            abort_unless($target, 404);
            $access->authorize($actor, 'M01.user_password_reset', $target);
            if ($target->version !== $expectedVersion) {
                throw ValidationException::withMessages(['password' => 'تغير الحساب. أعد فتح النموذج.']);
            }
            $data = Validator::make($input, ['password' => ['required', 'string', Password::min(12)->mixedCase()->numbers(), 'confirmed', 'max:255']])->validate();
            DB::table('users')->where('id', $id)->update(['password' => Hash::make($data['password']), 'version' => $target->version + 1, 'updated_at' => now()]);
            DB::table('password_reset_tokens')->where('email', $target->email)->delete();
            app(RevokeUserSessions::class)->execute($id);
            event(new MasterChanged($actor->id, 'user', $id, 'password_reset', null, ['sessions_revoked' => true]));
        });
    }
}
