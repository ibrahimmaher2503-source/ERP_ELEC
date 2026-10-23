<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Organization\Application\RbacGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BootstrapSecurity extends Command
{
    protected $signature = 'erp:bootstrap-security {email}';

    protected $description = 'Grant only explicit local security-configuration authority to an existing verified account';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Local development only.');

            return self::FAILURE;
        }
        $actor = User::where('email', $this->argument('email'))->where('active', true)->whereNotNull('email_verified_at')->firstOrFail();
        DB::transaction(function () use ($actor) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $inserted = DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            if ($inserted) {
                $guard->audit($actor, $actor->id, 'local_cli.security_bootstrap', ['security_admin' => false], ['security_admin' => true]);
            }
        });
        $this->info('Security configuration authority recorded. No operational permission or scope was added.');

        return self::SUCCESS;
    }
}
