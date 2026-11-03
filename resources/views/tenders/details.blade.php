<form wire:submit="save" class="erp-document-form space-y-5">
    <x-erp.section-card title="بيانات المناقصة" icon="document-text">
        <div class="erp-form-grid">
            <flux:input field:class="erp-field-wide" label="عنوان المناقصة" wire:model="form.title" required />
            <flux:select label="الفئة" wire:model="form.category">@foreach(['supply'=>'توريد','project'=>'تركيب ومشروع','service'=>'صيانة وخدمات','mixed'=>'مختلط'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</flux:select>
            <flux:input label="الجهة المصدرة" wire:model="form.issuer" :disabled="$t!==null" required />
            <flux:input label="مرجع الجهة الفريد" wire:model="form.external_reference" :disabled="$t!==null" required />
            <flux:input type="date" label="تاريخ النشر" wire:model="form.published_on" required />
        </div>
        <flux:textarea field:class="mt-5" label="وصف المناقصة" wire:model="form.description" rows="3" />
    </x-erp.section-card>
    <x-erp.section-card title="المسؤولية والعميل" icon="users">
        <div class="erp-form-grid">
            <flux:select label="الفرع" wire:model.live="form.branch_id" :disabled="$t!==null" required><option value="">اختر الفرع</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
            <flux:select label="المسؤول الداخلي" wire:model="form.responsible_id" required><option value="">اختر المسؤول</option>@foreach($users as $u)<option value="{{ $u['id'] }}">{{ $u['name'] }}</option>@endforeach</flux:select>
            <flux:select label="العميل المرتبط (اختياري)" wire:model="form.customer_id" search-model="customerSearch" :disabled="$t!==null"><option value="">جهة محتملة دون عميل</option>@if($t?->customer_id)<option value="{{ $t->customer_id }}">{{ $t->customer_name }}</option>@else @foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->code }} · {{ $c->name }}</option>@endforeach @endif</flux:select>
        </div>
    </x-erp.section-card>
    <x-erp.section-card title="المواعيد ومتطلبات المشاركة" icon="calendar-days">
        <p class="erp-form-hint mb-4">أدخل المواعيد بتوقيت القاهرة. الموعد النهائي مطلوب، وباقي المواعيد حسب شروط الجهة.</p>
        <div class="erp-form-grid">@foreach(['final_at'=>'الموعد النهائي للتقديم','site_visit_at'=>'زيارة الموقع','clarification_at'=>'آخر موعد للاستفسارات','technical_at'=>'تقديم العرض الفني','financial_at'=>'تقديم العرض المالي','opening_at'=>'فتح المظاريف'] as $key=>$label)<flux:input type="datetime-local" :label="$label" wire:model="form.deadlines.{{ $key }}" :required="$key==='final_at'" />@endforeach</div>
        <flux:textarea field:class="mt-5" label="متطلبات المشاركة" wire:model="form.requirements" rows="3" />
    </x-erp.section-card>
    @if(!$t || $a->allows(auth()->id(),$branch,'manage'))<div class="erp-form-footer"><p>الحفظ يسجل البيانات. تقديم العطاء واعتماده من خطوات المناقصة.</p><flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="save">حفظ بيانات المناقصة</flux:button></div>@endif
</form>
@if($t && $a->allows(auth()->id(),$branch,'manage'))
<x-ui.panel><form wire:submit="advanceState" class="grid gap-3 md:grid-cols-3"><flux:select label="القرار أو المرحلة" wire:model="state">@foreach(['under_review'=>'بدء الدراسة','preparing_technical'=>'قرار المشاركة وإعداد الفني','no_bid'=>'عدم المشاركة','awaiting_result'=>'انتظار النتيجة','withdrawn'=>'سحب العطاء','cancelled'=>'إلغاء'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</flux:select><flux:input label="سبب القرار" wire:model="reason"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">تسجيل القرار</flux:button></form></x-ui.panel>
<x-ui.panel><form wire:submit="saveEvent" class="grid gap-3 md:grid-cols-2"><flux:select label="تحديث موثق" wire:model="eventKind"><option value="clarification">استفسار</option><option value="addendum">ملحق وإعادة إعداد العطاء</option><option value="deadline_extension">تمديد الموعد</option><option value="reopen">إعادة التقييم بتصريح النتيجة</option></flux:select><flux:select label="الدليل المرفوع" wire:model="eventDocument"><option value="">اختر المستند</option>@foreach($documents as $doc)<option value="{{ $doc->id }}">{{ $doc->title }}</option>@endforeach</flux:select><flux:textarea label="المرجع والسبب" wire:model="reason"/><p class="text-xs text-slate-500">للتمديد عدل المواعيد أعلاه ثم سجل التحديث. الملحق أو إعادة التقييم يبطل اعتماد النسخ الحالية مع حفظ التاريخ.</p><flux:button type="submit" wire:loading.attr="disabled">حفظ التحديث</flux:button></form></x-ui.panel>@endif
