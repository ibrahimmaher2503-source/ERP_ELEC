@php($money=fn($v)=>\App\Modules\Sales\Application\DraftAmounts::display($v))
<div class="quote-amounts">
 <div><span>الإجمالي قبل الخصم</span><bdi>{{ $money($amounts['gross']??null) }}</bdi></div>
 <div><span>خصومات البنود</span><bdi>{{ $money($amounts['line_discounts']??null) }}</bdi></div>
 <div><span>خصم عرض السعر</span><bdi>{{ $money($amounts['quotation_discount']??null) }}</bdi></div>
 <div class="quote-net"><strong>الصافي قبل الضريبة (ج.م)</strong><bdi>{{ $money($amounts['net']??null) }}</bdi></div>
 <div><span>الضريبة</span><span class="text-slate-500">بانتظار عقد الضرائب</span></div>
 <div><strong>الإجمالي النهائي (ج.م)</strong><span class="text-slate-500">غير محسوب</span></div>
 <p class="mt-3 text-xs text-slate-500">حساب مسودة دقيق دون تقريب رسمي. الأسعار المطلوبة لا تُعد أسعارًا معتمدة، والضريبة لم تُحسب.</p>
</div>
