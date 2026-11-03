<x-ui.panel>
    <div class="workshop-section-heading"><div><h2>قطع الغيار المطلوبة</h2><p>طلب القطعة لا يخصم المخزون. الحجز يسبق الصرف، وكل كمية مصروفة يجب استخدامها أو إرجاعها.</p></div><span class="workshop-eyebrow">تكامل المخزون</span></div>
    <x-ui.responsive-table label="متطلبات قطع الغيار">
        <table class="w-full text-start text-sm"><thead><tr><th>القطعة والمستودع</th><th>المطلوب</th><th>المصروف</th><th>المستخدم</th><th>المرتجع</th><th>بعهدة الفني</th><th>الإجراء</th></tr></thead><tbody>
        @forelse($requirements as $r)
            @php($totals=app(\App\Modules\WorkshopRepairs\Application\WorkshopParts::class)->totals($r->id))
            <tr wire:key="job-part-{{ $r->id }}">
                <td data-label="القطعة والمستودع"><strong>{{ $partLabels[$r->product_id] ?? 'الصنف #'.$r->product_id }}</strong><p class="text-xs text-slate-500">{{ collect($warehouses)->firstWhere('id',$r->warehouse_id)['name'] ?? 'مستودع #'.$r->warehouse_id }} · طلب #{{ $r->id }} · {{ $r->tracking==='serial'?'مسلسل':'كمي' }}</p></td>
                <td data-label="المطلوب">{{ $r->quantity }}</td><td data-label="المصروف">{{ $totals['issued'] }}</td><td data-label="المستخدم">{{ $totals['used'] }}</td><td data-label="المرتجع">{{ $totals['returned'] }}</td><td data-label="بعهدة الفني">{{ bcsub(bcsub($totals['issued'],$totals['used'],8),$totals['returned'],8) }}</td>
                <td data-label="الإجراء"><flux:button size="sm" wire:click="selectRequirement({{ $r->id }})" :variant="(int)$requirementId===$r->id?'primary':'outline'">إدارة القطعة</flux:button></td>
            </tr>
        @empty
            <tr><td colspan="7"><x-ui.empty-state title="لم تطلب قطع غيار" description="أضف القطع اللازمة للإصلاح بعد اعتماد العرض. الإصلاح الذي لا يحتاج قطعًا يستكمل من سجل العمل." /></td></tr>
        @endforelse
        </tbody></table>
    </x-ui.responsive-table>
</x-ui.panel>

@if(in_array($w->status,['approved','repair','waiting_parts'])&&$catalogAvailable&&$access->allows(auth()->id(),$w->branch_id,'parts_request'))
<x-ui.panel>
    <form wire:submit="requestPart" class="space-y-4">
        <div class="workshop-section-heading"><div><h2>طلب قطعة غيار</h2><p>اختر الصنف والمستودع والكمية المطلوبة لهذا الإصلاح.</p></div></div>
        <div class="grid gap-4 md:grid-cols-3">
            <flux:select label="قطعة الغيار" wire:model.live="part.product_id" search-model="productSearch"><option value="">اختر الصنف</option>@foreach($products as $p)@if($p['kind']!=='service')<option value="{{ $p['id'] }}">{{ $p['sku'] }} · {{ $p['name'] }}</option>@endif @endforeach</flux:select>
            <flux:select label="مستودع الصرف" wire:model.live="part.warehouse_id"><option value="">اختر المستودع</option>@foreach($warehouses as $wh)<option value="{{ $wh['id'] }}">{{ $wh['name'] }}</option>@endforeach</flux:select>
            <flux:input label="الكمية المطلوبة" inputmode="decimal" wire:model="part.quantity" />
        </div>
        @if($availability)<p class="workshop-hint">المخزون الفعلي {{ $availability['on_hand'] }} · المحجوز {{ $availability['reserved'] }} · المتاح {{ $availability['available'] }}</p>@endif
        @if(!$warehouses)<p class="workshop-hint">لا توجد مستودعات متاحة ضمن صلاحياتك لهذا الفرع. راجع نطاق المستودع قبل طلب القطعة.</p>@endif
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">إضافة طلب قطعة</flux:button>
    </form>
</x-ui.panel>
@endif

@if(in_array($w->status,['approved','repair','waiting_parts'])&&$requirements->isNotEmpty())
<x-ui.panel>
    <div class="workshop-section-heading"><div><h2>حركة قطعة الغيار</h2><p>اختر طلبًا من الجدول، ثم نفّذ العملية المطلوبة على مصادره فقط.</p></div></div>
    <div class="grid gap-4 sm:grid-cols-2">
        <flux:select label="طلب القطعة" wire:model.live="requirementId"><option value="">اختر الطلب</option>@foreach($requirements as $r)<option value="{{ $r->id }}">{{ $partLabels[$r->product_id] ?? 'صنف #'.$r->product_id }} · طلب #{{ $r->id }}</option>@endforeach</flux:select>
        <flux:select label="العملية المطلوبة" wire:model.live="partOperation"><option value="reserve">1. حجز الكمية</option><option value="issue">2. الصرف من الحجز</option><option value="use">3. تسجيل الاستخدام</option><option value="return">4. إرجاع غير المستخدم</option></flux:select>
    </div>
    @php($selectedPart=$requirements->firstWhere('id',(int)$requirementId))
    @if(!$selectedPart)
        <p class="workshop-hint mt-4">اختر القطعة لإظهار الحجوزات وحركات الصرف المرتبطة بها.</p>
    @else
        @php($partReservations=$reservations->where('requirement_id',$selectedPart->id))
        @php($partIssues=$issues->where('requirement_id',$selectedPart->id))
        <div class="mt-5 border-t pt-5">
        @if($partOperation==='reserve')
            @if($stockControls['reserve']&&$access->allows(auth()->id(),$w->branch_id,'parts_request'))
                <form wire:submit="reservePart" class="space-y-4"><p>الحجز يخصص الكمية لهذا الإصلاح دون خصم فعلي من المخزون.</p><flux:input label="الكمية المراد حجزها" inputmode="decimal" wire:model="quantity"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حجز الكمية</flux:button></form>
                @if($partReservations->isNotEmpty())<form wire:submit="releasePart" class="mt-5 space-y-3 border-t pt-5"><flux:select label="الحجز المطلوب فك باقيه" wire:model="reservationId"><option value="">اختر الحجز</option>@foreach($partReservations as $r)<option value="{{ $r->reservation_id }}">حجز #{{ $r->reservation_id }}</option>@endforeach</flux:select><flux:button type="submit" wire:confirm="فك الكمية غير المصروفة من هذا الحجز؟">فك بقية الحجز</flux:button></form>@endif
            @else<p class="workshop-hint">الحجز يتطلب صلاحية طلب القطع وصلاحية حجز المخزون في مستودع هذه القطعة.</p>@endif
        @elseif($partOperation==='issue')
            @if($stockControls['issue']&&$access->allows(auth()->id(),$w->branch_id,'parts_issue'))
                @if($partReservations->isEmpty())<p class="workshop-hint">لا يوجد حجز لهذا الطلب. احجز الكمية أولًا.</p>@else
                <form wire:submit="issuePart" class="space-y-4"><flux:select label="الحجز المصدر للصرف" wire:model="reservationId"><option value="">اختر الحجز</option>@foreach($partReservations as $r)<option value="{{ $r->reservation_id }}">حجز #{{ $r->reservation_id }}</option>@endforeach</flux:select><flux:input label="الكمية المراد صرفها" inputmode="decimal" wire:model="quantity"/><p class="workshop-hint">الصرف ينشئ حركة مخزون وتكلفة فعلية مرتبطة بهذا الإصلاح.</p><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:confirm="تأكيد صرف هذه الكمية فعليًا من المخزون؟">صرف فعلي من المخزون</flux:button></form>
                @endif
            @else<p class="workshop-hint">الصرف يتطلب صلاحية صرف القطع والمخزون والترحيل المحاسبي في نطاق المستودع.</p>@endif
        @elseif($partOperation==='use')
            @if($w->technician_id===auth()->id()&&$access->allows(auth()->id(),$w->branch_id,'execute'))
                @if($records->isEmpty())<p class="workshop-hint">سجّل العمل الفعلي من مرحلة تنفيذ الإصلاح أولًا، ثم اربط القطع المستخدمة به.</p>@else
                <form wire:submit="usePart" class="space-y-4"><flux:select label="سجل العمل الذي استخدمت فيه القطعة" wire:model="recordId"><option value="">اختر سجل العمل</option>@foreach($records as $r)<option value="{{ $r->id }}">{{ \Illuminate\Support\Str::limit($r->work_performed,80) }} · سجل #{{ $r->id }}</option>@endforeach</flux:select><flux:input label="الكمية المستخدمة فعليًا" inputmode="decimal" wire:model="quantity"/>@if($selectedPart->tracking==='serial')<flux:textarea label="أرقام القطع المستخدمة، رقم بكل سطر" wire:model="partSerials"/>@endif<flux:button type="submit" variant="primary" wire:loading.attr="disabled">تسجيل استخدام القطعة</flux:button></form>
                @endif
            @else<p class="workshop-hint">استخدام القطعة يسجّله الفني المعيّن النشط على أمر الإصلاح.</p>@endif
        @elseif($partOperation==='return')
            @if($stockControls['return']&&$access->allows(auth()->id(),$w->branch_id,'parts_return'))
                @if($partIssues->isEmpty())<p class="workshop-hint">لا يوجد صرف أصلي لهذه القطعة. الإرجاع متاح بعد الصرف فقط.</p>@else
                <form wire:submit="returnPart" class="space-y-4"><flux:select label="حركة الصرف الأصلية للإرجاع" wire:model="issueId"><option value="">اختر الصرف</option>@foreach($partIssues as $r)<option value="{{ $r->id }}">صرف #{{ $r->id }} · الكمية {{ $r->quantity }} · {{ implode('، ',json_decode($r->serials??'[]',true)) }}</option>@endforeach</flux:select><flux:input label="الكمية غير المستخدمة المراد إرجاعها" inputmode="decimal" wire:model="quantity"/><flux:textarea label="سبب إرجاع القطعة" wire:model="reason"/>@if($selectedPart->tracking==='serial')<flux:textarea label="أرقام القطع المرتجعة، رقم بكل سطر" wire:model="partSerials"/>@endif<p class="workshop-hint">تعود الكمية بتكلفة مصدرها الأصلي. القطع المسجل استخدامها لا تقبل الإرجاع.</p><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:confirm="تأكيد إرجاع القطع غير المستخدمة إلى المخزون؟">إرجاع غير المستخدم</flux:button></form>
                @endif
            @else<p class="workshop-hint">الإرجاع يتطلب صلاحية إرجاع القطع وتصحيح المخزون والترحيل في مستودع القطعة.</p>@endif
        @endif
        </div>
    @endif
</x-ui.panel>
@endif

@if($issues->isNotEmpty())
<details class="workshop-disclosure"><summary>سجل الصرف والأرقام المسلسلة <span>{{ $issues->count() }} حركة</span></summary><div class="space-y-3 p-5">@foreach($issues as $r)<p>صرف #{{ $r->id }} · حركة مخزون #{{ $r->movement_id }} · الكمية {{ $r->quantity }} @if($r->serials??null) · {{ implode('، ',json_decode($r->serials,true)) }} @endif @if($canCosts) · التكلفة {{ $r->amount }} EGP · قيد #{{ $r->journal_id }} @endif</p>@endforeach</div></details>
@endif

<details class="workshop-disclosure"><summary>مكونات قدمها العميل <span>عهدة منفصلة عن المخزون</span></summary><div class="space-y-4 p-5">
    @forelse($customerParts as $cp)<p class="border-b pb-3">{{ $cp->description }} · {{ $cp->quantity }} · {{ ['received'=>'مستلم','used'=>'مستخدم','returned'=>'معاد'][$cp->disposition] }} · {{ $cp->reference }}</p>@empty<p class="text-sm text-slate-500">لم تسجل مكونات مقدمة من العميل.</p>@endforelse
    @if($access->allows(auth()->id(),$w->branch_id,'custody')&&!in_array($w->status,['verified','delivered','returned_unrepaired']))
    <form wire:submit="saveCustomerPart" class="space-y-4"><div class="grid gap-4 sm:grid-cols-2"><flux:select label="حركة مكون العميل" wire:model="customerPart.disposition"><option value="received">استلام</option><option value="used">استخدام</option><option value="returned">إرجاع</option></flux:select><flux:select label="مكون العميل الأصلي" wire:model="customerPart.source_id"><option value="">استلام جديد</option>@foreach($customerParts->where('disposition','received') as $cp)<option value="{{ $cp->id }}">{{ $cp->description }} · #{{ $cp->id }}</option>@endforeach</flux:select>@foreach(['description'=>'وصف المكون','quantity'=>'كمية مكون العميل','condition'=>'حالة المكون','reference'=>'دليل حركة المكون'] as $field=>$label)<flux:input :label="$label" wire:model="customerPart.{{ $field }}"/>@endforeach</div><flux:button type="submit" wire:loading.attr="disabled">تثبيت حركة مكون العميل</flux:button></form>
    @endif
</div></details>
@include('workshops.part-alternatives')
