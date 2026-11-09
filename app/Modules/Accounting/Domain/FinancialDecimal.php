<?php

namespace App\Modules\Accounting\Domain;

use Illuminate\Validation\ValidationException;

/** All authoritative arithmetic uses decimal strings. No locale or exponent parsing. */
final class FinancialDecimal
{
    public const SCALE = 32;

    public static function value(mixed $value, int $places = 16, bool $signed = false): string
    {
        $sign = $signed ? '-?' : '';
        if (! is_string($value) || ! preg_match('/^'.$sign.'(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,'.$places.'})?$/D', $value)) {
            throw ValidationException::withMessages(['form' => 'أدخل مبلغًا عشريًا نصيًا صالحًا ضمن الدقة المسموحة.']);
        }

        return $value;
    }

    /** Half away from zero, including negative adjustments. */
    public static function round(string $value, int $places = 2): string
    {
        if ($places < 0 || $places > 16) {
            throw new \InvalidArgumentException('دقة المبلغ المالي غير صالحة.');
        }
        $negative = bccomp($value, '0', self::SCALE) < 0;
        $absolute = $negative ? bcsub('0', $value, self::SCALE) : $value;
        $half = $places === 0 ? '0.5' : '0.'.str_repeat('0', $places).'5';
        $result = bcadd($absolute, $half, $places);

        return $negative && bccomp($result, '0', $places) !== 0 ? '-'.$result : $result;
    }

    /** Largest remainder allocation, input order breaks ties deterministically. */
    public static function allocate(array $values, int $places = 2): array
    {
        if ($values === []) {
            return [];
        }
        $sum = '0';
        $floorSum = '0';
        $out = [];
        $remainders = [];
        foreach (array_values($values) as $i => $value) {
            if (bccomp($value, '0', self::SCALE) < 0) {
                throw new \InvalidArgumentException('يتطلب التخصيص قيمًا غير سالبة.');
            }
            $sum = bcadd($sum, $value, self::SCALE);
            $out[$i] = bcadd($value, '0', $places);
            $floorSum = bcadd($floorSum, $out[$i], $places);
            $remainders[$i] = bcsub($value, $out[$i], self::SCALE);
        }
        $unit = $places === 0 ? '1' : '0.'.str_repeat('0', $places - 1).'1';
        $remaining = (int) bcdiv(bcsub(self::round($sum, $places), $floorSum, $places), $unit, 0);
        $indices = array_keys($remainders);
        usort($indices, fn ($a, $b) => bccomp($remainders[$b], $remainders[$a], self::SCALE) ?: $a <=> $b);
        foreach (array_slice($indices, 0, $remaining) as $index) {
            $out[$index] = bcadd($out[$index], $unit, $places);
        }

        return $out;
    }
}
