<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetUserBranchScope
{
    public function execute(User $actor, int $userId, int $branchId, bool $grant): void
    {
        DB::transaction(function () use ($actor, $userId, $branchId, $grant) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $guard->authorize($actor);
            abort_if($actor->id === $userId, 403, 'لا يمكن توسيع نطاق حسابك بنفسك.');
            $target = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['active']);
            $branch = DB::table('branches')->where('id', $branchId)->lockForUpdate()->first(['active']);
            abort_unless($target && $branch, 404);
            if ($grant && (! $target->active || ! $branch->active)) {
                throw ValidationException::withMessages(['selectedBranch' => 'منح نطاق جديد يحتاج حسابًا وفرعًا نشطين.']);
            }
            $before = DB::table('user_branch_scopes')->where('user_id', $userId)->orderBy('branch_id')->pluck('branch_id')->all();
            if ($grant) {
                DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $userId, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('user_branch_scopes')->where('user_id', $userId)->where('branch_id', $branchId)->delete();
            }
            $guard->audit($actor, $userId, $grant ? 'scope.branch_granted' : 'scope.branch_revoked', ['branches' => $before], ['branches' => DB::table('user_branch_scopes')->where('user_id', $userId)->orderBy('branch_id')->pluck('branch_id')->all()]);
        });
    }
}
