<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveQuotationSelfApproval
{
    public function execute(User $actor, string $type, int $target, bool $enabled, ?int $version): void
    {
        app(RbacGuard::class)->authorize($actor);
        Validator::make(['type' => $type, 'target' => $target], ['type' => ['required', Rule::in(['user', 'role'])], 'target' => ['required', 'integer', 'min:1']])->validate();
        DB::transaction(function () use ($actor, $type, $target, $enabled, $version) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $guard->authorize($actor);
            abort_unless(DB::table($type === 'user' ? 'users' : 'roles')->where('id', $target)->exists(), 404);
            $q = DB::table('quotation_self_approval_policies')->where('target_type', $type)->where('target_id', $target);
            $old = (clone $q)->lockForUpdate()->first();
            if (($old?->version) !== $version) {
                throw ValidationException::withMessages(['selfApproval' => 'تغير إعداد الموافقة الذاتية. أعد التحميل.']);
            }
            $data = ['target_type' => $type, 'target_id' => $target, 'enabled' => $enabled, 'version' => ($old?->version ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                $q->update($data);
            } else {
                DB::table('quotation_self_approval_policies')->insert($data + ['created_at' => now()]);
            }
            $guard->audit($actor, $type === 'user' ? $target : $actor->id, 'quotation.self_approval_policy_changed', ['target_type' => $type, 'target_id' => $target, 'enabled' => $old?->enabled ?? false], ['target_type' => $type, 'target_id' => $target, 'role_id' => $type === 'role' ? $target : null, 'enabled' => $enabled, 'version' => $data['version']]);
        });
    }
}
