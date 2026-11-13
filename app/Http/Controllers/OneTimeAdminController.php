<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Organization\Application\RbacGuard;
use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OneTimeAdminController extends Controller
{
    public function show(Request $request, string $token)
    {
        $this->assertAvailable($token);

        return view('pages.auth.one-time-admin', [
            'token' => $token,
            'created' => false,
        ]);
    }

    public function store(Request $request, string $token)
    {
        $this->assertAvailable($token);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ], [
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except('password', 'password_confirmation'));
        }

        $user = DB::transaction(function () use ($validator) {
            $guard = app(RbacGuard::class);
            $guard->lock();

            if (User::query()->exists()) {
                throw new NotFoundHttpException;
            }

            $user = User::create($validator->validated());
            $user->markEmailAsVerified();

            $role = DB::table('roles')->where('code', 'Administrator')->value('id')
                ?? DB::table('roles')->insertGetId([
                    'code' => 'Administrator',
                    'name' => 'مدير النظام',
                    'protected' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('user_roles')->insert([
                'user_id' => $user->id,
                'role_id' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('access_grants')->insertOrIgnore([
                'user_id' => $user->id,
                'permission_code' => 'M01.security_admin',
                'branch_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(SyncAdministratorPermissions::class)->execute();
            $guard->audit($user, $user->id, 'web.one_time_administrator_created', ['users' => 0], ['role' => 'Administrator']);

            return $user;
        });

        @unlink($this->tokenFile());

        return view('pages.auth.one-time-admin', [
            'token' => null,
            'created' => true,
            'user' => $user,
        ]);
    }

    private function assertAvailable(string $token): void
    {
        if (User::query()->exists()) {
            throw new NotFoundHttpException;
        }

        $storedHash = @file_get_contents($this->tokenFile());
        if (! is_string($storedHash) || ! hash_equals(trim($storedHash), hash('sha256', $token))) {
            throw new NotFoundHttpException;
        }
    }

    private function tokenFile(): string
    {
        return config('erp.bootstrap_token_file', '/var/lib/erp-elec-staging/one-time-admin-token.sha256');
    }
}
