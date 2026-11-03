<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class EmployeeActions
{
    public function save(int $actor, ?int $id, int $version, array $input): int
    {
        return DB::transaction(function () use ($actor, $id, $version, $input) {
            app(RbacGuard::class)->lock();
            $old = $id ? DB::table('organization_employees')->lockForUpdate()->find($id) : null;
            abort_unless(! $id || $old, 404);
            $branch = $old?->branch_id ?? (int) ($input['branch_id'] ?? 0);
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M01.employee_manage', $branch));
            $input['code'] = mb_strtoupper(trim((string) ($input['code'] ?? '')));
            $d = Validator::make($input, [
                'code' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN_-]+$/uD', Rule::unique('organization_employees', 'code')->ignore($id)],
                'name' => 'required|string|min:2|max:200|not_regex:/\x00/', 'branch_id' => 'required|integer|min:1',
                'user_id' => ['nullable', 'integer', Rule::unique('organization_employees', 'user_id')->ignore($id)],
                'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
                'active' => 'required|boolean', 'job_title' => 'nullable|string|max:200|not_regex:/\x00/',
            ])->validate();
            abort_unless(DB::table('branches')->where('id', $branch)->where('company_id', 1)->where('active', true)->exists(), 422, 'يلزم فرع شركة نشط.');
            abort_if($old && ($old->version !== $version || $old->code !== $d['code'] || $old->branch_id !== (int) $d['branch_id']), 409, 'هوية الموظف أو نسخته تغيرت.');
            if (! empty($d['user_id'])) {
                $account = DB::table('users')->where('id', $d['user_id'])->first();
                abort_unless($account, 422, 'الحساب المرتبط غير متاح.');
                $operator = app(PermissionAccess::class)->actor($actor);
                abort_unless($operator && app(UserAccess::class)->can($operator, 'M01.user_view', $account), 403);
            }
            $values = $d + ['version' => $old ? $version + 1 : 1, 'updated_at' => now()];
            if ($old) {
                DB::table('organization_employees')->where('id', $id)->update($values);
            } else {
                $id = DB::table('organization_employees')->insertGetId($values + ['created_at' => now()]);
            }
            event(new MasterChanged($actor, 'employee', $id, $old ? 'updated' : 'created', $old ? (array) $old : null, $values));

            return $id;
        }, 3);
    }
}
