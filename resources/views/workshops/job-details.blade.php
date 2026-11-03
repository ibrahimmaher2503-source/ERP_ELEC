<x-ui.panel>
    <div class="workshop-section-heading"><div><h2>الفحص والتشخيص</h2><p>التشخيص وخطة الاختبار يسجلهما الفني المعين على الورشة.</p></div></div>
    <dl class="grid gap-5 md:grid-cols-2"><div><dt>العطل المبلغ عند الاستلام</dt><dd>{{ $intake->reported_fault }}</dd></div><div><dt>الحالة عند الوصول</dt><dd>{{ $intake->arrival_condition }}</dd></div><div><dt>التشخيص المسجل</dt><dd>{{ $w->diagnosis ?: 'لم يسجل تشخيص بعد.' }}</dd></div><div><dt>الأعمال المطلوبة</dt><dd>{{ $w->planned_work ?: 'لم تسجل خطة إصلاح بعد.' }}</dd></div></dl>
</x-ui.panel>
@if($access->allows(auth()->id(),$w->branch_id,'assign')&&!in_array($w->status,['verified','delivered','returned_unrepaired']))
<x-ui.panel><form wire:submit="assign" class="space-y-4">
    <div class="workshop-section-heading"><div><h2>إسناد الفني</h2><p>تظهر العضويات النشطة المؤهلة للتنفيذ في الورشة الحالية فقط. نقل المعدة لورشة أخرى له سجل عهدة مستقل.</p></div></div>
    <flux:select label="الفني المكلف" wire:model="technicianId"><option value="">اختر الفني</option>@foreach($technicians as $u)<option value="{{ $u['id'] }}">{{ $u['name'] }}</option>@endforeach</flux:select>
    @if(!$technicians)<p class="workshop-hint">لا يوجد فني نشط مؤهل في هذه الورشة. أضف عضوية الفني من إعداد الورش وراجع صلاحية التنفيذ ونطاق الفرع.</p><flux:button :href="route('workshops.settings')" wire:navigate>إعداد أعضاء الورشة</flux:button>@endif
    <flux:input label="سبب الإسناد" wire:model="reason"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ الإسناد</flux:button>
</form></x-ui.panel>
@endif
@if($w->status==='inspection'&&$w->technician_id===auth()->id()&&$access->allows(auth()->id(),$w->branch_id,'execute'))
<x-ui.panel><form wire:submit="diagnose" class="space-y-5">
    <h2 class="text-lg font-semibold">سجّل نتيجة الفحص</h2>
    <flux:input label="تصنيف العطل" wire:model="diagnosis.fault_classification"/>
    <div class="grid gap-4 md:grid-cols-2"><flux:textarea label="التشخيص" wire:model="diagnosis.diagnosis"/><flux:textarea label="خطة الإصلاح" wire:model="diagnosis.planned_work"/></div>
    <div class="grid gap-4 sm:grid-cols-2"><flux:input type="number" min="1" label="الدقائق المقدرة" wire:model="diagnosis.estimated_minutes"/><flux:input type="datetime-local" label="الإنجاز المتوقع بالقاهرة" wire:model="diagnosis.estimated_completion"/></div>
    <div class="border-t pt-5"><h3 class="mb-3 font-semibold">خطة الاختبارات</h3><p class="mb-4 text-sm text-slate-500">حدد اختبارًا إلزاميًا على الأقل للتحقق الفني. أدخل الاختبارات المناسبة للمعدة.</p><div class="space-y-4">@foreach($diagnosis['required_tests'] as $n=>$t)<div class="grid items-end gap-3 sm:grid-cols-[1fr_auto]" wire:key="repair-test-plan-{{ $n }}"><flux:input :label="'اسم الاختبار '.($n+1)" wire:model="diagnosis.required_tests.{{ $n }}.name"/><flux:checkbox label="اختبار إلزامي" wire:model="diagnosis.required_tests.{{ $n }}.required"/></div>@endforeach</div></div>
    <div class="flex flex-wrap gap-3"><flux:button type="button" wire:click="addTest" icon="plus">إضافة اختبار</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ التشخيص وخطة الفحص</flux:button></div>
</form></x-ui.panel>
@elseif($w->status==='inspection')
    <p class="workshop-hint">{{ $w->technician_id ? 'تسجيل التشخيص متاح للفني المعيّن فقط. الحساب الحالي يستطيع متابعة الحالة وفق صلاحياته.' : 'يلزم إسناد الفني أولًا حتى يتمكن من تسجيل التشخيص.' }}</p>
@endif
@include('workshops.custody-transfer')
