@php
    use App\Modules\Sales\Presentation\SalesOrderDisplay as D;
    $linesById = $record->lines->keyBy('id');
@endphp
<x-erp.section-card title="الاعتماد والحجز" icon="check">
    @if($record->status === 'pending_approval' && $can('approve') && (!$isReviewRequester || $canSelfApprove))
        @if($isReviewRequester && $canSelfApprove)
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                اعتماد ذاتي موثّق — أنت مقدم طلب المراجعة، والسياسة المفعّلة لدورك تتيح لك الاعتماد مع إثباته في سجل التدقيق (<bdi>self_approved: true</bdi>).
            </div>
        @endif
        @if($customerCredit && ($customerCredit['credit_limit'] ?? null) !== null)
            <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50/70 px-3 py-2 text-xs text-blue-900">
                حد ائتمان العميل المسجل: <strong class="tabular-nums"><bdi>{{ D::money((string) $customerCredit['credit_limit']) }} ج.م</bdi></strong> (يُفحص الرصيد الفعلي تلقائيًا عند الاعتماد).
            </div>
        @endif

        @if($warehouseChoices !== [])
            <div class="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                <div class="min-w-52 flex-1">
                    <flux:select wire:model="bulkWarehouse" label="تطبيق مستودع موحد على كل البنود">
                        <option value="">اختر مستودعًا لتطبيقه دفعة واحدة…</option>
                        @foreach($warehouseChoices as $warehouse)
                            <option value="{{ $warehouse['id'] }}">{{ $warehouse['name'] }}</option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:button type="button" size="sm" wire:click="applyWarehouseToAll">تطبيق على الكل</flux:button>
            </div>
        @endif

        <p class="mb-3 text-sm text-slate-600">اختر مستودع كل بند. يسجل الاعتماد الحجز الفعلي والكمية المؤجلة عند نقص المخزون.</p>
        <div class="grid gap-3 md:grid-cols-2">
            @foreach($record->lines as $line)
                <flux:select wire:model="reservationWarehouses.{{ $line->id }}" :label="$line->description.' ('.D::qty((string) $line->quantity).' '.($line->product_snapshot['unit_name'] ?? '').')'">
                    <option value="">اختر مستودع الحجز</option>
                    @foreach($warehouseChoices as $warehouse)
                        <option value="{{ $warehouse['id'] }}">{{ $warehouse['name'] }}</option>
                    @endforeach
                </flux:select>
            @endforeach
        </div>

        @if($commercialReview['blockers'])
            <ul class="mt-4 list-inside list-disc space-y-1 text-sm text-amber-900">
                @foreach($commercialReview['blockers'] as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        @endif

        <flux:button class="mt-4" wire:click="operate('approve')" wire:loading.attr="disabled" variant="primary" icon="check">اعتماد أمر البيع وطلب الحجز</flux:button>
    @elseif($record->status === 'approved')
        <div class="space-y-3">
            <p class="text-sm text-emerald-800">أمر البيع معتمد رسميًا، وتم تسجيل الحجز والكميات المؤجلة.</p>
            <flux:button size="sm" :href="route('sales-orders.screen', ['order' => $record->id, 'screen' => 'delivery'])" wire:navigate icon="truck">الانتقال إلى التخصيص والتسليم</flux:button>
        </div>
    @else
        <p class="text-sm text-slate-600">يتطلب الاعتماد طلب مراجعة مستقلًا ومصدرًا تجاريًا معتمدًا وصلاحية الفرع ونطاق مستودع الحجز.</p>
    @endif

    @if($record->source_type === 'direct' && $record->status === 'pending_approval' && $commercialReview['exceptions'])
        <div class="mt-5 space-y-4 border-t border-slate-200 pt-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-bold text-slate-900">الاستثناءات التجارية المرصودة ({{ count($commercialReview['exceptions']) }})</h3>
                @if($exceptionDecision)
                    <span class="orders-badge" data-tone="success">تم تسجيل قرار الاستثناءات</span>
                @else
                    <span class="orders-badge" data-tone="warning">بانتظار قرار مسؤول الاستثناءات</span>
                @endif
            </div>

            <ul class="space-y-2 text-xs">
                @foreach($commercialReview['exceptions'] as $ex)
                    @php
                        $exType = $ex['type'] ?? '';
                        $exLine = ! empty($ex['line_id']) ? $linesById->get((int) $ex['line_id']) : null;
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50/50 px-3 py-2">
                        <div>
                            <strong class="text-slate-900">{{ D::EXCEPTION_LABELS[$exType] ?? $exType }}</strong>
                            @if($exLine)
                                <span class="text-slate-600"> — {{ $exLine->description }}</span>
                            @endif
                        </div>
                        <div class="text-slate-700 tabular-nums">
                            @if($exType === 'price')
                                المرجعي: <bdi>{{ D::money((string) ($ex['approved'] ?? null)) }}</bdi> ← المقترح: <strong><bdi>{{ D::money((string) ($ex['requested'] ?? null)) }} ج.م</bdi></strong>
                            @elseif(in_array($exType, ['line_discount', 'order_discount'], true))
                                {{ ($ex['mode'] ?? '') === 'percent' ? D::qty((string) ($ex['requested'] ?? '0')).'%' : D::money((string) ($ex['requested'] ?? '0')).' ج.م' }}
                            @elseif(in_array($exType, ['payment_terms', 'delivery_terms'], true))
                                {{ trim((string) ($ex['requested'] ?? '')) === '' ? 'غير محدد' : 'نص مخصص خارج القوالب المعتمدة' }}
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            @if($exceptionDecision)
                <div class="rounded-lg border border-emerald-200 bg-emerald-50/70 p-3 text-sm text-emerald-900">
                    <p class="font-semibold">قرار الاستثناءات مسجل لهذا الطلب</p>
                    <p class="mt-1 text-xs">المقرِّر: {{ \App\Modules\Organization\Presentation\UserNames::name((int) $exceptionDecision->decided_by) }} · <bdi>{{ \Carbon\CarbonImmutable::parse($exceptionDecision->decided_at)->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</bdi></p>
                    <p class="mt-1 text-xs">السبب: {{ $exceptionDecision->reason }}</p>
                </div>
            @elseif($can('exception_approve') && (!$isReviewRequester || $canSelfApprove))
                <div class="space-y-3 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                    <p class="text-xs text-slate-600">{{ $isReviewRequester ? 'اعتماد ذاتي موثّق لقرار الاستثناءات حسب سياسة الدور، ثم تنفيذ اعتماد الأمر.' : 'سجّل القرار الإضافي من دور مسؤول الاستثناءات أولًا، ثم نفّذ اعتماد الأمر.' }}</p>
                    <flux:textarea wire:model="exceptionDecisionReason" label="سبب قرار اعتماد الاستثناءات" rows="2" placeholder="أدخل مبرر الموافقة على الاستثناءات…" />
                    <flux:button wire:click="approveCommercialExceptions" wire:loading.attr="disabled" icon="shield-check">تسجيل القرار الإضافي</flux:button>
                </div>
            @endif
        </div>
    @endif
</x-erp.section-card>
