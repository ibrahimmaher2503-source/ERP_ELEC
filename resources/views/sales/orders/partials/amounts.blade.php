@php
    use App\Modules\Sales\Presentation\SalesOrderDisplay as D;
    $isOfficial = (bool) ($amounts['official'] ?? false);
@endphp
<dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
    <div>
        <dt class="text-slate-500">الإجمالي قبل الخصم</dt>
        <dd class="mt-1 font-semibold tabular-nums"><bdi>{{ D::money($amounts['gross'] ?? null) }} ج.م</bdi></dd>
    </div>
    <div>
        <dt class="text-slate-500">خصومات البنود</dt>
        <dd class="mt-1 font-semibold tabular-nums"><bdi>{{ D::money($amounts['line_discounts'] ?? null) }} ج.م</bdi></dd>
    </div>
    <div>
        <dt class="text-slate-500">خصم أمر البيع</dt>
        <dd class="mt-1 font-semibold tabular-nums"><bdi>{{ D::money($amounts['quotation_discount'] ?? null) }} ج.م</bdi></dd>
    </div>
    <div>
        <dt class="text-slate-500">الصافي قبل الضرائب</dt>
        <dd class="mt-1 font-semibold tabular-nums"><bdi>{{ D::money($amounts['net'] ?? null) }} ج.م</bdi></dd>
    </div>
    @if($isOfficial)
        <div>
            <dt class="text-slate-500">الضريبة المعتمدة</dt>
            <dd class="mt-1 font-semibold tabular-nums"><bdi>{{ D::money($amounts['tax'] ?? null) }} ج.م</bdi></dd>
        </div>
        <div class="col-span-2 rounded-lg bg-emerald-50/70 px-3 py-2.5 ring-1 ring-emerald-200">
            <dt class="text-xs font-semibold text-emerald-900">الإجمالي الرسمي شامل الضريبة</dt>
            <dd class="mt-1 text-lg font-bold text-emerald-900 tabular-nums"><bdi>{{ D::money($amounts['total'] ?? null) }} ج.م</bdi></dd>
        </div>
    @elseif(isset($amounts['preview_tax']))
        <div>
            <dt class="text-slate-500">الضريبة المقدرة</dt>
            <dd class="mt-1 font-semibold tabular-nums"><bdi>{{ D::money($amounts['preview_tax'] ?? null) }} ج.م</bdi></dd>
        </div>
        <div class="col-span-2 rounded-lg bg-blue-50/70 px-3 py-2.5 ring-1 ring-blue-200">
            <dt class="text-xs font-semibold text-blue-900">الإجمالي المقدر قبل الاعتماد</dt>
            <dd class="mt-1 text-lg font-bold text-blue-900 tabular-nums"><bdi>{{ D::money($amounts['preview_total'] ?? null) }} ج.م</bdi></dd>
        </div>
    @endif
</dl>
@if($isOfficial)
    <p class="mt-4 text-xs leading-5 text-emerald-800">مبالغ رسمية معتمدة وثابتة حسب القاعدة الضريبية المحفوظة عند الاعتماد.</p>
@else
    <p class="mt-4 text-xs leading-5 text-slate-500">حساب مسودة دقيق بلا تقريب. تُثبَّت الضريبة والإجمالي الرسمي عند الاعتماد.</p>
@endif
