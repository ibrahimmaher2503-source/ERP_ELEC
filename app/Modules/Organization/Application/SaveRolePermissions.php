<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveRolePermissions
{
    public function execute(User $actor, int $roleId, array $codes, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $roleId, $codes, $expectedVersion) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $guard->authorize($actor);
            $role = DB::table('roles')->where('id', $roleId)->lockForUpdate()->first();
            abort_unless($role, 404);
            if ($role->code === 'Administrator') {
                throw ValidationException::withMessages(['permissions' => 'مدير النظام يحصل تلقائيًا على جميع الصلاحيات الحالية والجديدة؛ لا تحتاج مصفوفتُه إلى تعديل يدوي.']);
            }
            if ($role->version !== $expectedVersion) {
                throw ValidationException::withMessages(['permissions' => 'تغير الدور. حدّث الصفحة قبل حفظ المصفوفة.']);
            }
            abort_if(DB::table('user_roles')->where('user_id', $actor->id)->where('role_id', $roleId)->exists(), 403, 'لا يمكن تعديل صلاحيات دور مرتبط بحسابك.');
            Validator::make(['codes' => $codes], ['codes' => ['array', 'max:500'], 'codes.*' => ['required', 'string', 'distinct', Rule::in(array_keys(PermissionCatalog::DEFINITIONS))]])->validate();
            if ($role->code === 'ManagerReadOnly' && array_diff($codes, ['M01.company_view', 'M01.branch_view', 'M01.user_view', 'M02.product_view', 'M02.notes_view', 'M03.customer_view', 'M03.document_view', 'M03.notes_view', 'M03.history_view', 'M03.supplier_view', 'M03.supplier_document_view', 'M03.supplier_notes_view', 'M03.supplier_history_view', 'M04.quotation_view', 'M04.quotation_document_view', 'M04.quotation_history', 'M04.sales_order_view', 'M04.sales_order_history', 'M04.sales_order_invoice_view', 'M04.sales_order_return_view', 'M06.warehouse_view', 'M06.dashboard_view', 'M06.balance_view', 'M06.movement_view', 'M06.reservation_view', 'M06.export', 'M05.requisition_view', 'M05.rfq_view', 'M05.offer_view', 'M05.comparison_view', 'M05.order_view', 'M05.receipt_view', 'M05.return_view', 'M05.dashboard_view', 'M05.report_view', 'M05.export', 'M07.project_view', 'M07.budget_view', 'M07.report_view', 'M07.export']) !== []) {
                throw ValidationException::withMessages(['permissions' => 'دور مدير القراءة فقط لا يقبل صلاحيات تغيير البيانات.']);
            }
            $before = DB::table('role_permissions')->where('role_id', $roleId)->orderBy('permission_code')->pluck('permission_code')->all();
            if (in_array('M01.security_admin', $codes, true) !== ($role->code === 'Administrator')) {
                throw ValidationException::withMessages(['permissions' => 'صلاحية إدارة الأمان محمية ومخصصة لدور مدير النظام.']);
            }
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            foreach ($codes as $code) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_code' => $code, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('roles')->where('id', $roleId)->update(['version' => $role->version + 1, 'updated_at' => now()]);
            $guard->assertAdministratorRemains();
            $guard->audit($actor, $actor->id, 'role.permissions_changed', ['role_id' => $roleId, 'permissions' => $before], ['role_id' => $roleId, 'permissions' => array_values($codes)]);
        });
    }
}
