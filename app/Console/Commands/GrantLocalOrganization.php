<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GrantLocalOrganization extends Command
{
    protected $signature = 'erp:grant-local-organization {email}';

    protected $description = 'Explicitly grant M01 master setup access to a local development operator';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Local development only.');

            return self::FAILURE;
        }
        $user = User::where('email', $this->argument('email'))->firstOrFail();
        DB::transaction(function () use ($user) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $permissions = ['M01.company_view', 'M01.company_update', 'M01.branch_create', 'M01.branch_admin', 'M01.user_admin', 'M01.user_create'];
            $before = DB::table('access_grants')->where('user_id', $user->id)->orderBy('id')->get()->toArray();
            foreach ($permissions as $permission) {
                DB::table('access_grants')->insertOrIgnore(['user_id' => $user->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('access_audits')->insert([
                'actor_id' => $user->id, 'subject_user_id' => $user->id, 'action' => 'local_cli.setup_granted',
                'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_snapshot' => json_encode(DB::table('access_grants')->where('user_id', $user->id)->orderBy('id')->get()->toArray(), JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'outcome' => 'succeeded',
            ]);
        });
        $this->info('Explicit M01 setup grants recorded. No transaction, payroll or warehouse authority granted.');

        return self::SUCCESS;
    }
}
