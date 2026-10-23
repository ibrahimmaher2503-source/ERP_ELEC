<div class="quotation-page space-y-4">
 @include('sales.quotations.partials.detail-header',['heading'=>'قبول العميل'])
 @include('sales.quotations.partials.identity')
 <flux:callout variant="warning">لا توجد نسخة صادرة يمكن ربط قبول العميل بها. حفظ مرفق داخلي لا يُعد تسجيلًا لقبول العميل.</flux:callout>
 <div class="grid gap-4 lg:grid-cols-2"><x-ui.panel class="quote-section"><h2>بيانات قبول العميل</h2><div class="grid gap-4"><flux:select label="الإصدار المقبول" disabled><option>لا توجد إصدارات صادرة</option></flux:select><flux:select label="نوع الإثبات" disabled><option>أمر شراء من العميل</option><option>بريد إلكتروني</option><option>قبول كتابي موقع</option></flux:select><flux:input type="date" label="تاريخ القبول" disabled /><flux:input label="مرجع قبول العميل" disabled /><flux:textarea label="ملاحظات" disabled rows="3" /></div></x-ui.panel><x-ui.panel class="quote-section"><h2>إثبات القبول</h2><x-ui.empty-state title="لم يسجل قبول" description="يرتبط الإثبات بإصدار رسمي محدد. القبول الشفهي يحتاج استثناءً موثقًا ومصرحًا به، ولا ينشئ القبول أمر بيع تلقائيًا." icon="document-check" /></x-ui.panel></div>
 @if($can('accept'))<flux:button wire:click="operation('accept')">التحقق من متطلبات القبول</flux:button>@endif
</div>
