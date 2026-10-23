<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Modules\Organization\Application\RevokeUserSessions;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input) {
            $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $current->active) {
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }
            $current->forceFill(['password' => $input['password'], 'version' => $current->version + 1])->save();
            app(RevokeUserSessions::class)->execute($current->id);
            event(new MasterChanged($current->id, 'user', $current->id, 'self_password_reset', null, ['sessions_revoked' => true]));
        });
    }
}
