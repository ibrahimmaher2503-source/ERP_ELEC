<?php

namespace App\Modules\Sales\Presentation;

/** Presentation-only helpers. Never used in financial calculations; stored decimals keep full precision. */
final class SalesOrderDisplay
{
    public const STATUSES = [
        'draft' => ['مسودة', 'neutral'],
        'pending_approval' => ['بانتظار المراجعة', 'warning'],
        'approved' => ['معتمد', 'success'],
        'cancelled' => ['ملغي', 'danger'],
    ];

    public const RESERVATION_STATUSES = [
        'not_requested' => ['لم يُطلب حجز', 'neutral'],
        'reserved' => ['محجوز', 'info'],
        'backorder' => ['طلب مؤجل', 'warning'],
        'fulfilled' => ['نُفّذ الحجز', 'success'],
        'restricted' => ['محجوب بالنطاق', 'neutral'],
    ];

    public const DELIVERY_STATUSES = [
        'not_started' => ['لم يبدأ التسليم', 'neutral'],
        'partial' => ['تسليم جزئي', 'warning'],
        'fulfilled' => ['مُسلَّم بالكامل', 'success'],
    ];

    public const INVOICE_STATUSES = [
        'none' => ['بدون فاتورة', 'neutral'],
        'requested' => ['فاتورة قيد الإعداد', 'warning'],
        'invoiced' => ['صدرت فاتورة', 'success'],
        'restricted' => ['محجوبة بالصلاحية', 'neutral'],
    ];

    public const SOURCES = [
        'direct' => 'أمر مباشر',
        'quotation' => 'من عرض سعر',
    ];

    public const HISTORY_ACTIONS = [
        'draft_created' => 'إنشاء المسودة',
        'draft_updated' => 'تحديث المسودة',
        'converted_from_quotation' => 'تحويل من عرض سعر',
        'review_submitted' => 'تقديم للمراجعة',
        'review_withdrawn' => 'سحب طلب المراجعة',
        'review_rejected' => 'رفض المراجعة وإرجاع للتعديل',
        'commercial_exceptions_approved' => 'اعتماد الاستثناءات التجارية',
        'credit_limit_exception' => 'تجاوز حد ائتمان معتمد',
        'approved_and_reserved' => 'اعتماد الأمر وطلب الحجز',
        'delivery_instructed' => 'إنشاء طلب تسليم',
        'delivery_confirmed' => 'تأكيد التسليم وترحيل المخزون',
        'unexecuted_cancelled' => 'إلغاء أمر غير منفذ',
        'demo.created' => 'إنشاء بيانات محلية تجريبية',
    ];

    public const EXCEPTION_LABELS = [
        'price' => 'تعديل سعر عن السعر المرجعي',
        'line_discount' => 'خصم على البند',
        'order_discount' => 'خصم إجمالي على الأمر',
        'payment_terms' => 'شروط دفع غير قياسية',
        'delivery_terms' => 'شروط تسليم غير قياسية',
    ];

    /** Trim insignificant decimal zeros and group thousands without floating-point math. */
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

    /** Format monetary decimal string with thousands commas and at least 2 decimal places, never rounding. */
    public static function money(?string $value, string $fallback = 'غير محسوب'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        $sign = '';
        if ($value[0] === '-') {
            $sign = '-';
            $value = substr($value, 1);
        }
        [$int, $dec] = array_pad(explode('.', $value, 2), 2, '');
        $dec = rtrim($dec, '0');
        $dec = str_pad($dec, 2, '0');
        if (preg_match('/^\d{1,15}$/', $int)) {
            $int = number_format((int) $int, 0, '.', ',');
        }

        return $sign.$int.'.'.$dec;
    }

    /** Trim insignificant zeros without thousands commas for numeric form inputs. */
    public static function inputDecimal(?string $value): string
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

    public static function status(string $status): array
    {
        return self::STATUSES[$status] ?? [$status, 'neutral'];
    }

    public static function reservation(string $state): array
    {
        return self::RESERVATION_STATUSES[$state] ?? [$state, 'neutral'];
    }

    public static function delivery(string $state): array
    {
        return self::DELIVERY_STATUSES[$state] ?? [$state, 'neutral'];
    }

    public static function invoicing(string $state): array
    {
        return self::INVOICE_STATUSES[$state] ?? [$state, 'neutral'];
    }

    public static function historyAction(?string $action): string
    {
        return self::HISTORY_ACTIONS[$action ?? ''] ?? 'تحديث أمر البيع';
    }

    public static function stepIndex(string $status, array $progress): int
    {
        if ($status === 'cancelled') {
            return -1;
        }
        if ($status === 'draft') {
            return 0;
        }
        if ($status === 'pending_approval') {
            return 1;
        }
        if (($progress['invoicing'] ?? null) === 'invoiced') {
            return 4;
        }
        if (in_array($progress['delivery'] ?? null, ['partial', 'fulfilled'], true)) {
            return 3;
        }

        return 2;
    }
}
