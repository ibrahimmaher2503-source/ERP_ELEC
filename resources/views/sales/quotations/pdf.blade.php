<div class="quotation-page space-y-4">
 @include('sales.quotations.partials.detail-header',['heading'=>'معاينة عرض السعر (PDF)'])
 <div class="quote-pdf-grid">
  <div class="quote-pdf-viewer"><div class="quote-pdf-toolbar"><span>الملف الرسمي لعرض السعر</span><span>غير صادر</span></div><div class="quote-pdf-empty"><flux:icon.document-text class="size-14" /><h2>لا يوجد ملف PDF رسمي</h2><p>الإصدار محظور حتى اكتمال مصدر الأسعار والضرائب والتقريب والشروط المعتمدة وهوية الشركة والتوقيع. لن يتم إنشاء ملف بتقديرات غير معتمدة.</p></div></div>
  <aside class="space-y-4"><x-ui.panel class="quote-section"><h2>معلومات عرض السعر</h2><dl class="quote-dl"><div><dt>المرجع</dt><dd>{{ $record->label() }}</dd></div><div><dt>العميل</dt><dd>{{ $record->customer_snapshot['name'] }}</dd></div><div><dt>العملة</dt><dd>EGP</dd></div><div><dt>الحالة</dt><dd><x-sales.quotation-status :status="$record->status" /></dd></div></dl></x-ui.panel><x-ui.panel class="quote-section"><h2>متطلبات الإصدار</h2><ul class="list-inside list-disc space-y-3 text-xs leading-6">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></x-ui.panel></aside>
 </div>
 @if($can('issue'))<flux:button wire:click="operation('issue')" icon="document-check">التحقق من متطلبات الإصدار</flux:button>@endif
</div>
