<?php

namespace App\Modules\Sales\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Sales\Domain\SalesOrderChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SalesOrderApprovalPolicy
{
    public function configuration(int $actor): ?object
    {
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M01.security_admin'));

        return DB::table('sales_order_policies')->where('id', 1)->first();
    }

    public function save(int $actor, int $roleId, ?int $version): void
    {
        $roles = app(OrganizationApi::class)->securityRoles($actor);
        Validator::make(['role' => $roleId], ['role' => ['required', Rule::in(array_column($roles, 'id'))]])->validate();
        DB::transaction(function () use ($actor, $roleId, $version) {
            DB::select('SELECT pg_advisory_xact_lock(40421)');
            $old = $this->configuration($actor);
            if (($old?->version) !== $version) {
                throw ValidationException::withMessages(['policy' => 'تغيرت الإعدادات؛ أعد تحميل الصفحة.']);
            }
            $data = ['exception_role_id' => $roleId, 'version' => ($version ?? 0) + 1, 'updated_by' => $actor, 'updated_at' => now()];
            DB::table('sales_order_policies')->updateOrInsert(['id' => 1], $data + ['created_at' => $old?->created_at ?? now()]);
            event(new SalesOrderChanged($actor, 0, 'approval_policy_changed', $old === null ? null : ['role_id' => $old->exception_role_id, 'version' => $old->version], ['role_id' => $roleId, 'version' => $data['version']]));
        });
    }

    public function authorizeApprover(int $actor, int $requester, int $branch, bool $exception = false): void
    {
        app(SalesOrderAccess::class)->authorize($actor, 'view', $branch);
        app(SalesOrderAccess::class)->authorize($actor, $exception ? 'exception_approve' : 'approve', $branch);
        abort_if($actor === $requester, 403, 'الموافقة الذاتية لأوامر البيع غير مسموحة.');
        if ($exception) {
            $policy = DB::table('sales_order_policies')->where('id', 1)->first();
            if (! $policy) {
                throw ValidationException::withMessages(['policy' => 'حدد دور مسؤول الاستثناءات في الإعدادات أولًا.']);
            }
            abort_unless(app(OrganizationApi::class)->hasRole($actor, (int) $policy->exception_role_id), 403, 'قرار الاستثناء للمسؤول المحدد فقط.');
        }
    }
}
