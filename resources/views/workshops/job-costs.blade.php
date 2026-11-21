@if($profitability)
<x-ui.panel class="space-y-4">
    <div class="workshop-section-heading">
        <div>
            <h2>بطاقة تكلفة وربح أمر الإصلاح</h2>
            <p>الربح يُحسب من صافي البيع بدون الضريبة مطروحًا منه التكاليف الفعلية. التحصيل والمتبقي تدفق نقدي منفصل عن الربح.</p>
        </div>
    </div>
    @if(! $profitability['cost_complete'])
        <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">تنبيه: توجد سجلات عمل فعلية بلا معدل ساعة معتمد، لذا تكلفة العمل المعروضة جزئية (تُحدّث تلقائيًا بعد اعتماد المعدل).</p>
    @endif
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-lg border border-slate-200 bg-slate-50/70 p-4 space-y-2">
            <h3 class="text-sm font-bold text-slate-800">١. التكاليف الفعلية</h3>
            <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between gap-2"><dt class="text-slate-500">قطع الغيار بعد المرتجع</dt><dd class="font-semibold tabular-nums">{{ $profitability['parts_cost'] }} ج.م</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">تكلفة العمل الفعلية</dt><dd class="font-semibold tabular-nums">{{ $profitability['labor_cost'] !== null ? $profitability['labor_cost'].' ج.م' : 'جزئي ('.$profitability['supported_labor_cost'].' ج.م)' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">مصروفات / شغل خارجي</dt><dd class="font-semibold tabular-nums">{{ $profitability['other_cost'] }} ج.م</dd></div>
                <div class="flex justify-between gap-2 border-t border-slate-200 pt-2"><dt class="font-bold text-slate-800">إجمالي التكلفة المدعومة</dt><dd class="font-bold text-slate-900 tabular-nums">{{ $profitability['total_cost'] }} ج.م</dd></div>
            </dl>
        </div>
        <div class="rounded-lg border border-blue-200 bg-blue-50/50 p-4 space-y-2">
            <h3 class="text-sm font-bold text-blue-900">٢. البيع والربح (بدون الضريبة)</h3>
            <p class="text-xs text-slate-600">{{ $profitability['selling_source'] }}</p>
            <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between gap-2"><dt class="text-slate-600">صافي البيع بدون ضريبة</dt><dd class="font-semibold tabular-nums">{{ $profitability['selling_net'] !== null ? $profitability['selling_net'].' ج.م' : 'غير متاح' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">الضريبة (منفصلة عن الربح)</dt><dd class="tabular-nums">{{ $profitability['tax_amount'] !== null ? $profitability['tax_amount'].' ج.م' : '—' }}</dd></div>
                @php($profitPositive = $profitability['profit'] !== null && bccomp($profitability['profit'], '0', 2) >= 0)
                <div class="flex justify-between gap-2 border-t border-blue-200 pt-2">
                    <dt class="font-bold text-blue-950">الربح الصافي{{ $profitability['profit'] !== null && ! $profitability['profit_complete'] ? ' (جزئي)' : '' }}</dt>
                    <dd class="font-bold tabular-nums {{ $profitability['profit'] === null ? 'text-slate-500' : ($profitPositive ? 'text-emerald-700' : 'text-red-700') }}">
                        {{ $profitability['profit'] !== null ? $profitability['profit'].' ج.م' : 'غير متاح' }}
                    </dd>
                </div>
            </dl>
        </div>
        <div class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-4 space-y-2">
            <h3 class="text-sm font-bold text-emerald-900">٣. التحصيل والعربون والمسترد (منفصل عن الربح)</h3>
            @if($profitability['cash_available'])
                <dl class="space-y-1.5 text-sm">
                    @if($profitability['invoice_total'] !== null)
                        <div class="flex justify-between gap-2"><dt class="text-slate-600">إجمالي الفاتورة (شامل الضريبة)</dt><dd class="font-semibold tabular-nums">{{ $profitability['invoice_total'] }} ج.م</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-emerald-800">المقبوض على الفاتورة</dt><dd class="font-bold text-emerald-700 tabular-nums">{{ $profitability['collected'] }} ج.م</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-slate-600">المتبقي للتحصيل</dt><dd class="font-bold text-amber-800 tabular-nums">{{ $profitability['remaining'] }} ج.م</dd></div>
                    @endif
                    <div class="flex justify-between gap-2 border-t border-emerald-200/70 pt-1.5"><dt class="text-slate-600">العربون المحصل للأمر</dt><dd class="font-semibold tabular-nums">{{ $profitability['advances_collected'] }} ج.م</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-slate-600">العربون المطبّق / المسترد</dt><dd class="tabular-nums">{{ $profitability['advances_applied'] }} / {{ $profitability['advances_refunded'] }} ج.م</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-slate-600">العربون المتبقي</dt><dd class="font-semibold tabular-nums">{{ $profitability['advances_remaining'] }} ج.م</dd></div>
                    <div class="flex justify-between gap-2 border-t border-emerald-200 pt-2"><dt class="font-bold text-slate-800">صافي النقدية المقبوضة</dt><dd class="font-bold text-emerald-800 tabular-nums">{{ $profitability['net_cash_received'] }} ج.م</dd></div>
                </dl>
            @else
                <p class="text-xs text-slate-600">لا توجد بيانات مالية متاحة للعرض بعد (أو تنقصك صلاحية عرض الذمم المدينة M12.ar_view). التحصيل لا يختلط بحساب الربح.</p>
            @endif
        </div>
    </div>
</x-ui.panel>
@endif
<div class="grid gap-3 sm:grid-cols-3">@foreach(['parts'=>'تكلفة قطع فعلية','labor'=>'تكلفة العمل الفعلية','minutes'=>'دقائق العمل','other'=>'مصروفات معترف بها','supported_total'=>'إجمالي التكلفة المدعومة'] as $key=>$label)<x-ui.panel><p class="text-sm text-slate-500">{{ $label }}</p><strong class="text-xl">{{ $costs[$key]??'غير متاحة: لا يوجد معدل معتمد' }}</strong></x-ui.panel>@endforeach</div>
<x-ui.panel>@foreach($laborEntries as $l)<p class="border-b py-2">سجل #{{ $l->id }} · {{ $l->duration_minutes }} دقيقة · معدل #{{ $l->rate_id??'غير متاح' }} · {{ $l->cost??'التكلفة النقدية غير متاحة' }}</p>@endforeach</x-ui.panel>
@if($access->allows(auth()->id(),$w->branch_id,'rate_approve'))<x-ui.panel><div class="grid gap-3 sm:grid-cols-2"><flux:input label="مرجع سجل العمل للاعتراف" wire:model="laborEntryId"/><flux:input label="مرجع قيد مصروف يدوي مرحل" wire:model="expenseJournal"/><flux:input label="مرجع تخصيص المصروف" wire:model="reason"/></div><div class="mt-3 flex gap-2"><flux:button wire:click="recognizeLabor">الاعتراف بتكلفة العمل في M11</flux:button><flux:button wire:click="linkExpense">ربط المصروف دون قيد جديد</flux:button></div></x-ui.panel>@endif
@if($access->allows(auth()->id(),$w->branch_id,'labor_record'))<x-ui.panel><form wire:submit="proposeRate" class="space-y-3"><flux:heading>اقتراح معدل تكلفة موثق</flux:heading><div class="grid gap-3 sm:grid-cols-3"><flux:select label="الفني صاحب المعدل" wire:model="rate.technician_id"><option value="">اختر الفني</option>@foreach($users as $u)<option value="{{ $u['id'] }}">{{ $u['name'] }}</option>@endforeach</flux:select><flux:input label="تكلفة الساعة" wire:model="rate.hourly_cost"/><flux:input type="date" label="سريان المعدل من" wire:model="rate.effective_from"/><flux:input type="date" label="سريان المعدل إلى" wire:model="rate.effective_to"/><flux:input label="مرجع اعتماد معدل التكلفة" wire:model="rate.reference"/></div><flux:button type="submit">إرسال اقتراح المعدل</flux:button></form></x-ui.panel>@endif
@if($access->allows(auth()->id(),$w->branch_id,'rate_approve'))<x-ui.panel><form wire:submit="approveRate" class="space-y-3"><flux:select label="اقتراح المعدل للاعتماد" wire:model="rateId"><option value="">اختر الاقتراح</option>@foreach($rates->whereNull('approved_by') as $r)<option value="{{ $r->id }}">#{{ $r->id }} · {{ $r->reference }} · {{ $r->hourly_cost }}{{ ($r->proposed_by ?? null) === auth()->id() && $canSelfApprove ? ' (اعتماد ذاتي موثّق)' : '' }}</option>@endforeach</flux:select><flux:button type="submit">اعتماد المعدل</flux:button></form></x-ui.panel>@endif
