<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Organization\Application\RbacGuard;
use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Registration is closed, so the first account on an empty system is created from the server shell. */
class CreateFirstAdministrator extends Command
{
    protected $signature = 'erp:create-first-admin {email} {--name=مدير النظام}';

    protected $description = 'Create the first Administrator. Refuses to run once any user exists.';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('Users already exist. Manage accounts from the application.');

            return self::FAILURE;
        }
        $validator = Validator::make(['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $this->secret('Password')],
            ['name' => ['required', 'string', 'max:200'], 'email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        DB::transaction(function () use ($validator) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            abort_if(User::query()->exists(), 409);
            $user = User::create($validator->validated());
            $user->markEmailAsVerified();
            $role = DB::table('roles')->where('code', 'Administrator')->value('id')
                ?? DB::table('roles')->insertGetId(['code' => 'Administrator', 'name' => 'مدير النظام', 'protected' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('access_grants')->insertOrIgnore(['user_id' => $user->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            app(SyncAdministratorPermissions::class)->execute();
            $guard->audit($user, $user->id, 'cli.first_administrator_created', ['users' => 0], ['role' => 'Administrator']);
        });
        $this->info('First Administrator created.');

        return self::SUCCESS;
    }
}
