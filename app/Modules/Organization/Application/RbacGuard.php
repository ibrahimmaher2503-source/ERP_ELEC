<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RbacGuard
{
    public function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(10401)');
    }

    public function authorize(User $actor): void
    {
        abort_unless(app(PermissionAccess::class)->allows($actor, 'M01.security_admin') && DB::table('users')->where('id', $actor->id)->whereNotNull('email_verified_at')->exists(), 403, 'إدارة الأمان تحتاج تفويضًا صريحًا.');
    }

    public function assertAdministratorRemains(): void
    {
        $exists = DB::table('users as u')->where('u.active', true)->whereNotNull('u.email_verified_at')->where(function ($query) {
            $query->whereExists(function ($query) {
                $query->selectRaw('1')->from('access_grants as ag')->whereColumn('ag.user_id', 'u.id')->where('ag.permission_code', 'M01.security_admin')->whereNull('ag.branch_id');
            })->orWhereExists(function ($query) {
                $query->selectRaw('1')->from('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->whereColumn('ur.user_id', 'u.id')->where('r.code', 'Administrator');
            })->orWhereExists(function ($query) {
                $query->selectRaw('1')->from('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->whereColumn('ur.user_id', 'u.id')->where('rp.permission_code', 'M01.security_admin');
            });
        })->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['assignment' => 'يجب بقاء مسؤول أمان نشط ومؤكد البريد على الأقل.']);
        }
    }

    public function audit(User $actor, int $subjectId, string $action, array $before, array $after): void
    {
        DB::table('access_audits')->insert([
            'actor_id' => $actor->id, 'subject_user_id' => $subjectId, 'action' => $action,
            'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR), 'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'occurred_at' => now(), 'outcome' => 'succeeded',
        ]);
    }
}
