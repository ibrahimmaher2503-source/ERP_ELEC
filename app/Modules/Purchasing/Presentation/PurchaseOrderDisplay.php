<?php

namespace App\Modules\Purchasing\Presentation;

/** Presentation-only helpers. Never used in financial calculations; stored decimals keep full precision. */
final class PurchaseOrderDisplay
{
    public const STATUSES = [
        'draft' => ['مسودة', 'neutral'],
        'submitted' => ['بانتظار الاعتماد', 'warning'],
        'approved' => ['معتمد داخليًا', 'success'],
        'rejected' => ['مرفوض', 'danger'],
    ];

    public const RECEIVING_STATUSES = [
        'not_received' => ['لم يُستلم', 'neutral'],
        'partially_received' => ['استلام جزئي', 'warning'],
        'received' => ['مُستلم بالكامل', 'success'],
    ];

    public const RECEIPT_STATUSES = [
        'draft' => ['مسودة غير مرحلة', 'neutral'],
        'integration_pending' => ['بانتظار المراجعة والترحيل', 'warning'],
        'posted' => ['مرحّل مع FIFO وGRNI', 'success'],
    ];

    public const REVIEW_ACTIONS = [
        'submit' => ['إرسال للاعتماد', 'info'],
        'approve' => ['اعتماد داخلي', 'success'],
        'reject' => ['رفض', 'danger'],
        'reopen' => ['إعادة للمسودة', 'neutral'],
    ];

    public const TAX_MODES = [
        'exclusive' => 'غير شامل الضريبة',
        'inclusive' => 'شامل الضريبة',
    ];

    public const CURRENCIES = [
        'EGP' => 'ج.م',
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
    public static function money(?string $value, string $fallback = '—'): string
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

    public static function receiving(string $status): array
    {
        return self::RECEIVING_STATUSES[$status] ?? [$status, 'neutral'];
    }

    public static function receiptStatus(string $status): array
    {
        return self::RECEIPT_STATUSES[$status] ?? [$status, 'neutral'];
    }

    public static function reviewAction(string $action): array
    {
        return self::REVIEW_ACTIONS[$action] ?? [$action, 'neutral'];
    }

    public static function currencyLabel(?string $code): string
    {
        return self::CURRENCIES[$code ?? 'EGP'] ?? (string) $code;
    }

    public static function stepIndex(string $status, bool $issued, string $receivingStatus): int
    {
        if ($status === 'rejected') {
            return -1;
        }
        if ($status === 'draft') {
            return 0;
        }
        if ($status === 'submitted') {
            return 1;
        }
        if ($receivingStatus !== 'not_received') {
            return 4;
        }
        if ($issued) {
            return 3;
        }

        return 2;
    }
}
