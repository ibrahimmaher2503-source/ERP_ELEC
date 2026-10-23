@php($money = fn ($value) => \App\Modules\Sales\Application\DraftAmounts::display(is_string($value) ? $value : null))
<dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
    <div><dt class="text-slate-500">الإجمالي قبل الخصم</dt><dd class="mt-1 font-semibold tabular-nums"><bdi>{{ $money($amounts['gross'] ?? null) }} ج.م</bdi></dd></div>
    <div><dt class="text-slate-500">خصومات البنود</dt><dd class="mt-1 font-semibold tabular-nums"><bdi>{{ $money($amounts['line_discounts'] ?? null) }} ج.م</bdi></dd></div>
    <div><dt class="text-slate-500">خصم أمر البيع</dt><dd class="mt-1 font-semibold tabular-nums"><bdi>{{ $money($amounts['quotation_discount'] ?? null) }} ج.م</bdi></dd></div>
    <div class="border-t border-slate-200 pt-3"><dt class="font-medium text-slate-700">الصافي قبل الضرائب</dt><dd class="mt-1 text-lg font-bold tabular-nums"><bdi>{{ $money($amounts['net'] ?? null) }} ج.م</bdi></dd></div>
</dl>
<p class="mt-4 text-xs leading-5 text-slate-500">حساب مسودة دقيق بلا تقريب. الضرائب والإجمالي الرسمي غير محسوبين هنا.</p>
