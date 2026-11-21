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
        @elseif(in_array($w->status,['verified','delivered']) || ($cancellation && bccomp((string)$cancellation->fee_amount,'0',2)>0))
            @if($access->allows(auth()->id(),$w->branch_id,'billing_request'))
                <form wire:submit="requestInvoice" class="space-y-4"><p class="workshop-hint">{{ $cancellation ? 'توجد رسوم تشخيص/إلغاء متفق عليها بدون فاتورة حتى الآن. يمكنك إنشاء مسودة فاتورة الرسوم ثم ترحيلها وتطبيق العربون أو التحصيل عليها.' : 'لم تنشأ فاتورة لهذا الإصلاح. ستنشأ مسودة مرتبطة بالعرض المقبول، دون إعادة إدخال الأسعار.' }}</p><div class="grid gap-4 sm:grid-cols-2"><flux:input type="date" label="تاريخ الفاتورة المطابق للضريبة المعتمدة" wire:model="invoiceDate"/><flux:input type="date" label="تاريخ الاستحقاق" wire:model="dueDate"/></div><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ $cancellation ? 'إنشاء مسودة فاتورة رسوم الإلغاء' : 'إنشاء مسودة فاتورة الإصلاح' }}</flux:button></form>
            @else
                <p class="workshop-hint">تبدأ إحالة الفوترة بعد التحقق الفني أو الإلغاء برسوم، وينفذها المفوض بإحالة الفواتير.</p>
            @endif
        @else
            <p class="workshop-hint">تبدأ إحالة الفوترة بعد التحقق الفني، وينفذها المفوض بإحالة الفواتير. لا توجد فاتورة لهذا الإصلاح حتى الآن.</p>
        @endif
    </x-ui.panel>

    {{-- العربون والدفعات المقدمة لهذا الأمر --}}
    @if($w->customer_id && in_array($w->billing_mode, ['billable', 'partial'], true))
        <x-ui.panel class="space-y-4">
            <div class="workshop-section-heading">
                <div>
                    <h2>العربون / الدفعات المقدمة لهذا الأمر</h2>
                    <p>أي دفعة مقدمة (عربون) تُربط مباشرة بهذا الأمر وتُحفظ حركة تحصيلها في الخزينة؛ يمكن تطبيقها لاحقاً على الفاتورة أو رد المتبقي للعميل بسند صرف مرتبط لا يُمحى.</p>
                </div>
            </div>

            @if(! $customerAdvanceMapped)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>يلزم ربط حساب «دفعات مقدمة من العملاء»</flux:callout.heading>
                    <flux:callout.text>قبل استلام أو تطبيق عربون، اربط الحساب الاختياري «دفعات مقدمة من العملاء» من <a href="{{ route('accounting.setup') }}" wire:navigate class="font-semibold underline">تجهيز النظام المالي ← الإعدادات الاختيارية</a>.</flux:callout.text>
                </flux:callout>
            @endif

            @if($customerAdvanceMapped && ! in_array($w->status, ['delivered', 'returned_unrepaired'], true) && $collectionAccounts)
                <form wire:submit="recordDeposit" class="space-y-3 rounded-lg border border-slate-200 bg-slate-50/70 p-4">
                    <h3 class="text-sm font-bold text-slate-800">استلام عربون جديد على هذا الأمر</h3>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <flux:select label="حساب الخزينة أو البنك" wire:model="deposit.account_id" required>
                            <option value="">اختر الحساب</option>
                            @foreach($collectionAccounts as $account)
                                <option value="{{ $account['id'] }}">{{ $account['name'] }} · {{ $account['kind']==='bank'?'بنك':'نقدية' }}</option>
                            @endforeach
                        </flux:select>
                        <flux:input label="قيمة العربون ج.م" inputmode="decimal" dir="ltr" wire:model="deposit.amount" required />
                        <flux:input type="date" label="تاريخ الاستلام" wire:model="deposit.date" required />
                        <flux:input label="المرجع / البيان" wire:model="deposit.reference" required placeholder="عربون تحت حساب الإصلاح" />
                    </div>
                    <flux:button type="submit" variant="primary" size="sm" wire:confirm="تسجيل استلام العربون وربطه بأمر الإصلاح وإصدار سند القبض؟" wire:loading.attr="disabled">تسجيل العربون وإصدار السند</flux:button>
                </form>
            @endif

            @if($financialLedger['available'])
                @if($financialLedger['advances'] !== [])
                    <div class="space-y-3">
                        @foreach($financialLedger['advances'] as $adv)
                            <div class="rounded-lg border border-slate-200 bg-white p-4 space-y-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-bold text-slate-900">
                                            عربون #{{ $adv['id'] }}
                                            @if($adv['reversed'])<span class="ms-2 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">تم عكس التحصيل</span>@endif
                                        </p>
                                        <p class="text-xs text-slate-600">التاريخ: <bdi dir="ltr">{{ $adv['movement_date'] }}</bdi> · المرجع: {{ $adv['reference'] }} · قيد #{{ $adv['journal_id'] }}</p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        @if($adv['official_document_id'])
                                            <flux:button size="xs" :href="route('treasury.movements.pdf', $adv['movement_id'])" icon="arrow-down-tray">سند القبض PDF</flux:button>
                                        @endif
                                        @if(! $adv['reversed'] && bccomp((string)$adv['remaining'], '0', 2) > 0)
                                            @if($canApplyAdvance && $invoice && $invoice['balance_available'] && $invoice['remaining'] !== null && bccomp((string)$invoice['remaining'], '0', 2) > 0)
                                                <flux:button size="xs" wire:click="$set('applyAdvance.advance_id', '{{ $adv['id'] }}')">تحديد للتطبيق على الفاتورة</flux:button>
                                            @endif
                                            @if($canRefundAdvance)
                                                <flux:button size="xs" wire:click="$set('refundAdvance.advance_id', '{{ $adv['id'] }}')">تحديد للرد</flux:button>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                                <dl class="grid gap-2 sm:grid-cols-4 text-xs border-t border-slate-100 pt-2">
                                    <div><dt class="text-slate-500">الأصل المحصل</dt><dd class="font-semibold tabular-nums" dir="ltr">{{ $adv['original_amount'] }} EGP</dd></div>
                                    <div><dt class="text-slate-500">المطبّق على الفاتورة</dt><dd class="font-semibold text-blue-700 tabular-nums" dir="ltr">{{ $adv['applied_amount'] }} EGP</dd></div>
                                    <div><dt class="text-slate-500">المسترد للعميل</dt><dd class="font-semibold text-amber-700 tabular-nums" dir="ltr">{{ $adv['refunded_amount'] }} EGP</dd></div>
                                    <div><dt class="text-slate-500">المتبقي من العربون</dt><dd class="font-bold text-emerald-700 tabular-nums" dir="ltr">{{ $adv['remaining'] }} EGP</dd></div>
                                </dl>
                            </div>
                        @endforeach
                    </div>

                    @if($canApplyAdvance && $invoice && $invoice['balance_available'] && $invoice['remaining'] !== null && bccomp((string)$invoice['remaining'], '0', 2) > 0 && collect($financialLedger['advances'])->contains(fn($a) => ! $a['reversed'] && bccomp((string)$a['remaining'], '0', 2) > 0))
                        <form wire:submit="applyDeposit" class="space-y-3 rounded-lg border border-blue-200 bg-blue-50/40 p-4">
                            <h3 class="text-sm font-bold text-blue-900">تطبيق رصيد العربون على الفاتورة (متبقي الفاتورة {{ $invoice['remaining'] }} EGP)</h3>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <flux:select label="العربون" wire:model="applyAdvance.advance_id" required>
                                    <option value="">اختر العربون</option>
                                    @foreach($financialLedger['advances'] as $adv)
                                        @if(! $adv['reversed'] && bccomp((string)$adv['remaining'], '0', 2) > 0)
                                            <option value="{{ $adv['id'] }}">عربون #{{ $adv['id'] }} · متبقٍ {{ $adv['remaining'] }} EGP</option>
                                        @endif
                                    @endforeach
                                </flux:select>
                                <flux:input label="المبلغ المراد تطبيقه" inputmode="decimal" dir="ltr" wire:model="applyAdvance.amount" required />
                                <flux:input type="date" label="تاريخ التطبيق" wire:model="applyAdvance.date" required />
                            </div>
                            <flux:button type="submit" variant="primary" size="sm" wire:confirm="تطبيق العربون على فاتورة الإصلاح وترحيل القيد المحاسبي؟" wire:loading.attr="disabled">تطبيق العربون على الفاتورة</flux:button>
                        </form>
                    @endif

                    @if($canRefundAdvance && $refundAccounts && collect($financialLedger['advances'])->contains(fn($a) => ! $a['reversed'] && bccomp((string)$a['remaining'], '0', 2) > 0))
                        <form wire:submit="refundDeposit" class="space-y-3 rounded-lg border border-amber-200 bg-amber-50/40 p-4">
                            <h3 class="text-sm font-bold text-amber-900">رد العربون المتبقي للعميل (صرف نقدي/بنكي مرتبط بالأصل)</h3>
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <flux:select label="العربون" wire:model="refundAdvance.advance_id" required>
                                    <option value="">اختر العربون</option>
                                    @foreach($financialLedger['advances'] as $adv)
                                        @if(! $adv['reversed'] && bccomp((string)$adv['remaining'], '0', 2) > 0)
                                            <option value="{{ $adv['id'] }}">عربون #{{ $adv['id'] }} · متبقٍ {{ $adv['remaining'] }} EGP</option>
                                        @endif
                                    @endforeach
                                </flux:select>
                                <flux:select label="حساب الصرف" wire:model="refundAdvance.account_id" required>
                                    <option value="">اختر الحساب</option>
                                    @foreach($refundAccounts as $acc)
                                        <option value="{{ $acc['id'] }}">{{ $acc['name'] }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:input label="المبلغ المسترد" inputmode="decimal" dir="ltr" wire:model="refundAdvance.amount" required />
                                <flux:input type="date" label="تاريخ الرد" wire:model="refundAdvance.date" required />
                                <flux:input label="سبب ومرجع الرد" wire:model="refundAdvance.reference" required placeholder="رد باقي العربون بعد الإلغاء" />
                            </div>
                            <flux:button type="submit" size="sm" wire:confirm="تنفيذ رد المبلغ فعلياً من الخزينة وربطه بالعربون الأصلي؟" wire:loading.attr="disabled">تنفيذ رد العربون</flux:button>
                        </form>
                    @endif
                @else
                    <p class="workshop-hint">لم يُسجل عربون لهذا الأمر حتى الآن.</p>
                @endif
            @else
                <p class="workshop-hint">عرض تفاصيل العربون والتحصيلات يتطلب صلاحية عرض الذمم (M12.ar_view) في فرع الإصلاح.</p>
            @endif
        </x-ui.panel>
    @endif

    @if($invoice && $invoice['balance_available'] && $invoice['remaining'] !== null && bccomp($invoice['remaining'],'0',2)>0)
        <x-ui.panel><div class="workshop-section-heading"><div><h2>تحصيل فاتورة الإصلاح</h2><p>المبلغ يخصص مباشرة على هذه الفاتورة، ويمكن تسجيل تحصيل جزئي على دفعات.</p></div></div>
        @if($collectionAccounts)
            <form wire:submit="collectInvoice" class="space-y-4"><div class="grid gap-4 sm:grid-cols-2"><flux:select label="حساب استلام المبلغ" wire:model="collection.account_id"><option value="">اختر البنك أو الخزينة</option>@foreach($collectionAccounts as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }} · {{ $account['kind']==='bank'?'بنك':'نقدية' }}</option>@endforeach</flux:select><flux:input label="المبلغ المحصل بالجنيه (دفعة كاملة أو جزئية)" inputmode="decimal" wire:model="collection.amount"/><flux:input type="date" label="تاريخ التحصيل" wire:model="collection.date"/><flux:input label="مرجع استلام المبلغ" wire:model="collection.reference"/></div><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:confirm="تأكيد استلام المبلغ وتخصيصه على فاتورة الإصلاح؟">تسجيل التحصيل وإصدار سند القبض</flux:button></form>
        @else<p class="workshop-hint">التحصيل يحتاج صلاحية تحصيل العملاء وعرض الذمم في فرع الإصلاح، مع حساب خزينة نشط.</p>@endif
        </x-ui.panel>
    @endif

    @if($financialLedger['available'])
        <x-ui.panel class="space-y-4">
            <div class="workshop-section-heading">
                <div>
                    <h2>سجل التحصيلات والردود المرتبط بالأمر</h2>
                    <p>تاريخ مترابط لكل تحصيل أو عربون أو تطبيق أو رد، لا يُمحى أي أثر تاريخي له.</p>
                </div>
            </div>
            @php($t = $financialLedger['totals'])
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-3 text-xs"><p class="text-slate-600">إجمالي المقبوض (عربون + دفعات)</p><p class="mt-1 text-base font-bold text-emerald-800 tabular-nums" dir="ltr">{{ $t['total_collected'] }} EGP</p></div>
                <div class="rounded-lg border border-blue-200 bg-blue-50/40 p-3 text-xs"><p class="text-slate-600">العربون المطبّق على الفاتورة</p><p class="mt-1 text-base font-bold text-blue-800 tabular-nums" dir="ltr">{{ $t['advances_applied'] }} EGP</p></div>
                <div class="rounded-lg border border-amber-200 bg-amber-50/40 p-3 text-xs"><p class="text-slate-600">المسترد للعميل</p><p class="mt-1 text-base font-bold text-amber-800 tabular-nums" dir="ltr">{{ $t['total_refunded'] }} EGP</p></div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs"><p class="text-slate-600">صافي النقدية المقبوضة (متبقٍ غير مسترد {{ $t['advances_remaining'] }})</p><p class="mt-1 text-base font-bold text-slate-900 tabular-nums" dir="ltr">{{ $t['net_cash_received'] }} EGP</p></div>
            </div>
            <div class="space-y-2 text-xs">
                @foreach($financialLedger['advances'] as $adv)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-200 px-3 py-2">
                        <span><strong>استلام عربون #{{ $adv['id'] }}</strong> · <bdi dir="ltr">{{ $adv['movement_date'] }}</bdi> · {{ $adv['reference'] }} · <strong dir="ltr">{{ $adv['original_amount'] }} EGP</strong> @if($adv['reversed'])(معكوس)@endif</span>
                        <span class="flex gap-2"><a href="{{ route('treasury.movements.show', $adv['movement_id']) }}" wire:navigate class="text-blue-700 hover:underline">حركة #{{ $adv['movement_id'] }}</a>@if($adv['official_document_id'])<a href="{{ route('treasury.movements.pdf', $adv['movement_id']) }}" class="text-blue-700 hover:underline">PDF</a>@endif</span>
                    </div>
                    @foreach($adv['applications'] as $appRow)
                        <div class="ms-4 flex flex-wrap items-center justify-between gap-2 rounded border border-blue-100 bg-blue-50/40 px-3 py-1.5">
                            <span>↳ تطبيق عربون #{{ $adv['id'] }} على الفاتورة · <bdi dir="ltr">{{ $appRow['effective_date'] }}</bdi> · <strong dir="ltr">{{ $appRow['amount'] }} EGP</strong></span>
                            <span>قيد #{{ $appRow['journal_id'] }}</span>
                        </div>
                    @endforeach
                    @foreach($adv['refunds'] as $rf)
                        <div class="ms-4 flex flex-wrap items-center justify-between gap-2 rounded border border-amber-100 bg-amber-50/40 px-3 py-1.5">
                            <span>↳ <strong>رد عربون #{{ $adv['id'] }} للعميل</strong> · <bdi dir="ltr">{{ $rf['refund_date'] }}</bdi> · {{ $rf['reference'] }} · <strong dir="ltr">{{ $rf['amount'] }} EGP</strong> @if($rf['reversed'])(معكوس)@endif</span>
                            <span class="flex gap-2"><a href="{{ route('treasury.movements.show', $rf['movement_id']) }}" wire:navigate class="text-blue-700 hover:underline">حركة #{{ $rf['movement_id'] }}</a>@if($rf['official_document_id'])<a href="{{ route('treasury.movements.pdf', $rf['movement_id']) }}" class="text-blue-700 hover:underline">PDF</a>@endif</span>
                        </div>
                    @endforeach
                @endforeach
                @foreach($financialLedger['collections'] as $coll)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded border border-emerald-200 bg-emerald-50/30 px-3 py-2">
                        <span><strong>تحصيل على الفاتورة #{{ $coll['id'] }}</strong> · <bdi dir="ltr">{{ $coll['movement_date'] }}</bdi> · {{ $coll['reference'] }} · <strong dir="ltr">{{ $coll['amount'] }} EGP</strong> @if($coll['reversed'])(معكوس)@endif</span>
                        <span class="flex gap-2"><a href="{{ route('treasury.movements.show', $coll['movement_id']) }}" wire:navigate class="text-blue-700 hover:underline">حركة #{{ $coll['movement_id'] }}</a>@if($coll['official_document_id'])<a href="{{ route('treasury.movements.pdf', $coll['movement_id']) }}" class="text-blue-700 hover:underline">PDF</a>@endif</span>
                    </div>
                @endforeach
            </div>
        </x-ui.panel>
    @endif
    @if($collectionMovementId)<flux:button :href="route('treasury.movements.pdf',$collectionMovementId)" icon="arrow-down-tray">تنزيل سند القبض</flux:button>@endif
@endif
