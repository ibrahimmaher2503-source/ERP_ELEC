<x-ui.panel>
    <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
        <aside class="space-y-4">
            <div class="party-address"><h2 class="party-section-title">تنزيل قالب Excel</h2><p class="my-4 text-sm leading-7">استخدم قالب {{ $entityLabel }} المعتمد لتعبئة البيانات بالحقول والصيغة الصحيحة.</p>@if($canTemplate)<flux:button class="w-full" wire:click="downloadTemplate" icon="arrow-down-tray">تنزيل القالب</flux:button>@else<p>تحتاج صلاحية تحميل القالب.</p>@endif<p class="mt-3 text-xs">{{ data_get($definition,'version') }}</p></div>
            <div class="party-address"><h3 class="font-bold">قواعد الاستيراد</h3><ul class="mt-3 list-inside list-disc space-y-3 text-xs leading-6"><li>الكود فريد وثابت.</li><li>الكيان القانوني: individual أو organization.</li><li>الحالة: active أو inactive أو archived.</li><li>تطابق التحديثات حسب الكود فقط.</li><li>لا تستورد بيانات مالية أو صلاحيات.</li><li>في التحديث: الفراغ يبقي القيمة، و#CLEAR يمسح الحقول الاختيارية.</li>@if($entity==='suppliers')<li>نوع المورد: manufacturer / distributor / service_provider / other.</li><li>استخدم كود تصنيف نشط من ورقة Lookups.</li>@endif</ul></div>
        </aside>
        <div>
            <ol class="party-address mb-5 flex flex-wrap justify-between gap-4 text-sm"><li>١. تنزيل القالب</li><li>٢. تعبئة البيانات</li><li>٣. رفع الملف</li><li>٤. مراجعة النتائج</li></ol>
            <form wire:submit="previewImport" class="space-y-5">
                <div class="party-upload flex min-h-52 flex-col justify-center gap-4 text-center"><flux:icon.cloud-arrow-up class="mx-auto size-12 text-blue-600" /><h2 class="text-lg font-bold">اختر ملف Excel المكتمل</h2>@if($templateDownloaded && $canImport)<flux:input type="file" wire:model="upload" label="اختيار ملف من جهازك" accept=".xlsx" required />@else<p class="text-sm">نزّل القالب الرسمي لفتح رفع الملف.</p>@endif<p class="text-xs opacity-65">XLSX · الحد الأقصى 10 ميجابايت · حتى 500 صف</p><span wire:loading wire:target="upload">جارٍ رفع الملف…</span></div>
                <flux:select wire:model.live="mode" label="طريقة الاستيراد"><option value="create">إنشاء سجلات جديدة فقط</option><option value="update">تحديث السجلات المطابقة فقط</option></flux:select>
                <flux:callout color="blue">المعاينة تعرض الصفوف والأخطاء دون حفظ العملاء. يتطلب التنفيذ تأكيدًا لاحقًا.</flux:callout>
                <flux:button type="submit" variant="primary" icon="eye" :disabled="!$templateDownloaded || !$upload || !$canImport" wire:loading.attr="disabled" wire:target="upload,previewImport">التحقق ومعاينة الملف</flux:button><flux:button :href="route($entity.'.index')" wire:navigate>إلغاء</flux:button>
            </form>
        </div>
    </div>
</x-ui.panel>
