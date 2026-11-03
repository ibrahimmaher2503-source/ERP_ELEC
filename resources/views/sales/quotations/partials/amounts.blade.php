@php($money=fn($v)=>\App\Modules\Sales\Application\DraftAmounts::display($v))
<div class="quote-amounts">
 <div><span>الإجمالي قبل الخصم</span><bdi>{{ $money($amounts['gross']??null) }}</bdi></div>
 <div><span>خصومات البنود</span><bdi>{{ $money($amounts['line_discounts']??null) }}</bdi></div>
 <div><span>خصم عرض السعر</span><bdi>{{ $money($amounts['quotation_discount']??null) }}</bdi></div>
 <div class="quote-net"><strong>الصافي قبل الضريبة (ج.م)</strong><bdi>{{ $money($amounts['net']??null) }}</bdi></div>
 <div><span>الضريبة</span><bdi>{{ $money($amounts['tax']??null) }}</bdi></div>
 <div><strong>الإجمالي النهائي (ج.م)</strong><bdi>{{ $money($amounts['total']??null) }}</bdi></div>
 <p class="mt-3 text-xs text-slate-500">{{ ($amounts['official']??false) ? 'مبالغ ثابتة من الإصدار الرسمي وقواعد الضريبة المحفوظة.' : 'حساب مسودة دقيق دون تقريب رسمي. يتحقق الإصدار من السعر والخصم والضريبة.' }}</p>
</div>
