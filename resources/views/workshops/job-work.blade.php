<x-ui.panel>
    <div class="workshop-section-heading"><div><h2>تنفيذ الإصلاح</h2><p>بدء العمل وتوثيق الساعات والعمليات من حساب الفني المعيّن. تسجّل أسباب الانتظار والإيقاف والاستئناف في تاريخ الأمر.</p></div></div>
    @if($w->technician_id===auth()->id()&&$access->allows(auth()->id(),$w->branch_id,'execute')&&!in_array($w->status,['verified','delivered','returned_unrepaired']))
        @php($transitions=match($w->status){'approved'=>['start'=>'بدء الإصلاح'],'repair'=>['waiting_parts'=>'انتظار قطع','hold'=>'إيقاف مؤقت','testing'=>'إحالة للاختبار'],'waiting_parts'=>['resume'=>'استئناف العمل','hold'=>'إيقاف مؤقت'],'on_hold'=>['resume'=>'استئناف العمل'],'testing'=>['repair'=>'إعادة للإصلاح'],default=>[]})
        @if($transitions)<flux:textarea label="سبب انتقال حالة الإصلاح" wire:model="reason"/><div class="mt-4 flex flex-wrap gap-3">@foreach($transitions as $action=>$label)<flux:button :variant="in_array($action,['start','resume','testing'])?'primary':'outline'" wire:key="workshop-state-{{ $action }}" wire:click="state('{{ $action }}')" wire:loading.attr="disabled">{{ $label }}</flux:button>@endforeach</div>@else<p class="workshop-hint">يبدأ التنفيذ بعد اكتمال التشخيص واعتماد أساس الإصلاح.</p>@endif
    @elseif(!in_array($w->status,['verified','delivered','returned_unrepaired']))
        <p class="workshop-hint">بدء الإصلاح وتسجيل العمل متاحان للفني المعيّن فقط. الاعتماد أو صفة مدير النظام لا تحل محل إسناد الفني.</p>
    @endif
</x-ui.panel>
@if($w->status==='repair'&&$w->technician_id===auth()->id()&&$access->allows(auth()->id(),$w->branch_id,'labor_record'))
<x-ui.panel><form wire:submit="recordWork" class="space-y-5">
    <div class="workshop-section-heading"><div><h2>تسجيل عمل فعلي</h2><p>الأوقات بتوقيت القاهرة، ومن دون تداخل مع أعمال الورشة أو الصيانة الميدانية للفني نفسه.</p></div></div>
    <div class="grid gap-4 sm:grid-cols-2"><flux:input type="datetime-local" label="بداية العمل بالقاهرة" wire:model="record.started_at"/><flux:input type="datetime-local" label="نهاية العمل بالقاهرة" wire:model="record.ended_at"/></div>
    <flux:input label="نوع العمل" wire:model="record.work_type"/><flux:textarea label="العمل المنفذ" wire:model="record.work_performed"/><flux:textarea label="الملاحظات" wire:model="record.observations"/>
    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">تثبيت سجل العمل والوقت</flux:button>
</form></x-ui.panel>
@endif
<x-ui.panel>
    <div class="workshop-section-heading"><div><h2>سجل الأعمال المنفذة</h2><p>الساعات والقطع المستخدمة ترتبط بالعمل الفعلي، ولا تستنتج من التقدير.</p></div></div>
    @forelse($records as $r)<article class="space-y-2 border-b py-4 last:border-0"><h3 class="font-semibold">{{ $r->work_performed }}</h3><p class="text-sm text-slate-500"><bdi>{{ \Carbon\CarbonImmutable::parse($r->started_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</bdi> إلى <bdi>{{ \Carbon\CarbonImmutable::parse($r->ended_at)->setTimezone('Africa/Cairo')->format('H:i') }}</bdi> · سجل #{{ $r->id }}</p><p class="text-sm">{{ $r->observations }}</p></article>@empty<x-ui.empty-state title="لم يسجل عمل فعلي بعد" description="بعد بدء الإصلاح، يثبت الفني ما نفذه ووقت البداية والنهاية. ثم يسجل استخدام القطع في مرحلة قطع الغيار."/>@endforelse
</x-ui.panel>
