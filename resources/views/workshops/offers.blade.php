@php($latestOffer=$estimates->sortByDesc('revision')->first())
@if($w->billing_mode==='contract')
    <x-ui.panel><div class="workshop-section-heading"><div><h2>تغطية عقد الخدمة</h2><p>يرتبط هذا الإصلاح بالعقد #{{ $w->contract_id }}. يعتمد المفوض التغطية قبل بدء العمل، وتثبت الاستخدامات الفعلية على العقد.</p></div></div>
    @if(in_array($w->status,['inspection','waiting_approval'])&&$access->allows(auth()->id(),$w->branch_id,'estimate_approve'))
        @if(!$w->diagnosis)<p class="workshop-hint">سجّل التشخيص وخطة الفحص أولًا.</p>@elseif($w->created_by===auth()->id())<p class="workshop-hint">اعتماد الإصلاح التعاقدي يحتاج مفوضًا مستقلًا عن منشئ الأمر.</p>@else<form wire:submit="authorizeContract" class="space-y-4"><flux:textarea label="مرجع اعتماد التغطية" wire:model="approvalEvidence"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">اعتماد الإصلاح ضمن العقد</flux:button></form>@endif
    @else<p class="workshop-hint">{{ in_array($w->status,['inspection','waiting_approval'])?'اعتماد التغطية يتطلب صلاحية اعتماد التقدير ومفوضًا مستقلًا.':'راجع سجل الأمر للاعتماد التعاقدي والاستخدامات المثبتة.' }}</p>@endif
    </x-ui.panel>
@elseif(in_array($w->billing_mode,['warranty','nonbillable']))
    <x-ui.panel><div class="workshop-section-heading"><div><h2>أساس الإصلاح غير المدفوع</h2><p>{{ $w->billing_mode==='warranty'?'الضمان يرتبط بإصلاح سابق لنفس المعدة وضمان صريح لم تنته مدته.':($intake->customer_owned?'المجاملة تحتاج سببًا وتفويضًا مستقلًا.':'الإصلاح الداخلي مرتبط بمعدة الشركة وعزلها الفعلي في المخزون.') }}</p></div></div>
    @if(in_array($w->status,['inspection','waiting_approval'])&&$access->allows(auth()->id(),$w->branch_id,'nonbillable_approve'))
        @if(!$w->diagnosis)<p class="workshop-hint">يلزم تشخيص وخطة اختبارات قبل اعتماد أساس الإصلاح.</p>@elseif($w->created_by===auth()->id())<p class="workshop-hint">الاعتماد يحتاج مستخدمًا مستقلًا عن منشئ أمر الإصلاح.</p>@else
        <form wire:submit="authorizeNonbillable" class="space-y-4"><flux:select label="أساس الإصلاح" wire:model="nonbillableKind">@if($w->billing_mode==='warranty')<option value="warranty">ضمان إصلاح سابق ساري</option>@elseif(!$intake->customer_owned)<option value="internal">معدة الشركة المعزولة للصيانة</option>@else<option value="goodwill">مجاملة معتمدة</option>@endif</flux:select>@if($w->billing_mode==='warranty')<flux:input label="مرجع أمر الإصلاح السابق في حالة الضمان" wire:model="warrantySource"/><p class="text-sm text-slate-500">راجع أوامر نفس المعدة من ملف الاستلام للتأكد من مصدر الضمان.</p>@endif<flux:textarea label="مرجع التفويض المستقل والأدلة" wire:model="approvalEvidence"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">اعتماد أساس الإصلاح</flux:button></form>
        @endif
    @else<p class="workshop-hint">{{ in_array($w->status,['inspection','waiting_approval'])?'ينتظر هذا الأمر مفوضًا مستقلًا باعتماد الإصلاح غير المدفوع.':'أساس الإصلاح محفوظ في سجل العمليات.' }}</p>@endif
    </x-ui.panel>
@else
    @if($latestOffer)
    <x-ui.panel>
        <div class="workshop-section-heading"><div><h2>عرض الإصلاح الحالي</h2><p>مراجعة {{ $latestOffer->revision }} · صالح حتى {{ $latestOffer->valid_until ?? 'غير مسجل' }}</p></div><span class="workshop-eyebrow">قيمة البيع للعميل</span></div>
        <dl class="workshop-summary workshop-summary-inset"><div><dt>الصافي</dt><dd>{{ $latestOffer->billable_amount }} EGP</dd></div><div><dt>الضريبة</dt><dd>{{ $latestOffer->tax_amount }} EGP</dd></div><div><dt>الإجمالي</dt><dd>{{ bcadd($latestOffer->billable_amount,$latestOffer->tax_amount,2) }} EGP</dd></div><div><dt>الاعتماد الداخلي</dt><dd>{{ $quoteApprovals->contains('estimate_id',$latestOffer->id)?'معتمد':'بانتظار اعتماد مستقل' }}</dd></div></dl>
        @php($currentQuote=$quotes->firstWhere('estimate_id',$latestOffer->id))
        @if($currentQuote&&$access->allows(auth()->id(),$w->branch_id,'document_view'))<flux:button class="mt-5" icon="arrow-down-tray" :href="route('workshops.official',[$w->id,'quote',$currentQuote->id])">عرض الإصلاح PDF</flux:button>@endif
    </x-ui.panel>
    @else
        <p class="workshop-hint">لم ينشأ عرض إصلاح لهذا الأمر. يبدأ العرض بعد حفظ التشخيص وخطة الفحص، بواسطة المفوض بإدارة التقدير وعرض التكلفة.</p>
    @endif

    @if($w->status==='waiting_approval'&&$latestOffer)
    <x-ui.panel>
        <div class="workshop-section-heading"><div><h2>استكمال الموافقات</h2><p>تسلسل واضح لنفس مراجعة العرض: اعتماد السعر، إصدار العرض، ثم توثيق قرار العميل.</p></div></div>
        <div class="space-y-5">
            <flux:select label="نسخة العرض محل الإجراء" wire:model="estimateId"><option value="{{ $latestOffer->id }}">مراجعة {{ $latestOffer->revision }} · {{ $latestOffer->billable_amount }} EGP قبل الضريبة</option></flux:select>
            @if(!$quoteApprovals->contains('estimate_id',$latestOffer->id))
                <h3 class="font-semibold">1. الاعتماد الداخلي للسعر</h3>
                @if($latestOffer->proposed_by===auth()->id())<p class="workshop-hint">أنت أعددت التقدير. يعتمد السعر مستخدم آخر مفوض.</p>@elseif($access->allows(auth()->id(),$w->branch_id,'estimate_approve'))<form wire:submit="decideEstimate" class="space-y-4"><flux:textarea label="مرجع الاعتماد الداخلي" wire:model="approvalEvidence"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">اعتماد السعر بشكل مستقل</flux:button></form>@else<p class="workshop-hint">ينتظر اعتماد مفوض بصلاحية اعتماد تقدير الإصلاح.</p>@endif
            @elseif(!$currentQuote)
                <h3 class="font-semibold">2. إصدار عرض العميل</h3><p class="text-sm text-slate-500">السعر معتمد. أصدر نسخة PDF الخاصة بهذه المراجعة لعرضها على العميل.</p>
                @if($access->allows(auth()->id(),$w->branch_id,'quote_issue'))<flux:button variant="primary" wire:click="issueQuote" wire:loading.attr="disabled">إصدار عرض الإصلاح</flux:button>@else<p class="workshop-hint">الإصدار يتطلب صلاحية إصدار عرض الإصلاح.</p>@endif
            @else
                <h3 class="font-semibold">3. قرار العميل على العرض الصادر</h3>
                @if($w->customer_id&&$access->allows(auth()->id(),$w->branch_id,'customer_acceptance'))<form wire:submit="acceptQuote" class="space-y-4"><flux:select label="قرار العميل" wire:model.boolean="accepted"><option value="1">قبول العرض</option><option value="0">رفض العرض</option></flux:select><flux:textarea label="دليل قرار العميل على نفس العرض الصادر" wire:model="approvalEvidence"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">توثيق قرار العميل</flux:button></form>@else<p class="workshop-hint">توثيق القرار متاح للمفوض بتسجيل قبول العميل.</p>@endif
            @endif
        </div>
    </x-ui.panel>
    @endif

    @if(in_array($w->status,['inspection','waiting_approval','rejected','approved'])&&$canCosts&&$access->allows(auth()->id(),$w->branch_id,'estimate_manage'))
    @if(!$w->diagnosis)<p class="workshop-hint">يسجل الفني التشخيص وخطة الاختبارات قبل إعداد عرض الإصلاح.</p>@else
    <x-ui.panel><form wire:submit="proposeEstimate" class="space-y-5">
        <div class="workshop-section-heading"><div><h2>{{ $latestOffer?'إعداد مراجعة جديدة للعرض':'إعداد عرض الإصلاح' }}</h2><p>تكلفة الورشة منفصلة عن سعر البيع للعميل. أي مراجعة جديدة تحتاج اعتمادًا وقبولًا جديدين قبل التنفيذ.</p></div></div>
        <div class="grid gap-4 sm:grid-cols-2"><flux:select label="خدمة الإصلاح من دليل الأصناف" wire:model="estimate.product_id" search-model="productSearch"><option value="">اختر الخدمة</option>@foreach($products as $p)@if($p['kind']==='service')<option value="{{ $p['id'] }}">{{ $p['name'] }}</option>@endif @endforeach</flux:select></div>
        <fieldset class="space-y-4 border-t pt-5"><legend class="font-semibold">التكلفة المقدرة داخل الورشة</legend><div class="grid gap-4 sm:grid-cols-3">@foreach(['parts_amount'=>'تكلفة القطع المقدرة','labor_amount'=>'تكلفة العمل المقدرة','other_amount'=>'تكاليف أخرى مقدرة'] as $field=>$label)<flux:input :label="$label" inputmode="decimal" wire:model="estimate.{{ $field }}"/>@endforeach</div></fieldset>
        <fieldset class="space-y-4 border-t pt-5"><legend class="font-semibold">عرض البيع للعميل بالجنيه</legend><div class="grid gap-4 sm:grid-cols-2">@foreach(['parts_selling'=>'سعر بيع القطع','labor_selling'=>'أجر الإصلاح للعميل','other_selling'=>'رسوم أخرى','discount_amount'=>'الخصم'] as $field=>$label)<flux:input :label="$label" inputmode="decimal" wire:model.live.debounce.300ms="estimate.{{ $field }}"/>@endforeach</div><p class="workshop-hint">صافي العرض قبل الضريبة: <strong><output>{{ $offerNet === null ? 'أكمل الأسعار لحساب الصافي' : $offerNet.' EGP' }}</output></strong></p></fieldset>
        <div class="grid gap-4 sm:grid-cols-3"><flux:input type="date" label="تاريخ العرض والضريبة" wire:model="estimate.date"/><flux:input type="date" label="صالح حتى" wire:model="estimate.valid_until"/><flux:input type="number" min="0" label="مدة الضمان بالأيام، صفر بدون ضمان" wire:model="estimate.warranty_days"/></div>
        <flux:textarea label="شروط الضمان" wire:model="estimate.warranty_conditions"/><flux:textarea label="مرجع السعر أو سبب الاستثناء التجاري المطلوب اعتماده" wire:model="estimate.pricing_reference"/>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ مراجعة جديدة للاعتماد</flux:button>
    </form></x-ui.panel>
    @endif
    @endif
@endif
@if($estimates->count()>1)
<details class="workshop-disclosure"><summary>مراجعات عروض الإصلاح السابقة <span>{{ $estimates->count() }} مراجعات</span></summary><div class="p-5 space-y-3">@foreach($estimates->sortByDesc('revision') as $e)<p>مراجعة {{ $e->revision }} · الصافي {{ $e->billable_amount }} EGP · الضريبة {{ $e->tax_amount }} EGP · صالحة حتى {{ $e->valid_until }}</p>@endforeach</div></details>
@endif
