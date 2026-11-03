<div data-erp-archetype="details" class="space-y-4">
    <x-page.heading title="تفاصيل حركة الخزينة" description="سجل حركة منفذة للقراءة فقط" />
    <x-ui.validation-summary />
    <x-ui.panel><dl class="grid gap-4 sm:grid-cols-2">
        @foreach(['id'=>'مرجع الحركة','reference'=>'المرجع','movement_date'=>'تاريخ الحركة','amount'=>'المبلغ EGP','branch_id'=>'الفرع','account_id'=>'حساب الخزينة','journal_id'=>'مرجع قيد الأستاذ'] as $field=>$label)
            <div><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-1 font-semibold"><bdi>{{ $movement->$field }}</bdi></dd></div>
        @endforeach
    </dl></x-ui.panel>
    @if($movement->original_movement_id)<flux:callout>تصحيح مرتبط بالحركة الأصلية <a href="{{ route('treasury.movements.show',$movement->original_movement_id) }}">#{{ $movement->original_movement_id }}</a>. الأصل محفوظ دون تعديل.</flux:callout>@endif
    @if($correction)<flux:callout>صُححت الحركة بسجل مستقل <a href="{{ route('treasury.movements.show',$correction->id) }}">#{{ $correction->id }}</a> بتاريخ {{ $correction->movement_date }}.</flux:callout>@endif
    @if($canCorrect)<x-ui.panel><flux:heading level="2">تصحيح مستقل من المصدر</flux:heading><flux:text class="mt-2">يعكس القيد والحركة والتخصيصات الفعلية بتاريخ التصحيح. التحويل يعكس طرفيه معًا. يرفض تصحيح مقدم مستخدم أو حركة لا يكفي رصيدها الفعلي.</flux:text><form wire:submit="correct" class="mt-4 space-y-4"><flux:input label="تاريخ التصحيح الفعلي" type="date" wire:model="correctionDate" required /><flux:textarea label="سبب التصحيح" wire:model="reason" required /><flux:button type="submit" wire:loading.attr="disabled" wire:confirm="تنفيذ عكس كامل من المصدر مع الاحتفاظ بالأصل؟">ترحيل التصحيح المرتبط</flux:button></form></x-ui.panel>@endif
    @if($movement->official_document_id)<flux:button :href="route('treasury.movements.pdf',$movement->id)">سند PDF</flux:button>@endif
    <flux:button :href="route($movement->kind==='customer_collection'?'treasury.collections':'treasury.payments')" wire:navigate>سجل الخزينة</flux:button>
</div>
