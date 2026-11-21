<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InventoryAccess
{
    private const ACTION_META = [
        'isolation_manage' => ['عزل المخزون غير المتاح', true],
        'isolation_release' => ['تحرير المخزون المعزول', true],
        'correction_execute' => ['تصحيح حركات المخزون المرتبطة بمصدر', true],
        'serial_view' => ['عرض الأرقام المسلسلة وسجلها', true],
        'reorder_manage' => ['إدارة حدود تنبيه المخزون', true],
        'warehouse_view' => ['عرض المستودعات', false],
        'warehouse_create' => ['إنشاء المستودعات', false],
        'warehouse_update' => ['تعديل المستودعات', false],
        'dashboard_view' => ['عرض لوحة المخزون', true],
        'balance_view' => ['عرض أرصدة المخزون', true],
        'movement_view' => ['عرض حركات المخزون', true],
        'reservation_view' => ['عرض حجوزات المخزون', true],
        'reservation_manage' => ['إدارة حجوزات المخزون', true],
        'receipt_create' => ['إنشاء استلام وأرصدة افتتاحية', true],
        'receipt_approve' => ['اعتماد الاستلام والأرصدة الافتتاحية', true],
        'receipt_post' => ['ترحيل الاستلام والأرصدة الافتتاحية', true],
        'issue_create' => ['إنشاء صرف مخزون', true],
        'issue_approve' => ['اعتماد صرف المخزون', true],
        'issue_post' => ['ترحيل صرف المخزون', true],
        'transfer_create' => ['إنشاء تحويل مخزون', true],
        'transfer_approve' => ['اعتماد تحويل المخزون', true],
        'transfer_dispatch' => ['إرسال تحويل المخزون', true],
        'transfer_receive' => ['استلام تحويل المخزون', true],
        'adjustment_create' => ['إنشاء تسوية مخزون', true],
        'adjustment_approve' => ['اعتماد تسوية مخزون', true],
        'adjustment_post' => ['ترحيل تسوية المخزون', true],
        'count_create' => ['إنشاء جرد المخزون', true],
        'count_submit' => ['إرسال جرد المخزون', true],
        'count_approve' => ['مراجعة واعتماد الجرد', true],
        'export' => ['تصدير المخزون', true],
    ];

    public function denialMessage(int $actor, string $action, ?int $warehouse = null): string
    {
        $code = 'M06.'.$action;
        [$label, $needsWarehouse] = self::ACTION_META[$action] ?? [$code, true];
        $parts = ['يتطلب هذا الإجراء صلاحية «'.$label.'» ('.$code.')'];

        if ($needsWarehouse) {
            if ($warehouse !== null) {
                $warehouseName = DB::table('inventory_warehouses')->where('id', $warehouse)->value('name');
                $parts[] = 'ونطاق فرع ومستودع فعّال ('.($warehouseName ?: 'مستودع #'.$warehouse).')';
            } else {
                $hasActiveWarehouses = DB::table('inventory_warehouses')->where('active', true)->exists();
                if (! $hasActiveWarehouses) {
                    $parts[] = 'ووجود مستودع نشط واحد على الأقل ونطاق مستودع ممنوح للحساب';
                } else {
                    try {
                        $scopes = app(OrganizationApi::class)->inventoryScopes($actor);
                        if ($scopes === []) {
                            $parts[] = 'ونطاق فرع ومستودع مضافًا للحساب (لا يوجد أي نطاق مستودع ممنوح لهذا الحساب حاليًا)';
                        } else {
                            $parts[] = 'ضمن نطاق المستودعات المصرح بها للحساب';
                        }
                    } catch (ContractException) {
                        $parts[] = 'وحسابًا نشطًا ومؤكد البريد مع نطاق فرع ومستودع';
                    }
                }
            }
        }

        return implode(' ', $parts).'. يمنحها مدير النظام أو صاحب صلاحية إدارة الأدوار والتفويض الأمني (M01.security_admin) من تفاصيل المستخدم ← الأدوار ونطاقات الوصول.';
    }

    public function authorize(int $actor, string $action, ?int $warehouse = null): void
    {
        try {
            $branch = $warehouse === null ? null : DB::table('inventory_warehouses')->where('id', $warehouse)->value('branch_id');
            abort_if($warehouse !== null && ! $branch, 404);
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M06.'.$action, $branch, $warehouse));
        } catch (ContractException $e) {
            abort($e->getCode(), $this->denialMessage($actor, $action, $warehouse));
        }
    }

    public function allows(int $actor, string $action, ?int $warehouse = null): bool
    {
        try {
            $this->authorize($actor, $action, $warehouse);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    public function ids(int $actor, string $action): array
    {
        try {
            return array_values(array_column(array_filter(app(OrganizationApi::class)->inventoryScopes($actor), fn ($s) => in_array('M06.'.$action, $s['permissions'], true)), 'warehouse_id'));
        } catch (ContractException) {
            return [];
        }
    }

    public function navigation(int $actor): array
    {
        $links = [];
        try {
            $scopes = app(OrganizationApi::class)->inventoryScopes($actor);
        } catch (ContractException) {
            return [];
        }
        $effective = array_unique(array_merge(...array_column($scopes, 'permissions')));
        $has = fn ($action) => in_array('M06.'.$action, $effective, true);
        foreach (['dashboard' => 'dashboard_view', 'warehouses' => 'warehouse_view', 'balances' => 'balance_view', 'movements' => 'movement_view', 'reservations' => 'reservation_view'] as $route => $action) {
            if ($route === 'warehouses' ? $this->allows($actor, $action) : $has($action)) {
                $links[$route] = true;
            }
        }
        foreach (['transfers' => 'transfer', 'counts' => 'count', 'adjustments' => 'adjustment', 'receipts' => 'opening', 'issues' => 'issue'] as $route => $kind) {
            foreach (['create', 'approve', ...($kind === 'transfer' ? ['dispatch', 'receive'] : ($kind === 'count' ? ['submit'] : ['post']))] as $action) {
                if ($has(app(InventoryDocuments::class)->permission($kind, $action))) {
                    $links[$route] = true;
                }
            }
        }

        $links['serials'] = $has('serial_view');
        $links['isolations'] = $has('isolation_manage') || $has('isolation_release');
        $links['corrections'] = $has('correction_execute');

        return $links;
    }
}
