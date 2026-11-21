<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TaxCalculator
{
    public const DOCUMENT_TREATMENTS = ['rule', 'vat_14', 'exempt_0'];

    public static function treatmentLabel(?string $treatment): string
    {
        return match ($treatment) {
            'vat_14' => 'خاضع لضريبة القيمة المضافة 14%',
            'exempt_0' => 'بدون ضريبة (0%)',
            default => 'وفق القاعدة الضريبية للشركة',
        };
    }

    public function calculate(int $actor, string $transaction, string $date, string $mode, array $lines, string $documentDiscount = '0', ?array $documentTax = null): array
    {
        return $this->compute($actor, $transaction, $date, $mode, $lines, $documentDiscount, false, $documentTax);
    }

    public function tender(int $actor, string $date, string $mode, array $lines, string $documentDiscount = '0'): array
    {
        return $this->compute($actor, 'sale', $date, $mode, $lines, $documentDiscount, true);
    }

    private function compute(int $actor, string $transaction, string $date, string $mode, array $lines, string $documentDiscount, bool $external, ?array $documentTax = null): array
    {
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.tax_calculate'));
        Validator::make(compact('transaction', 'date', 'mode', 'lines'), [
            'transaction' => 'required|in:sale,purchase', 'date' => 'required|date_format:Y-m-d',
            'mode' => 'required|in:inclusive,exclusive', 'lines' => 'required|array|min:1|max:500',
            'lines.*.product_id' => $external ? 'present|nullable|integer|min:1' : 'required|integer|min:1',
        ])->validate();
        $choice = (string) ($documentTax['treatment'] ?? 'rule');
        $exemptReason = isset($documentTax['reason']) ? trim((string) $documentTax['reason']) : null;
        if (! in_array($choice, self::DOCUMENT_TREATMENTS, true)) {
            $this->fail('نوع المعاملة الضريبية للمستند غير صالح.');
        }
        if ($choice !== 'rule' && ($external || $transaction !== 'sale')) {
            $this->fail('اختيار المعاملة الضريبية على مستوى المستند مخصص لمستندات البيع فقط.');
        }
        if ($choice === 'exempt_0' && ($exemptReason === null || mb_strlen($exemptReason) < 5 || mb_strlen($exemptReason) > 2000)) {
            $this->fail('يجب كتابة سبب واضح (5 أحرف على الأقل) عند اختيار "بدون ضريبة".');
        }
        Decimal::value($documentDiscount, Decimal::SCALE);
        $out = [];
        $net = '0';
        $lastEligible = null;
        foreach (array_values($lines) as $line) {
            $product = $line['product_id'] === null ? null : app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $line['product_id']))->product;
            if ($product !== null && ! $product['active']) {
                $this->fail('الصنف غير نشط لحساب ضريبة معاملة جديدة.');
            }
            // Reject even apparently harmless client-supplied overrides.
            if (array_intersect(['rate', 'tax_code', 'tax_amount', 'tax_rule_id'], array_keys($line))) {
                $this->fail('لا يُسمح بتجاوز كود أو نسبة الضريبة في المستند.');
            }
            $qty = Decimal::value($line['quantity'] ?? null, 8);
            $price = Decimal::value($line['unit_price'] ?? null, 8);
            $discount = Decimal::value($line['discount'] ?? '0', Decimal::SCALE);
            if (bccomp($qty, '0', 8) <= 0) {
                $this->fail('كمية البند يجب أن تكون موجبة.');
            }
            $gross = bcmul($qty, $price, Decimal::SCALE);
            if (bccomp($discount, $gross, Decimal::SCALE) > 0) {
                $this->fail('الخصم يتجاوز قيمة البند.');
            }
            $eligible = bcsub($gross, $discount, Decimal::SCALE);
            $net = bcadd($net, $eligible, Decimal::SCALE);
            $meta = $this->resolveLineTax($transaction, $date, $line['product_id'], $choice, $exemptReason);
            $out[] = ['product_id' => $line['product_id'] === null ? null : (int) $line['product_id'], 'quantity' => $qty, 'unit_price' => $price,
                'gross_raw' => $gross, 'line_discount_raw' => $discount, 'eligible_raw' => $eligible] + $meta;
            if (bccomp($eligible, '0', Decimal::SCALE) > 0) {
                $lastEligible = count($out) - 1;
            }
        }
        if (bccomp($documentDiscount, $net, Decimal::SCALE) > 0) {
            $this->fail('خصم المستند يتجاوز القيمة المتاحة.');
        }
        $allocatedDiscount = '0';
        foreach ($out as $i => &$line) {
            // The last eligible line receives the residual; zero-value lines never do.
            $cut = $i === $lastEligible ? bcsub($documentDiscount, $allocatedDiscount, Decimal::SCALE)
                : (bccomp($net, '0', Decimal::SCALE) === 0 ? '0' : bcdiv(bcmul($documentDiscount, $line['eligible_raw'], Decimal::SCALE), $net, Decimal::SCALE));
            $allocatedDiscount = bcadd($allocatedDiscount, $cut, Decimal::SCALE);
            $base = bcsub($line['eligible_raw'], $cut, Decimal::SCALE);
            $taxable = $mode === 'inclusive' ? bcdiv(bcmul($base, '100', Decimal::SCALE), bcadd('100', $line['rate'], Decimal::SCALE), Decimal::SCALE) : $base;
            $tax = $mode === 'inclusive' ? bcsub($base, $taxable, Decimal::SCALE) : bcdiv(bcmul($taxable, $line['rate'], Decimal::SCALE), '100', Decimal::SCALE);
            $line += ['document_discount_raw' => $cut, 'taxable_raw' => $taxable, 'tax_raw' => $tax];
            $line['tax_document'] = Decimal::round($tax, $line['tax_scale']);
            $line['net_document'] = Decimal::round($taxable, $line['tax_scale']);
        }
        unset($line);
        $nets = Decimal::allocate(array_column($out, 'taxable_raw'));
        // Allocate per code/rate so document and tax summaries reconcile exactly.
        $groups = [];
        foreach ($out as $i => $line) {
            $groupKey = json_encode([$line['tax_code'], $line['rate'], $line['tax_scale'], $line['treatment'], $line['purchase_recoverable']], JSON_THROW_ON_ERROR);
            $groups[$groupKey][$i] = $line['tax_document'];
        }
        $taxes = [];
        foreach ($groups as $values) {
            foreach (array_combine(array_keys($values), Decimal::allocate(array_values($values))) as $i => $tax) {
                $taxes[$i] = $tax;
            }
        }
        if ($mode === 'inclusive') {
            $inclusiveTotals = Decimal::allocate(array_map(fn ($line) => bcadd($line['taxable_raw'], $line['tax_raw'], Decimal::SCALE), $out));
            foreach ($inclusiveTotals as $i => $total) {
                $nets[$i] = bcsub($total, $taxes[$i], 2);
                if (bccomp($nets[$i], '0', 2) < 0) {
                    $this->fail('القيم الصغيرة لا تسمح بمصالحة السعر الشامل مع دقة الضريبة المختارة. يلزم مراجعة قيم البنود أو قاعدة التقريب الرسمية.');
                }
            }
        }
        $totalNet = '0.00';
        $totalTax = '0.00';
        foreach ($out as $i => &$line) {
            $line['net'] = $nets[$i];
            $line['tax'] = $taxes[$i];
            $line['total'] = bcadd($line['net'], $line['tax'], 2);
            $line['net_posting_rounding'] = bcsub($line['net'], $line['net_document'], 5);
            $line['tax_posting_rounding'] = bcsub($line['tax'], $line['tax_document'], 5);
            $totalNet = bcadd($totalNet, $line['net'], 2);
            $totalTax = bcadd($totalTax, $line['tax'], 2);
        }
        unset($line);
        if (bccomp(bcadd($totalNet, $totalTax, 2), '999999999999999999.99', 2) > 0) {
            $this->fail('قيمة المستند تتجاوز الدقة المالية المدعومة.');
        }
        $taxTotals = [];
        foreach ($groups as $key => $values) {
            [$code, $rate, $scale, $treatment, $recoverable] = json_decode($key, true, 512, JSON_THROW_ON_ERROR);
            $documentTax = '0.00000';
            $postedTax = '0.00';
            foreach ($values as $i => $value) {
                $documentTax = bcadd($documentTax, $value, 5);
                $postedTax = bcadd($postedTax, $taxes[$i], 2);
            }
            $taxTotals[] = ['code' => $code, 'rate' => $rate, 'tax_scale' => $scale, 'treatment' => $treatment,
                'purchase_recoverable' => $recoverable, 'document_amount' => $documentTax, 'posted_amount' => $postedTax];
        }

        return ['currency' => 'EGP', 'mode' => $mode, 'date' => $date, 'transaction_type' => $transaction,
            'document_tax_choice' => $choice, 'tax_exempt_reason' => $choice === 'exempt_0' ? $exemptReason : null,
            'rounding' => 'half_away_from_zero_largest_remainder', 'internal_scale' => Decimal::SCALE,
            'configuration_version' => DB::table('accounting_activation')->where('id', 1)->value('version'),
            'production_validated' => (bool) DB::table('accounting_activation')->where('id', 1)->value('tax_validated'),
            'lines' => $out, 'tax_totals' => $taxTotals, 'net' => $totalNet, 'tax' => $totalTax, 'total' => bcadd($totalNet, $totalTax, 2)];
    }

    private function matchingRules(string $transaction, string $date, ?int $productId)
    {
        $rules = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
            ->whereRaw('coalesce(e.withdrawn,false)=false')->where('r.transaction_type', $transaction)
            ->where(fn ($q) => $q->where('r.product_id', $productId)->orWhereNull('r.product_id'))
            ->where('r.starts_on', '<=', $date)->whereRaw('(coalesce(e.ends_on,r.ends_on) IS NULL OR coalesce(e.ends_on,r.ends_on)>=?::date)', [$date])
            ->orderByRaw('r.product_id IS NULL')->orderBy('r.id')->get(['r.*']);
        $specific = $rules->whereNotNull('product_id');

        return $specific->isNotEmpty() ? $specific : $rules;
    }

    private function resolveLineTax(string $transaction, string $date, ?int $productId, string $choice, ?string $exemptReason): array
    {
        if ($choice === 'exempt_0') {
            return [
                'tax_rule_id' => null, 'tax_code' => 'DOC-NO-TAX', 'rate' => '0.00000',
                'treatment' => 'exempt', 'tax_scale' => 5, 'purchase_recoverable' => false,
                'legal_reference' => 'خيار ضريبي على مستوى المستند: بدون ضريبة (0%) — السبب: '.$exemptReason,
                'document_tax_choice' => 'exempt_0', 'tax_exempt_reason' => $exemptReason,
            ];
        }
        $applicable = $this->matchingRules($transaction, $date, $productId);
        if ($choice === 'vat_14') {
            $rule = $applicable->count() === 1 ? $applicable->first() : null;
            $matchesVat14 = $rule !== null && $rule->treatment === 'percentage' && bccomp((string) $rule->rate, '14', 5) === 0;

            return [
                'tax_rule_id' => $matchesVat14 ? $rule->id : null,
                'tax_code' => $matchesVat14 ? $rule->code : 'DOC-VAT-14',
                'rate' => '14.00000',
                'treatment' => 'percentage',
                'tax_scale' => $matchesVat14 ? $rule->tax_scale : 5,
                'purchase_recoverable' => false,
                'legal_reference' => $matchesVat14 ? $rule->legal_reference : 'خيار ضريبي على مستوى المستند: خاضع لضريبة القيمة المضافة 14%',
                'document_tax_choice' => 'vat_14',
                'tax_exempt_reason' => null,
            ];
        }
        if ($applicable->count() !== 1) {
            $this->fail('يلزم تحديد قاعدة ضريبية واحدة سارية لكل صنف ونوع معاملة. لا توجد نسبة ضريبة افتراضية.');
        }
        $rule = $applicable->first();

        return [
            'tax_rule_id' => $rule->id, 'tax_code' => $rule->code, 'rate' => $rule->rate,
            'treatment' => $rule->treatment, 'tax_scale' => $rule->tax_scale,
            'purchase_recoverable' => $rule->purchase_recoverable, 'legal_reference' => $rule->legal_reference,
            'document_tax_choice' => 'rule', 'tax_exempt_reason' => null,
        ];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['tax' => $message]);
    }
}
