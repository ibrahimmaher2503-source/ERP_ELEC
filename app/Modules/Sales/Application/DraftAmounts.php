<?php

namespace App\Modules\Sales\Application;

use Illuminate\Validation\ValidationException;

/** Exact, unrounded draft arithmetic. Official rounding/tax remains Accounting-owned. */
final class DraftAmounts
{
    const SCALE = 40;

    public static function decimal(mixed $value, string $key, bool $positive = false): string
    {
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,8})?$/D', $value) || ($positive && bccomp($value, '0', 8) <= 0)) {
            throw ValidationException::withMessages([$key => 'أدخل رقمًا عشريًا صالحًا'.($positive ? ' أكبر من صفر.' : '.')]);
        }

        return $value;
    }

    private function discount(string $base, string $mode, string $value, string $key): string
    {
        self::decimal($value, $key);
        if (! in_array($mode, ['percent', 'fixed'], true)) {
            throw ValidationException::withMessages([$key => 'اختر نوع الخصم.']);
        }
        if ($mode === 'percent' && bccomp($value, '100', 8) > 0) {
            throw ValidationException::withMessages([$key => 'النسبة لا تتجاوز 100%.']);
        }
        $result = $mode === 'percent' ? bcdiv(bcmul($base, $value, self::SCALE), '100', self::SCALE) : $value;
        if (bccomp($result, $base, self::SCALE) > 0) {
            throw ValidationException::withMessages([$key => 'الخصم يتجاوز القيمة المتاحة.']);
        }

        return bcadd($result, '0', self::SCALE);
    }

    public function calculate(array $lines, string $mode = 'percent', string $value = '0'): array
    {
        self::decimal($value, 'discount_value');
        if (! in_array($mode, ['percent', 'fixed'], true) || ($mode === 'percent' && bccomp($value, '100', 8) > 0)) {
            throw ValidationException::withMessages(['discount_value' => 'خصم العرض غير صالح.']);
        }
        $gross = '0';
        $discount = '0';
        $complete = true;
        $out = [];
        foreach ($lines as $i => $line) {
            $qty = self::decimal($line['quantity'] ?? null, "lines.$i.quantity", true);
            $d = self::decimal($line['discount_value'] ?? '0', "lines.$i.discount_value");
            $m = $line['discount_mode'] ?? 'percent';
            if (! in_array($m, ['percent', 'fixed'], true) || ($m === 'percent' && bccomp($d, '100', 8) > 0)) {
                throw ValidationException::withMessages(["lines.$i.discount_value" => 'خصم البند غير صالح.']);
            }
            $price = ($line['requested_price'] ?? '') === '' ? ($line['suggested_price'] ?? null) : $line['requested_price'];
            if ($price === null || $price === '') {
                $complete = false;
                $out[] = ['gross' => null, 'discount' => null, 'net' => null];

                continue;
            }
            self::decimal($price, "lines.$i.requested_price");
            $base = bcmul($qty, $price, self::SCALE);
            $cut = $this->discount($base, $m, $d, "lines.$i.discount_value");
            $gross = bcadd($gross, $base, self::SCALE);
            $discount = bcadd($discount, $cut, self::SCALE);
            $out[] = ['gross' => $base, 'discount' => $cut, 'net' => bcsub($base, $cut, self::SCALE)];
        }
        $net = bcsub($gross, $discount, self::SCALE);
        $global = $complete ? $this->discount($net, $mode, $value, 'discount_value') : null;

        return ['lines' => $out, 'complete' => $complete, 'gross' => $complete ? $gross : null, 'line_discounts' => $complete ? $discount : null, 'quotation_discount' => $global, 'net' => $complete ? bcsub($net, $global, self::SCALE) : null, 'tax' => null, 'total' => null, 'currency' => 'EGP', 'official' => false];
    }

    public static function display(?string $value): string
    {
        if ($value === null) {
            return 'غير محسوب';
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        if ($value === '' || $value === '-') {
            $value = '0';
        }

        return $value;
    }
}
