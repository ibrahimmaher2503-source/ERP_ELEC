@php($latestOffer=$estimates->sortByDesc('revision')->first())
@php($taxLabelFn=fn(?string $t)=>\App\Modules\Accounting\Application\TaxCalculator::treatmentLabel($t))
@if($w->billing_mode==='contract')
    <x-ui.panel><div class="workshop-section-heading"><div><h2>تغطية عقد الخدمة</h2><p>يرتبط هذا الإصلاح بالعقد #{{ $w->contract_id }}. يعتمد المفوض التغطية قبل بدء العمل، وتثبت الاستخدامات الفعلية على العقد.</p></div></div>
    @if(in_array($w->status,['inspection','waiting_approval'])&&$access->allows(auth()->id(),$w->branch_id,'estimate_approve'))
        @if(!$w->diagnosis)<p class="workshop-hint">سجّل التشخيص وخطة الفحص أولًا.</p>@elseif($w->created_by===auth()->id() && !$canSelfApprove)<p class="workshop-hint">اعتماد الإصلاح التعاقدي يحتاج مفوضًا مستقلًا عن منشئ الأمر.</p>@else<form wire:submit="authorizeContract" class="space-y-4">@if($w->created_by===auth()->id() && $canSelfApprove)<p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">اعتماد ذاتي موثّق — مسموح حسب سياسة الدور ويُثبت في سجل التدقيق (<bdi>self_approved: true</bdi>).</p>@endif<flux:textarea label="مرجع اعتماد التغطية" wire:model="approvalEvidence"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ $w->created_by===auth()->id() ? 'اعتماد الإصلاح ضمن العقد (اعتماد ذاتي موثّق)' : 'اعتماد الإصلاح ضمن العقد' }}</flux:button></form>@endif
    @else<p class="workshop-hint">{{ in_array($w->status,['inspection','waiting_approval'])?'اعتماد التغطية يتطلب صلاحية اعتماد التقدير ومفوضًا مستقلًا.':'راجع سجل الأمر للاعتماد التعاقدي والاستخدامات المثبتة.' }}</p>@endif
    </x-ui.panel>
@elseif(in_array($w->billing_mode,['warranty','nonbillable']))
    <x-ui.panel><div class="workshop-section-heading"><div><h2>أساس الإصلاح غير المدفوع</h2><p>{{ $w->billing_mode==='warranty'?'الضمان يرتبط بإصلاح سابق لنفس المعدة وضمان صريح لم تنته مدته.':($intake->customer_owned?'المجاملة تحتاج سببًا وتفويضًا مستقلًا.':'الإصلاح الداخلي مرتبط بمعدة الشركة وعزلها الفعلي في المخزون.') }}</p></div></div>
    @if(in_array($w->status,['inspection','waiting_approval'])&&$access->allows(auth()->id(),$w->branch_id,'nonbillable_approve'))
        @if(!$w->diagnosis)<p class="workshop-hint">يلزم تشخيص وخطة اختبارات قبل اعتماد أساس الإصلاح.</p>@elseif($w->created_by===auth()->id() && !$canSelfApprove)<p class="workshop-hint">الاعتماد يحتاج مستخدمًا مستقلًا عن منشئ أمر الإصلاح.</p>@else
        <form wire:submit="authorizeNonbillable" class="space-y-4">@if($w->created_by===auth()->id() && $canSelfApprove)<p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">اعتماد ذاتي موثّق — مسموح حسب سياسة الدور ويُثبت في سجل التدقيق.</p>@endif<flux:select label="أساس الإصلاح" wire:model="nonbillableKind">@if($w->billing_mode==='warranty')<option value="warranty">ضمان إصلاح سابق ساري</option>@elseif(!$intake->customer_owned)<option value="internal">معدة الشركة المعزولة للصيانة</option>@else<option value="goodwill">مجاملة معتمدة</option>@endif</flux:select>@if($w->billing_mode==='warranty')<flux:input label="مرجع أمر الإصلاح السابق في حالة الضمان" wire:model="warrantySource"/><p class="text-sm text-slate-500">راجع أوامر نفس المعدة من ملف الاستلام للتأكد من مصدر الضمان.</p>@endif<flux:textarea label="مرجع التفويض والأدلة" wire:model="approvalEvidence"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ $w->created_by===auth()->id() ? 'اعتماد أساس الإصلاح (اعتماد ذاتي موثّق)' : 'اعتماد أساس الإصلاح' }}</flux:button></form>
        @endif
    @else<p class="workshop-hint">{{ in_array($w->status,['inspection','waiting_approval'])?'ينتظر هذا الأمر مفوضًا مستقلًا باعتماد الإصلاح غير المدفوع.':'أساس الإصلاح محفوظ في سجل العمليات.' }}</p>@endif
    </x-ui.panel>
@else
    @if($latestOffer)
    <x-ui.panel>
        <div class="workshop-section-heading"><div><h2>عرض الإصلاح الحالي</h2><p>مراجعة {{ $latestOffer->revision }} · صالح حتى {{ $latestOffer->valid_until ?? 'غير مسجل' }}</p></div><span class="workshop-eyebrow">قيمة البيع للعميل</span></div>
        <dl class="workshop-summary workshop-summary-inset"><div><dt>الصافي</dt><dd>{{ $latestOffer->billable_amount }} EGP</dd></div><div><dt>الضريبة</dt><dd>{{ $latestOffer->tax_amount }} EGP</dd></div><div><dt>الإجمالي</dt><dd>{{ bcadd($latestOffer->billable_amount,$latestOffer->tax_amount,2) }} EGP</dd></div><div><dt>المعاملة الضريبية</dt><dd>{{ $taxLabelFn($latestOffer->tax_treatment ?? 'rule') }}</dd></div><div><dt>الاعتماد الداخلي</dt><dd>{{ $quoteApprovals->contains('estimate_id',$latestOffer->id)?'معتمد':'بانتظار اعتماد' }}</dd></div></dl>
        @if(($latestOffer->tax_treatment ?? 'rule') === 'exempt_0' && $latestOffer->tax_exempt_reason)
            <p class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">سبب عدم احتساب الضريبة: {{ $latestOffer->tax_exempt_reason }}</p>
        @endif
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
                @if($latestOffer->proposed_by===auth()->id() && !$canSelfApprove)<p class="workshop-hint">أنت أعددت التقدير. يعتمد السعر مستخدم آخر مفوض.</p>@elseif($access->allows(auth()->id(),$w->branch_id,'estimate_approve'))<form wire:submit="decideEstimate" class="space-y-4">@if($latestOffer->proposed_by===auth()->id() && $canSelfApprove)<p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">اعتماد ذاتي موثّق — أنت مُعد التقدير والسياسة تتيح لك الاعتماد مع تسجيله صراحة في التدقيق (<bdi>self_approved: true</bdi>).</p>@endif<flux:textarea label="مرجع الاعتماد الداخلي" wire:model="approvalEvidence"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ $latestOffer->proposed_by===auth()->id() ? 'اعتماد السعر (اعتماد ذاتي موثّق)' : 'اعتماد السعر بشكل مستقل' }}</flux:button></form>@else<p class="workshop-hint">ينتظر اعتماد مفوض بصلاحية اعتماد تقدير الإصلاح.</p>@endif
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
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select label="خدمة الإصلاح من دليل الأصناف" wire:model="estimate.product_id" search-model="productSearch"><option value="">اختر الخدمة</option>@foreach($products as $p)@if($p['kind']==='service')<option value="{{ $p['id'] }}">{{ $p['name'] }}</option>@endif @endforeach</flux:select>
            <flux:select label="المعاملة الضريبية للمستند" wire:model.live="estimate.tax_treatment">
                <option value="vat_14">خاضع لضريبة القيمة المضافة 14%</option>
                <option value="exempt_0">بدون ضريبة (0%)</option>
            </flux:select>
        </div>
        @if(($estimate['tax_treatment'] ?? 'vat_14') === 'exempt_0')
            <flux:textarea label="سبب اختيار بدون ضريبة (إلزامي)" wire:model="estimate.tax_exempt_reason" placeholder="اكتب السبب الواضح لعدم احتساب الضريبة على هذا المستند (5 أحرف على الأقل)…" required />
        @endif
        <fieldset class="space-y-4 border-t pt-5"><legend class="font-semibold">التكلفة المقدرة داخل الورشة</legend><div class="grid gap-4 sm:grid-cols-3">@foreach(['parts_amount'=>'تكلفة القطع المقدرة','labor_amount'=>'تكلفة العمل المقدرة','other_amount'=>'تكاليف أخرى مقدرة'] as $field=>$label)<flux:input :label="$label" inputmode="decimal" wire:model="estimate.{{ $field }}"/>@endforeach</div></fieldset>
        <fieldset class="space-y-4 border-t pt-5"><legend class="font-semibold">عرض البيع للعميل بالجنيه</legend><div class="grid gap-4 sm:grid-cols-2">@foreach(['parts_selling'=>'سعر بيع القطع','labor_selling'=>'أجر الإصلاح للعميل','other_selling'=>'رسوم أخرى','discount_amount'=>'الخصم'] as $field=>$label)<flux:input :label="$label" inputmode="decimal" wire:model.live.debounce.300ms="estimate.{{ $field }}"/>@endforeach</div>
            <div class="rounded-lg border border-blue-200 bg-blue-50/60 p-3 text-xs text-slate-700 flex flex-wrap gap-x-6 gap-y-1">
                <span>صافي العرض قبل الضريبة: <strong><output>{{ $offerNet === null ? 'أكمل الأسعار' : $offerNet.' EGP' }}</output></strong></span>
                <span>الضريبة ({{ ($estimate['tax_treatment'] ?? 'vat_14') === 'exempt_0' ? '0%' : '14%' }}): <strong><output>{{ $offerTaxPreview === null ? '—' : $offerTaxPreview.' EGP' }}</output></strong></span>
                <span>الإجمالي النهائي للعميل: <strong><output>{{ $offerTotalPreview === null ? '—' : $offerTotalPreview.' EGP' }}</output></strong></span>
            </div>
        </fieldset>
        <div class="grid gap-4 sm:grid-cols-3"><flux:input type="date" label="تاريخ العرض والضريبة" wire:model="estimate.date"/><flux:input type="date" label="صالح حتى" wire:model="estimate.valid_until"/><flux:input type="number" min="0" label="مدة الضمان بالأيام، صفر بدون ضمان" wire:model="estimate.warranty_days"/></div>
        <flux:textarea label="شروط الضمان (اختيارية عند ضمان 0 يوم)" wire:model="estimate.warranty_conditions"/><flux:textarea label="مرجع السعر أو سبب الاستثناء التجاري المطلوب اعتماده" wire:model="estimate.pricing_reference"/>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ مراجعة جديدة للاعتماد</flux:button>
    </form></x-ui.panel>
    @endif
    @endif
@endif
@if($estimates->count()>1)
<details class="workshop-disclosure"><summary>مراجعات عروض الإصلاح السابقة <span>{{ $estimates->count() }} مراجعات</span></summary><div class="p-5 space-y-3">@foreach($estimates->sortByDesc('revision') as $e)<p>مراجعة {{ $e->revision }} · الصافي {{ $e->billable_amount }} EGP · الضريبة {{ $e->tax_amount }} EGP ({{ $taxLabelFn($e->tax_treatment ?? 'rule') }}) · صالحة حتى {{ $e->valid_until }}</p>@endforeach</div></details>
@endif

@if($cancellation)
<x-ui.panel class="border-amber-200 bg-amber-50/40 space-y-3">
    <div class="workshop-section-heading">
        <div>
            <h2>إلغاء الإصلاح بعد التشخيص</h2>
            <p>تم تثبيت الإلغاء بتاريخ {{ \Carbon\CarbonImmutable::parse($cancellation->created_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}.</p>
        </div>
        <span class="workshop-eyebrow">ملغى بعد التشخيص</span>
    </div>
    <dl class="workshop-summary workshop-summary-inset">
        <div><dt>سبب الإلغاء</dt><dd>{{ $cancellation->reason }}</dd></div>
        <div><dt>إثبات موافقة العميل</dt><dd>{{ $cancellation->customer_agreement }}</dd></div>
        <div><dt>رسوم التشخيص/الإلغاء المتفق عليها</dt><dd class="tabular-nums">{{ $cancellation->fee_amount }} EGP</dd></div>
        <div><dt>الضريبة والإجمالي</dt><dd class="tabular-nums">{{ $cancellation->tax_amount }} EGP · الإجمالي {{ $cancellation->total_amount }} EGP ({{ $taxLabelFn($cancellation->tax_treatment ?? 'exempt_0') }})</dd></div>
    </dl>
    @if($cancellation->tax_treatment === 'exempt_0' && $cancellation->tax_exempt_reason)
        <p class="text-xs text-slate-600">سبب عدم احتساب الضريبة: {{ $cancellation->tax_exempt_reason }}</p>
    @endif
    <div class="flex flex-wrap gap-2">
        @if(bccomp((string) $cancellation->fee_amount, '0', 2) > 0)
            <flux:button size="sm" variant="primary" :href="route('workshops.jobs.show', ['job' => $w->id, 'tab' => 'billing'])" wire:navigate>إصدار فاتورة الرسوم / تسوية العربون والرد</flux:button>
        @else
            <flux:button size="sm" :href="route('workshops.jobs.show', ['job' => $w->id, 'tab' => 'billing'])" wire:navigate>مراجعة العربون والرد</flux:button>
        @endif
        @if($w->status === 'rejected')
            <flux:button size="sm" :href="route('workshops.jobs.show', ['job' => $w->id, 'tab' => 'delivery'])" wire:navigate>تسليم المعدة غير المصلحة للعميل</flux:button>
        @endif
    </div>
</x-ui.panel>
@elseif($w->diagnosis && in_array($w->status, ['inspection', 'waiting_approval', 'rejected', 'approved'], true) && $access->allows(auth()->id(), $w->branch_id, 'customer_acceptance'))
<details class="workshop-disclosure">
    <summary>إلغاء الإصلاح بعد التشخيص (برسوم تشخيص متفق عليها أو بدون رسوم) <span>إجراء إلغاء موثق</span></summary>
    <div class="p-5">
        <form wire:submit="cancelAfterDiagnosis" class="space-y-4">
            <p class="text-sm text-slate-600">عند إلغاء العميل للإصلاح بعد إتمام التشخيص، يمكنك تثبيت رسوم تشخيص متفق عليها وفوترتها أو رد أي عربون متبقٍ بسجل تاريخي لا يُمحى.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:textarea label="سبب إلغاء الإصلاح" wire:model="cancellationForm.reason" rows="2" required />
                <flux:textarea label="إثبات موافقة العميل على الإلغاء / الرسوم" wire:model="cancellationForm.customer_agreement" rows="2" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input label="رسوم التشخيص/الإلغاء المتفق عليها (صفر = مجاني)" inputmode="decimal" dir="ltr" wire:model.live="cancellationForm.fee_amount" required />
                <flux:input type="date" label="تاريخ الإلغاء" wire:model="cancellationForm.date" required />
                @if($intake->customer_owned && $w->billing_mode !== 'contract')
                    <flux:select label="بند خدمة الرسوم (عند وجود رسوم &gt; 0)" wire:model="cancellationForm.product_id">
                        <option value="">اختر الخدمة</option>
                        @foreach($products as $p)
                            @if($p['kind'] === 'service')
                                <option value="{{ $p['id'] }}">{{ $p['name'] }}</option>
                            @endif
                        @endforeach
                    </flux:select>
                @endif
            </div>
            @if($intake->customer_owned && $w->billing_mode !== 'contract')
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select label="المعاملة الضريبية للرسوم" wire:model.live="cancellationForm.tax_treatment">
                        <option value="exempt_0">بدون ضريبة (0%)</option>
                        <option value="vat_14">خاضع لضريبة القيمة المضافة 14%</option>
                    </flux:select>
                    @if(($cancellationForm['tax_treatment'] ?? 'exempt_0') === 'exempt_0')
                        <flux:input label="سبب عدم احتساب الضريبة (عند وجود رسوم)" wire:model="cancellationForm.tax_exempt_reason" />
                    @endif
                </div>
            @endif
            <flux:button type="submit" variant="danger" wire:confirm="تأكيد إلغاء أمر الإصلاح بعد التشخيص وتحويل العهدة للإعادة دون إصلاح؟" wire:loading.attr="disabled">تثبيت إلغاء الإصلاح بعد التشخيص</flux:button>
        </form>
    </div>
</details>
@endif
