<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeBranchStatus
{
    public function execute(User $actor, int $id, bool $active, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $id, $active, $expectedVersion) {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            app(OrganizationAccess::class)->authorizeBranch($actor, 'M01.branch_status', $id);
            $branch = DB::table('branches')->where('id', $id)->lockForUpdate()->first();
            abort_unless($branch, 404);
            if ($branch->version !== $expectedVersion) {
                throw ValidationException::withMessages(['status' => 'تغيرت بيانات الفرع. أعد المحاولة بعد تحديث الصفحة.']);
            }
            if (! $active && DB::table('users')->where('branch_id', $id)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['status' => 'لا يمكن تعطيل فرع مرتبط بمستخدمين نشطين. انقل تكليفاتهم أولًا.']);
            }
            DB::table('branches')->where('id', $id)->update(['active' => $active, 'version' => $branch->version + 1, 'updated_at' => now()]);
            event(new MasterChanged($actor->id, 'branch', $id, $active ? 'activated' : 'deactivated', (array) $branch, (array) DB::table('branches')->where('id', $id)->first()));
        });
    }
}
