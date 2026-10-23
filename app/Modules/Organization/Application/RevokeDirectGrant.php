<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RevokeDirectGrant
{
    public function execute(User $actor, int $grantId): void
    {
        DB::transaction(function () use ($actor, $grantId) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $guard->authorize($actor);
            $grant = DB::table('access_grants')->where('id', $grantId)->lockForUpdate()->first();
            abort_unless($grant, 404);
            abort_if($grant->user_id === $actor->id, 403, 'تغيير منح حسابك يتم بواسطة مسؤول أمان آخر.');
            DB::table('access_grants')->where('id', $grantId)->delete();
            $guard->assertAdministratorRemains();
            $guard->audit($actor, $grant->user_id, 'direct_grant.revoked', ['permission' => $grant->permission_code, 'branch_id' => $grant->branch_id], ['revoked' => true]);
        });
    }
}
