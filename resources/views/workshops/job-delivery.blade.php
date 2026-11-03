@if($tab==='delivery')
    <x-ui.panel>
        <div class="workshop-section-heading"><div><h2>تسليم المعدة وإقفال العهدة</h2><p>يسجل التسليم الحالة والملحقات والمستلم، ويصدر محضرًا محفوظًا مع أمر الإصلاح.</p></div></div>
        @forelse($deliveries as $handover)
            <dl class="grid gap-4 sm:grid-cols-3"><div><dt>المستلم</dt><dd>{{ $handover->recipient }}</dd></div><div><dt>الحالة عند التسليم</dt><dd>{{ $handover->final_condition }}</dd></div><div><dt>وقت التسليم</dt><dd>{{ \Carbon\CarbonImmutable::parse($handover->delivered_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</dd></div></dl>
            @if($access->allows(auth()->id(),$w->branch_id,'document_view'))<flux:button class="mt-4" icon="arrow-down-tray" :href="route('workshops.official',[$w->id,'delivery',$handover->id])">محضر التسليم PDF</flux:button>@endif
        @empty
            @if(!in_array($w->status,['verified','rejected']))<p class="workshop-hint">التسليم متاح بعد التحقق الفني المستقل، أو في مسار الإعادة دون إصلاح. الحالة الحالية: @include('workshops.status',['status'=>$w->status])</p>@endif
            @if($w->status==='rejected')<p class="workshop-hint">سجّل الإعادة دون إصلاح مع حالة المعدة وسبب الإعادة، وراجع القرار الفني وقرار العميل في سجل الأمر.</p><flux:button class="mt-3" :href="route('workshops.jobs.show',['job'=>$w->id,'tab'=>'testing'])" wire:navigate>مراجعة القرار الفني</flux:button>@endif
        @endforelse
    </x-ui.panel>
    @if(in_array($w->status,['verified','rejected'])&&$access->allows(auth()->id(),$w->branch_id,'deliver'))
        @if($w->status==='verified'&&in_array($w->billing_mode,['billable','partial'])&&!$invoice)
            <div class="workshop-next"><div><h2>أحل الإصلاح للفوترة قبل التسليم</h2><p>يلزم إنشاء مسودة الفاتورة من العرض المقبول. التحصيل ليس شرطًا لتسليم المعدة.</p></div><flux:button variant="primary" :href="route('workshops.jobs.show',['job'=>$w->id,'tab'=>'billing'])" wire:navigate>إحالة الفوترة</flux:button></div>
        @else
            <x-ui.panel><form wire:submit="deliver" class="space-y-5">
                <h2 class="text-lg font-semibold">بيانات التسليم الفعلي</h2>
                <div class="grid gap-4 sm:grid-cols-2"><flux:input label="اسم المستلم" wire:model="delivery.recipient"/><flux:input type="datetime-local" label="وقت التسليم بالقاهرة" wire:model="delivery.delivered_at"/></div>
                <flux:textarea label="الملحقات المسلمة" wire:model="delivery.accessories"/><flux:textarea label="حالة المعدة عند التسليم" wire:model="delivery.final_condition"/><flux:textarea label="إقرار المستلم ودليل التسليم" wire:model="delivery.acknowledgment"/>
                @if($w->status==='rejected')<flux:checkbox label="إرجاع دون إصلاح" wire:model="delivery.unrepaired"/>@endif
                <flux:button type="submit" variant="primary" wire:key="workshop-deliver" wire:loading.attr="disabled" wire:confirm="تأكيد التسليم النهائي وإقفال عهدة المعدة؟">تأكيد التسليم وإصدار المحضر</flux:button>
            </form></x-ui.panel>
        @endif
    @elseif(!$access->allows(auth()->id(),$w->branch_id,'deliver')&&$deliveries->isEmpty())
        <p class="workshop-hint">تأكيد التسليم يتطلب مستخدمًا مفوضًا بتسليم عهدة الورشة في هذا الفرع.</p>
    @endif
@else
    <x-ui.panel>
        <div class="workshop-section-heading"><div><h2>فاتورة الإصلاح والتحصيل</h2><p>الفاتورة من عرض العميل المقبول. الإصدار والترحيل والتحصيل تنفذها الوحدات المالية بصلاحياتها.</p></div></div>
        @if(!$canBillingHandoff)
            <p class="workshop-hint">تحتاج صلاحية إحالة الفوترة أو إدارة الإصلاح لعرض حالة الإحالة، وصلاحيات الذمم لعرض المبالغ.</p>
        @elseif($w->billing_mode==='contract')
            <p class="workshop-hint">هذا الإصلاح ضمن عقد خدمة. استحقاق الفوترة والاستخدام مرتبطان بالعقد، ولا تنشأ فاتورة إصلاح منفصلة.</p>
        @elseif(in_array($w->billing_mode,['warranty','nonbillable']))
            <p class="workshop-hint">الإصلاح معتمد على أساس ضمان أو إصلاح غير مدفوع. لا توجد فاتورة عميل لهذا المسار.</p>
        @elseif($invoice)
            <dl class="workshop-summary workshop-summary-inset"><div><dt>الفاتورة</dt><dd>{{ $invoice['number'] ?? 'مسودة #'.$invoice['id'] }}</dd></div><div><dt>الحالة</dt><dd>{{ ['draft'=>'مسودة','validated'=>'تم التحقق، جاهزة للإصدار','posted'=>'مرحلة','partially_collected'=>'محصلة جزئيًا','collected'=>'محصلة','credited'=>'مسوّاة بإشعار'][$invoice['status']] ?? $invoice['status'] }}</dd></div>@if($invoice['balance_available'])<div><dt>الإجمالي</dt><dd>{{ $invoice['total'] }} EGP</dd></div><div><dt>المتبقي</dt><dd>{{ $invoice['remaining'] === null ? 'لم ترحل بعد' : $invoice['remaining'].' EGP' }}</dd></div>@endif</dl>
            <div class="mt-5 flex flex-wrap gap-3">
                @if($invoice['status']==='draft'&&$canValidateInvoice)<flux:button variant="primary" wire:click="invoiceStep({{ $invoice['version'] }},'validate')" wire:loading.attr="disabled">التحقق من فاتورة الإصلاح</flux:button>@endif
                @if($invoice['status']==='validated'&&$canPostInvoice)<flux:button variant="primary" wire:click="invoiceStep({{ $invoice['version'] }},'post')" wire:confirm="إصدار فاتورة الإصلاح وترحيل الذمة والقيد المحاسبي؟" wire:loading.attr="disabled">إصدار الفاتورة وترحيلها</flux:button>@endif
                @if($invoice['can_view'])<flux:button :href="route('financial.invoices.show',['type'=>'customer','invoice'=>$invoice['id']])" wire:navigate>تفاصيل الفاتورة</flux:button>@if($invoice['number'])<flux:button :href="route('financial.documents',['type'=>'customer-invoice','record'=>$invoice['id']])" icon="arrow-down-tray">الفاتورة PDF</flux:button>@endif @endif
            </div>
        @elseif(in_array($w->status,['verified','delivered'])&&$access->allows(auth()->id(),$w->branch_id,'billing_request'))
            <form wire:submit="requestInvoice" class="space-y-4"><p class="workshop-hint">لم تنشأ فاتورة لهذا الإصلاح. ستنشأ مسودة مرتبطة بالعرض المقبول، دون إعادة إدخال الأسعار.</p><div class="grid gap-4 sm:grid-cols-2"><flux:input type="date" label="تاريخ الفاتورة المطابق للضريبة المعتمدة" wire:model="invoiceDate"/><flux:input type="date" label="تاريخ الاستحقاق" wire:model="dueDate"/></div><flux:button type="submit" variant="primary" wire:loading.attr="disabled">إنشاء مسودة فاتورة الإصلاح</flux:button></form>
        @else
            <p class="workshop-hint">تبدأ إحالة الفوترة بعد التحقق الفني، وينفذها المفوض بإحالة الفواتير. لا توجد فاتورة لهذا الإصلاح حتى الآن.</p>
        @endif
    </x-ui.panel>
    @if($invoice && $invoice['balance_available'] && $invoice['remaining'] !== null && bccomp($invoice['remaining'],'0',2)>0)
        <x-ui.panel><div class="workshop-section-heading"><div><h2>تحصيل فاتورة الإصلاح</h2><p>المبلغ يخصص مباشرة على هذه الفاتورة، ويمكن تسجيل تحصيل جزئي.</p></div></div>
        @if($collectionAccounts)
            <form wire:submit="collectInvoice" class="space-y-4"><div class="grid gap-4 sm:grid-cols-2"><flux:select label="حساب استلام المبلغ" wire:model="collection.account_id"><option value="">اختر البنك أو الخزينة</option>@foreach($collectionAccounts as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }} · {{ $account['kind']==='bank'?'بنك':'نقدية' }}</option>@endforeach</flux:select><flux:input label="المبلغ المحصل بالجنيه" inputmode="decimal" wire:model="collection.amount"/><flux:input type="date" label="تاريخ التحصيل" wire:model="collection.date"/><flux:input label="مرجع استلام المبلغ" wire:model="collection.reference"/></div><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:confirm="تأكيد استلام المبلغ وتخصيصه على فاتورة الإصلاح؟">تسجيل التحصيل وإصدار سند القبض</flux:button></form>
        @else<p class="workshop-hint">التحصيل يحتاج صلاحية تحصيل العملاء وعرض الذمم في فرع الإصلاح، مع حساب خزينة نشط.</p>@endif
        </x-ui.panel>
    @endif
    @if($collectionMovementId)<flux:button :href="route('treasury.movements.pdf',$collectionMovementId)" icon="arrow-down-tray">تنزيل سند القبض</flux:button>@endif
@endif
