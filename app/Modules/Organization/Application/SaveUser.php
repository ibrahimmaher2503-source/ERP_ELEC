<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SaveUser
{
    public function execute(User $actor, array $input, ?int $id = null, ?int $expectedVersion = null): object
    {
        try {
            return DB::transaction(function () use ($actor, $input, $id, $expectedVersion) {
                app(RbacGuard::class)->lock();
                $access = app(UserAccess::class);
                $access->lockAccounts($actor, $id);
                $target = $id ? $access->query($actor)->where('u.id', $id)->first() : null;
                abort_if($id && ! $target, 404);
                $access->authorize($actor, $id ? 'M01.user_update' : 'M01.user_create', $target);
                if ($target && $target->version !== $expectedVersion) {
                    throw ValidationException::withMessages(['name' => 'تغير الحساب. أعد فتح النموذج.']);
                }
                foreach (['name', 'email'] as $field) {
                    if (is_string($input[$field] ?? null)) {
                        $input[$field] = trim($input[$field]);
                    }
                }
                if (is_string($input['email'] ?? null)) {
                    $input['email'] = mb_strtolower($input['email']);
                }
                $data = Validator::make($input, [
                    'name' => ['required', 'string', 'max:200', 'not_regex:/\x00/u'],
                    'gender' => ['required', Rule::in(['male', 'female'])],
                    'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
                    'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                    'password' => $id ? ['prohibited'] : ['required', 'string', Password::min(12)->mixedCase()->numbers(), 'confirmed', 'max:255'],
                ])->validate();
                $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null;
                if ($branchId !== $target?->branch_id && $branchId !== null) {
                    $branch = DB::table('branches')->where('id', $branchId)->lockForUpdate()->first();
                    if (! $branch?->active) {
                        throw ValidationException::withMessages(['branch_id' => 'اختر فرعًا نشطًا.']);
                    }
                    if (! $access->can($actor, 'M01.user_admin')) {
                        abort_unless(app(PermissionAccess::class)->allows($actor, 'M01.user_update', $branchId), 403);
                    }
                }
                if ($target && $branchId === null && $target->branch_id !== null && ! $access->can($actor, 'M01.user_admin')) {
                    abort(403);
                }
                $data['branch_id'] = $branchId;
                $data['updated_at'] = now();
                if ($id) {
                    $emailChanged = mb_strtolower($target->email) !== $data['email'];
                    $data['version'] = $target->version + 1;
                    if ($emailChanged) {
                        $data['email_verified_at'] = null;
                        DB::table('password_reset_tokens')->where('email', $target->email)->delete();
                        app(RevokeUserSessions::class)->execute($id);
                    }
                    DB::table('users')->where('id', $id)->update($data);
                    if ($emailChanged && app(PermissionAccess::class)->securityDesignated($id)) {
                        app(RbacGuard::class)->assertAdministratorRemains();
                    }
                } else {
                    $data['password'] = Hash::make($data['password']);
                    $id = DB::table('users')->insertGetId($data + ['active' => true, 'version' => 1, 'session_version' => 1, 'created_at' => now()]);
                }
                $saved = $access->query($actor)->where('u.id', $id)->first();
                // A creator without view grants may provision an account but cannot read its records.
                $safeAfter = DB::table('users')->where('id', $id)->first(['id', 'name', 'email', 'gender', 'active', 'branch_id', 'version']);
                event(new MasterChanged($actor->id, 'user', $id, $target ? 'updated' : 'created', $target ? (array) $target : null, (array) $safeAfter));

                return $saved ?? $safeAfter;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['email' => 'البريد الإلكتروني مسجل مسبقًا.']);
            }
            throw $exception;
        }
    }
}
