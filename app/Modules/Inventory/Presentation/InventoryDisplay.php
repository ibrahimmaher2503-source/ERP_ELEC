<?php

namespace App\Modules\Inventory\Presentation;

/** Presentation-only helpers. Never used in calculations; stored values stay NUMERIC(24,8) decimal strings. */
final class InventoryDisplay
{
    public const OPERATIONS = [
        'financial_opening' => 'افتتاح مالي', 'purchase_receipt' => 'استلام مشتريات', 'opening_post' => 'رصيد افتتاحي تاريخي',
        'issue_post' => 'صرف', 'adjustment_post' => 'تسوية', 'transfer_dispatch' => 'إرسال تحويل', 'transfer_receive' => 'استلام تحويل',
        'correction' => 'تصحيح مصدر', 'reservation_issue' => 'صرف حجز', 'project_issue' => 'صرف مشروع',
        'maintenance_issue' => 'صرف صيانة', 'workshop_issue' => 'صرف ورشة',
        'sales_return' => 'مرتجع بيع', 'supplier_return' => 'مرتجع مشتريات', 'workshop_return' => 'مرتجع ورشة',
        'maintenance_return' => 'مرتجع صيانة',
    ];

    public const SERIAL_EVENTS = [
        'received' => 'استلام', 'issued' => 'صرف', 'reserved' => 'حجز', 'reservation_released' => 'فك حجز',
        'isolated' => 'عزل', 'isolation_released' => 'إفراج عن عزل', 'released' => 'إفراج',
    ];

    public const SOURCES = [
        'Inventory' => 'المخزون', 'inventory' => 'المخزون', 'Sales' => 'المبيعات', 'sales' => 'المبيعات', 'Purchasing' => 'المشتريات',
        'purchasing' => 'المشتريات', 'Projects' => 'المشروعات', 'projects' => 'المشروعات', 'FieldMaintenance' => 'الصيانة الميدانية',
        'maintenance' => 'الصيانة الميدانية', 'WorkshopRepairs' => 'الورش والإصلاح', 'workshop' => 'الورش والإصلاح',
    ];

    public const DOCUMENT_STATUS = [
        'draft' => ['مسودة', 'neutral'], 'submitted' => ['بانتظار الاعتماد', 'warning'], 'approved' => ['معتمد', 'info'],
        'dispatched' => ['قيد النقل', 'info'], 'posted' => ['مرحّل', 'success'], 'received' => ['مستلم', 'success'], 'cancelled' => ['ملغي', 'danger'],
    ];

    /** Trim insignificant decimal zeros and group thousands, e.g. 3.00000000 => 3, 1250.50000000 => 1,250.5 */
    public static function qty(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $sign = '';
        if ($value[0] === '-') {
            $sign = '-';
            $value = substr($value, 1);
        }
        [$int, $dec] = array_pad(explode('.', $value, 2), 2, '');
        $dec = rtrim($dec, '0');
        if (preg_match('/^\d{1,15}$/', $int)) {
            $int = number_format((int) $int, 0, '.', ',');
        }

        return $sign.$int.($dec !== '' ? '.'.$dec : '');
    }

    /** Trim insignificant decimal zeros without thousands commas for numeric form inputs. */
    public static function inputQty(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $sign = '';
        if ($value[0] === '-') {
            $sign = '-';
            $value = substr($value, 1);
        }
        [$int, $dec] = array_pad(explode('.', $value, 2), 2, '');
        $dec = rtrim($dec, '0');

        return $sign.$int.($dec !== '' ? '.'.$dec : '');
    }

    public static function operation(?string $code): string
    {
        return self::OPERATIONS[$code] ?? (string) $code;
    }

    public static function serialEvent(?string $action): string
    {
        return self::SERIAL_EVENTS[$action] ?? self::operation($action);
    }

    public static function documentStatus(string $kind, string $status): array
    {
        if ($kind === 'count' && $status === 'posted') {
            return ['مُعتمد (مغلق)', 'success'];
        }

        return self::DOCUMENT_STATUS[$status] ?? [$status, 'neutral'];
    }

    public static function source(?string $module): string
    {
        return self::SOURCES[$module] ?? (string) $module;
    }

    public static function isPositive(string $quantity): bool
    {
        return bccomp($quantity, '0', 8) > 0;
    }
}
