@php
    use App\Modules\Sales\Presentation\SalesOrderDisplay as D;
    $screenTitles = ['overview' => 'تفاصيل أمر البيع', 'review' => 'المراجعة والاعتماد', 'amendment' => 'تعديل أمر البيع', 'delivery' => 'التخصيص والتسليم', 'invoices' => 'الفواتير', 'closure' => 'الإغلاق والمرتجعات'];
    $latestReview = $record->reviews->first();
    $amountsData = $record->calculation ?? [];
    $isOfficial = (bool) ($amountsData['official'] ?? false);
    $activeStep = D::stepIndex($record->status, $progress);
    $steps = ['إنشاء مسودة', 'مراجعة مستقلة', 'اعتماد وحجز', 'التسليم', 'الفوترة'];
    $needsException = $record->source_type === 'direct' && $record->status === 'pending_approval' && $commercialReview['exceptions'] !== [] && ! $exceptionDecision;
@endphp
<div data-erp-archetype="details" class="orders-ui space-y-5">
    <x-page.heading :title="$record->label()" :description="($screenTitles[$screen] ?? 'تفاصيل أمر البيع').' · النسخة '.$record->version" :breadcrumbs="['المبيعات', 'أوامر البيع', $record->label()]">
        <x-slot:actions>
            @if($record->editable() && $record->source_type === 'direct' && $can('edit'))
                <flux:button :href="route('sales-orders.edit', ['order' => $record->id])" wire:navigate icon="pencil-square">تعديل المسودة</flux:button>
            @endif
            <flux:button :href="route('sales-orders.index')" wire:navigate icon="arrow-right">أوامر البيع</flux:button>
        </x-slot:actions>
        @if($can('export') || ($record->status === 'approved' && $can('export')))
        <x-slot:secondaryActions>
            @if($record->status === 'approved' && $can('export'))
                <flux:button :href="route('sales-orders.pdf', $record->id)" icon="arrow-down-tray">أمر البيع PDF</flux:button>
            @endif
            @if($can('export'))
                <flux:button wire:click="export" icon="arrow-down-tray">تصدير Excel</flux:button>
            @endif
        </x-slot:secondaryActions>
        @endif
    </x-page.heading>

    @include('sales.orders.partials.feedback')

    @if($stale)
        <flux:callout variant="warning" icon="exclamation-triangle">
            تغيرت نسخة الأمر منذ فتح الصفحة. أعد تحميل البيانات قبل تنفيذ أي إجراء.
            <flux:button size="xs" variant="ghost" wire:click="refresh">إعادة تحميل</flux:button>
        </flux:callout>
    @endif

    <ol class="orders-steps" aria-label="مراحل دورة أمر البيع">
        @foreach($steps as $i => $stepLabel)
            @php
                $stepClass = match (true) {
                    $activeStep === -1 && $i === 0 => 'is-danger',
                    $activeStep > $i => 'is-done',
                    $activeStep === $i => 'is-current',
                    default => '',
                };
            @endphp
            <li class="{{ $stepClass }}">
                <span>{{ $i + 1 }}</span>
                <div>{{ $stepLabel }}@if($activeStep === -1 && $i === 0) (ملغي)@endif</div>
            </li>
        @endforeach
    </ol>

    @if($record->status === 'draft')
        <section class="orders-next-card">
            <div>
                <h2>الإجراء التالي: مراجعة المسودة وتقديمها للاعتماد</h2>
                <p>راجع البنود والأسعار والقوالب المعتمدة، ثم قدّم الأمر للمراجعة لتثبيت النسخة وإتاحتها للاعتماد والحجز.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($record->source_type === 'direct' && $can('edit'))
                    <flux:button :href="route('sales-orders.edit', ['order' => $record->id])" wire:navigate icon="pencil-square">تعديل المسودة</flux:button>
                @endif
                @if($can('submit'))
                    <flux:button variant="primary" wire:click="operate('submit')" wire:loading.attr="disabled" :disabled="$stale" icon="paper-airplane">تقديم للمراجعة</flux:button>
                @endif
            </div>
        </section>
    @elseif($record->status === 'pending_approval')
        <section class="orders-next-card" data-tone="warning">
            <div>
                <h2>الإجراء التالي: {{ ($isReviewRequester && !$canSelfApprove) ? 'بانتظار مراجع مستقل' : ($needsException ? 'قرار الاستثناءات ثم اعتماد الأمر' : 'المراجعة واختيار مستودعات الحجز') }}</h2>
                <p>
                    @if($isReviewRequester && !$canSelfApprove)
                        تم إرسال النسخة {{ $record->version }} للمراجعة. يمنع النظام اعتماد أو رفض المقدم لطلبه؛ يمكنك سحب الطلب إذا احتجت لتعديله.
                    @elseif($isReviewRequester && $canSelfApprove)
                        تم إرسال النسخة {{ $record->version }} للمراجعة. يمكنك الاعتماد الذاتي الموثّق حسب سياسة الدور، أو سحب الطلب للتعديل.
                    @elseif($needsException)
                        يحتوي الأمر {{ count($commercialReview['exceptions']) }} استثناءات تجارية تتطلب قرار مسؤول الاستثناءات أولًا، ثم اختيار مستودع كل بند والاعتماد.
                    @else
                        الأمر جاهز للاعتماد المستقل واختيار مستودع الحجز الفعلي لكل بند.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($can('approve') && (!$isReviewRequester || $canSelfApprove) && $screen !== 'review')
                    <flux:button variant="primary" :href="route('sales-orders.screen', ['order' => $record->id, 'screen' => 'review'])" wire:navigate icon="clipboard-document-check">فتح المراجعة والاعتماد</flux:button>
                @endif
                @if($can('withdraw'))
                    <flux:button wire:click="operate('withdraw')" wire:loading.attr="disabled" icon="arrow-uturn-right">سحب طلب المراجعة</flux:button>
                @endif
            </div>
        </section>
    @elseif($record->status === 'approved')
        <section class="orders-next-card" data-tone="success">
            <div>
                <h2>تم اعتماد أمر البيع برقم {{ $record->number }}</h2>
                <p>الاعتماد والحجز مستقلان عن التسليم الفعلي والفوترة. يمكنك متابعة التخصيص وأذون التسليم أو تحميل أمر البيع PDF الرسمي.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($screen !== 'delivery')
                    <flux:button variant="primary" :href="route('sales-orders.screen', ['order' => $record->id, 'screen' => 'delivery'])" wire:navigate icon="truck">متابعة التسليم والحجز</flux:button>
                @endif
                @if($can('export'))
                    <flux:button :href="route('sales-orders.pdf', $record->id)" icon="arrow-down-tray">أمر البيع PDF</flux:button>
                @endif
            </div>
        </section>
    @elseif($record->status === 'cancelled')
        <section class="orders-next-card" data-tone="danger">
            <div>
                <h2>أمر بيع ملغي (غير منفذ)</h2>
                <p>تم إلغاء هذا الأمر قبل تنفيذه ولا يقبل أي حركات أو تسليمات أو تعديلات.</p>
            </div>
        </section>
    @endif

    <section class="orders-summary" aria-label="ملخص أمر البيع">
        <div>
            <span>العميل</span>
            @if($canViewCustomer)
                <strong><a class="text-blue-700 hover:underline" href="{{ route('customers.show', $record->customer_id) }}" wire:navigate>{{ $record->customer_snapshot['name'] ?? '—' }}</a></strong>
            @else
                <strong>{{ $record->customer_snapshot['name'] ?? '—' }}</strong>
            @endif
        </div>
        <div>
            <span>الفرع</span>
            <strong>{{ $record->branch_snapshot['name'] ?? '—' }}</strong>
        </div>
        <div>
            <span>المصدر / المشروع</span>
            @if($sourceQuotation && $canViewQuotation)
                <strong><a class="text-blue-700 hover:underline" href="{{ route('quotations.show', $sourceQuotation->quotation_id) }}" wire:navigate>{{ $sourceQuotation->number ?? ('عرض #'.$sourceQuotation->quotation_id) }}</a></strong>
            @else
                <strong>{{ D::SOURCES[$record->source_type] ?? $record->source_type }}</strong>
            @endif
            <small>{{ $record->project_name ?: $record->customer_reference ?: 'بدون مرجع إضافي' }}</small>
        </div>
        <div>
            <span>الحالة والتنفيذ</span>
            <div class="mt-1">@include('sales.orders.partials.workflow-statuses', ['record' => $record, 'progress' => $progress, 'compact' => true])</div>
        </div>
        <div>
            <span>{{ $isOfficial ? 'الإجمالي الرسمي شامل الضريبة' : 'الصافي قبل الضرائب' }}</span>
            <strong class="text-base tabular-nums"><bdi>{{ D::money($isOfficial ? ($amountsData['total'] ?? null) : ($amountsData['net'] ?? null)) }} ج.م</bdi></strong>
            @if($isOfficial && ($amountsData['tax'] ?? null) !== null)
                <small>الضريبة: <bdi>{{ D::money($amountsData['tax']) }} ج.م</bdi></small>
            @endif
        </div>
    </section>

    <nav class="orders-tabs" aria-label="أقسام أمر البيع">
        <a @class(['is-active' => $screen === 'overview']) href="{{ route('sales-orders.show', ['order' => $record->id]) }}" wire:navigate>
            <flux:icon name="information-circle" class="size-4" />
            <span>البيانات الرئيسية</span>
            <span class="orders-tabs-count">{{ $record->lines->count() }}</span>
        </a>
        <a @class(['is-active' => $screen === 'review']) href="{{ route('sales-orders.screen', ['order' => $record->id, 'screen' => 'review']) }}" wire:navigate>
            <flux:icon name="clipboard-document-check" class="size-4" />
            <span>المراجعة</span>
            @if($record->status === 'pending_approval')<span class="orders-tabs-dot" aria-hidden="true"></span>@endif
        </a>
        <a @class(['is-active' => $screen === 'delivery']) href="{{ route('sales-orders.screen', ['order' => $record->id, 'screen' => 'delivery']) }}" wire:navigate>
            <flux:icon name="truck" class="size-4" />
            <span>التسليم والحجز</span>
            @if($deliveryInstructions->isNotEmpty())<span class="orders-tabs-count">{{ $deliveryInstructions->count() }}</span>@endif
        </a>
        <a @class(['is-active' => $screen === 'invoices']) href="{{ route('sales-orders.screen', ['order' => $record->id, 'screen' => 'invoices']) }}" wire:navigate>
            <flux:icon name="document-text" class="size-4" />
            <span>الفواتير</span>
            @if(! empty($progress['invoices']))<span class="orders-tabs-count">{{ count($progress['invoices']) }}</span>@endif
        </a>
        <a @class(['is-active' => $screen === 'amendment']) href="{{ route('sales-orders.screen', ['order' => $record->id, 'screen' => 'amendment']) }}" wire:navigate>
            <flux:icon name="pencil-square" class="size-4" />
            <span>التعديل</span>
        </a>
        <a @class(['is-active' => $screen === 'closure']) href="{{ route('sales-orders.screen', ['order' => $record->id, 'screen' => 'closure']) }}" wire:navigate>
            <flux:icon name="archive-box" class="size-4" />
            <span>الإغلاق والمرتجعات</span>
        </a>
    </nav>

    @if($screen === 'overview')
        <div class="grid gap-4 xl:grid-cols-3">
            <x-erp.section-card :title="'بنود أمر البيع ('.$record->lines->count().')'" icon="rectangle-stack" class="xl:col-span-2 !p-0">
                <div class="border-b border-slate-100 px-5 py-2.5 text-xs text-slate-500">
                    {{ $record->status === 'approved' ? 'بنود وأسعار الإصدار الرسمي المعتمد' : 'كميات مطلوبة وأسعار مقترحة للمسودة' }}
                </div>
                <x-ui.responsive-table label="جدول بنود أمر البيع">
                    <table role="table" class="w-full min-w-[820px] text-sm">
                        <thead role="rowgroup" class="text-end text-xs text-slate-600">
                            <tr role="row">
                                <th role="columnheader" scope="col" class="px-3 py-2.5">#</th>
                                <th role="columnheader" scope="col" class="px-3 py-2.5">الصنف</th>
                                <th role="columnheader" scope="col" class="px-3 py-2.5">الوحدة</th>
                                <th role="columnheader" scope="col" class="px-3 py-2.5">الكمية</th>
                                <th role="columnheader" scope="col" class="px-3 py-2.5">السعر المرجعي</th>
                                <th role="columnheader" scope="col" class="px-3 py-2.5">{{ $record->status === 'approved' ? 'السعر المعتمد' : 'السعر المقترح' }}</th>
                                <th role="columnheader" scope="col" class="px-3 py-2.5">الخصم</th>
                                <th role="columnheader" scope="col" class="px-3 py-2.5">صافي البند</th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup" class="divide-y divide-slate-100">
                            @foreach($record->lines as $index => $line)
                                @php
                                    $unitPrice = $line->requested_price ?? $line->suggested_price;
                                    $hasDisc = bccomp((string) $line->discount_value, '0', 8) > 0;
                                @endphp
                                <tr role="row" wire:key="so-line-{{ $line->id }}" class="hover:bg-slate-50/70">
                                    <td role="cell" data-label="#" class="px-3 py-2.5 tabular-nums">{{ $index + 1 }}</td>
                                    <td role="cell" data-label="الصنف" class="px-3 py-2.5">
                                        <span class="font-medium text-slate-900">{{ $line->description }}</span>
                                        <span class="mt-0.5 block text-[11px] text-slate-500"><bdi>{{ $line->product_snapshot['sku'] ?? '—' }}</bdi></span>
                                        @php
                                            $specChips = array_filter([
                                                $line->spec_model ? 'الطراز: '.$line->spec_model : null,
                                                $line->spec_type ? 'النوع: '.$line->spec_type : null,
                                                $line->spec_ampere ? 'الأمبير: '.$line->spec_ampere : null,
                                                $line->spec_kilo ? 'الكيلو: '.$line->spec_kilo : null,
                                            ]);
                                        @endphp
                                        @if($specChips !== [])
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach($specChips as $chip)
                                                    <span class="orders-badge" data-size="sm" data-tone="neutral">{{ $chip }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if(!empty($line->serial_reference))
                                            <span class="mt-1 block text-[11px] text-blue-800">السيريال: <bdi>{{ $line->serial_reference }}</bdi></span>
                                        @endif
                                        @if(!empty($line->accessories_note))
                                            <span class="mt-0.5 block text-[11px] text-slate-600">الملحقات: {{ $line->accessories_note }}</span>
                                        @endif
                                        @if($line->override_reason)
                                            <span class="mt-0.5 block text-[11px] text-amber-700">سبب السعر: {{ $line->override_reason }}</span>
                                        @endif
                                    </td>
                                    <td role="cell" data-label="الوحدة" class="px-3 py-2.5">{{ $line->product_snapshot['unit_name'] ?? '—' }}</td>
                                    <td role="cell" data-label="الكمية" class="px-3 py-2.5 tabular-nums"><bdi>{{ D::qty((string) $line->quantity) }}</bdi></td>
                                    <td role="cell" data-label="السعر المرجعي" class="px-3 py-2.5 tabular-nums text-slate-600">
                                        <bdi>{{ $line->suggested_price === null ? '—' : D::money((string) $line->suggested_price).' ج.م' }}</bdi>
                                    </td>
                                    <td role="cell" data-label="{{ $record->status === 'approved' ? 'السعر المعتمد' : 'السعر المقترح' }}" class="px-3 py-2.5 tabular-nums">
                                        <bdi>{{ $unitPrice === null ? 'غير محدد' : D::money((string) $unitPrice).' ج.م' }}</bdi>
                                    </td>
                                    <td role="cell" data-label="الخصم" class="px-3 py-2.5 tabular-nums text-slate-600">
                                        @if($hasDisc)
                                            <bdi>{{ $line->discount_mode === 'percent' ? D::qty((string) $line->discount_value).'%' : D::money((string) $line->discount_value).' ج.م' }}</bdi>
                                        @else
                                            <span>—</span>
                                        @endif
                                    </td>
                                    <td role="cell" data-label="صافي البند" class="px-3 py-2.5 font-semibold tabular-nums">
                                        <bdi>{{ D::money($amountsData['lines'][$index]['net'] ?? null) }} ج.م</bdi>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.responsive-table>
            </x-erp.section-card>

            <x-erp.section-card :title="$isOfficial ? 'الملخص المالي الرسمي' : 'ملخص الأسعار'" icon="banknotes">
                @include('sales.orders.partials.amounts', ['amounts' => $amountsData])
                @if($orderIssue)
                    <dl class="mt-4 space-y-2 border-t border-slate-200 pt-3 text-xs">
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-500">المُعتمد</dt>
                            <dd class="font-medium">{{ \App\Modules\Organization\Presentation\UserNames::name((int) $orderIssue->approved_by) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-500">وقت الاعتماد</dt>
                            <dd class="tabular-nums"><bdi>{{ \Carbon\CarbonImmutable::parse($orderIssue->approved_at)->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</bdi></dd>
                        </div>
                    </dl>
                @endif
            </x-erp.section-card>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <x-erp.section-card title="معلومات الأمر" icon="document-text">
                <dl class="space-y-2.5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">تاريخ الأمر</dt><dd class="tabular-nums"><bdi>{{ $record->order_date->format('Y/m/d') }}</bdi></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">التسليم المطلوب</dt><dd class="tabular-nums"><bdi>{{ $record->requested_delivery_date?->format('Y/m/d') ?? 'غير محدد' }}</bdi></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">مرجع العميل</dt><dd>{{ $record->customer_reference ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">العملة</dt><dd>ج.م (جنيه مصري)</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">رقم النسخة</dt><dd class="tabular-nums">{{ $record->version }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">المعاملة الضريبية</dt><dd>{{ \App\Modules\Accounting\Application\TaxCalculator::treatmentLabel($record->tax_treatment ?? 'rule') }}</dd></div>
                    @if(($record->tax_treatment ?? 'rule') === 'exempt_0' && $record->tax_exempt_reason)
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">سبب الإعفاء</dt><dd>{{ $record->tax_exempt_reason }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">أنشأه</dt><dd>{{ $record->created_by ? \App\Modules\Organization\Presentation\UserNames::name($record->created_by) : 'غير محدد' }}</dd></div>
                </dl>
            </x-erp.section-card>

            <x-erp.section-card :title="$record->status === 'approved' ? 'الشروط التجارية المعتمدة' : 'الشروط المقترحة'" icon="clipboard-document-list">
                <dl class="space-y-4 text-sm">
                    <div><dt class="text-slate-500">شروط الدفع</dt><dd class="mt-1 whitespace-pre-line">{{ $record->payment_terms ?: 'لم تحدد' }}</dd></div>
                    <div><dt class="text-slate-500">شروط التسليم</dt><dd class="mt-1 whitespace-pre-line">{{ $record->delivery_terms ?: 'لم تحدد' }}</dd></div>
                </dl>
                @if($record->status !== 'approved')
                    <p class="mt-3 text-xs text-amber-800">شروط مسودة غير معتمدة بعد.</p>
                @endif
            </x-erp.section-card>

            <x-erp.section-card title="ملاحظات داخلية" icon="document-text">
                <p class="whitespace-pre-line text-sm">{{ $record->notes ?: 'لا توجد ملاحظات.' }}</p>
                <p class="mt-4 text-xs text-slate-500">تم الإنشاء <bdi>{{ $record->created_at->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</bdi></p>
            </x-erp.section-card>
        </div>

        @if($can('history'))
            <x-erp.section-card title="سجل النشاط والمراجعة" icon="clock">
                <ol class="space-y-3">
                    @forelse($history as $entry)
                        <li class="flex gap-3 border-r-2 border-blue-200 pe-3 text-sm">
                            <div>
                                <strong class="text-slate-900">{{ D::historyAction($entry['action'] ?? null) }}</strong>
                                @if(! empty($entry['reason']))
                                    <p class="mt-0.5 text-xs text-slate-600">{{ $entry['reason'] }}</p>
                                @endif
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ !empty($entry['actor_id']) ? \App\Modules\Organization\Presentation\UserNames::name($entry['actor_id']) : 'مصدر محلي' }}
                                    · <bdi>{{ \Carbon\CarbonImmutable::parse($entry['occurred_at'])->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</bdi>
                                </p>
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">لا توجد أحداث مسجلة.</li>
                    @endforelse
                </ol>
            </x-erp.section-card>
        @endif

        @if($can('cancel') && in_array($record->status, ['draft', 'pending_approval'], true))
            <x-ui.panel class="border-red-200">
                <details>
                    <summary class="cursor-pointer font-semibold text-red-700">إلغاء أمر البيع غير المنفذ</summary>
                    <form wire:submit="cancel" wire:confirm="هل أنت متأكد من إلغاء أمر البيع؟ لا يمكن التراجع عن هذا الإجراء." class="mt-4 flex flex-wrap items-end gap-3">
                        <div class="min-w-64 flex-1"><flux:input wire:model="cancelReason" label="سبب الإلغاء" required minlength="3" /></div>
                        <flux:button type="submit" variant="danger" wire:loading.attr="disabled">تأكيد الإلغاء</flux:button>
                    </form>
                </details>
            </x-ui.panel>
        @endif
    @elseif($screen === 'review')
        <div class="grid gap-4 xl:grid-cols-2">
            <x-erp.section-card title="حالة المراجعة" icon="clock">
                <div class="flex flex-wrap items-center gap-3">
                    @include('sales.orders.partials.status', ['status' => $record->status])
                    @if($latestReview)
                        <span class="text-sm text-slate-600">مراجعة النسخة {{ $latestReview->target_version }} · {{ ['pending' => 'مسجلة للمراجعة', 'approved' => 'معتمدة', 'withdrawn' => 'مسحوبة', 'rejected' => 'مرفوضة للتعديل'][$latestReview->status] ?? $latestReview->status }}</span>
                    @else
                        <span class="text-sm text-slate-500">لا يوجد سجل مراجعة بعد.</span>
                    @endif
                </div>
                <p class="mt-4 text-sm leading-6 text-slate-600">تقديم الأمر يثبت النسخة المطلوب مراجعتها. الاعتماد من مستخدم آخر بعد التحقق من الأسعار والشروط والضريبة ومستودعات الحجز.</p>
                @if($record->status === 'draft' && $can('submit'))
                    <flux:button class="mt-4" variant="primary" wire:click="operate('submit')" wire:loading.attr="disabled" icon="paper-airplane">تقديم للمراجعة</flux:button>
                @elseif($record->status === 'pending_approval' && $can('withdraw'))
                    <flux:button class="mt-4" wire:click="operate('withdraw')" wire:loading.attr="disabled" icon="arrow-uturn-right">سحب طلب المراجعة</flux:button>
                @endif
                @if($record->status === 'pending_approval' && $can('reject'))
                    @if(!$isReviewRequester)
                        <details class="mt-4 rounded-lg border border-amber-200 bg-amber-50/60 p-3">
                            <summary class="cursor-pointer font-semibold text-amber-900">رفض المراجعة وإعادة الأمر للمسودة</summary>
                            <form wire:submit="operate('reject')" wire:confirm="هل أنت متأكد من رفض المراجعة وإعادة الأمر للمسودة؟" class="mt-3 flex flex-wrap items-end gap-3">
                                <div class="min-w-64 flex-1"><flux:input wire:model="rejectReason" label="سبب الرفض" required maxlength="2000" /></div>
                                <flux:button type="submit" variant="danger" wire:loading.attr="disabled">تأكيد الرفض</flux:button>
                            </form>
                        </details>
                    @else
                        <p class="mt-4 text-sm text-slate-500">لا يصدر مقدم طلب المراجعة قرارًا على طلبه.</p>
                    @endif
                @endif
            </x-erp.section-card>
            @include('sales.orders.partials.approval-action')
        </div>
    @elseif($screen === 'amendment')
        <x-erp.section-card title="تعديل الأمر" icon="pencil-square">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <p class="max-w-3xl text-sm leading-6 text-slate-600">المسودة المباشرة قابلة للتحرير من شاشة التعديل. إنشاء تعديل لنسخة مقدمة أو معتمدة يتطلب عقدًا مستقلًا يحفظ النسخ والمراجعات.</p>
                @if($record->editable() && $record->source_type === 'direct' && $can('edit'))
                    <flux:button variant="primary" :href="route('sales-orders.edit', ['order' => $record->id])" wire:navigate icon="pencil-square">تعديل المسودة الحالية</flux:button>
                @else
                    <flux:button disabled icon="pencil-square">إنشاء تعديل</flux:button>
                @endif
            </div>
            @unless($record->editable())
                <ul class="mt-4 list-inside list-disc space-y-2 text-sm text-amber-900">
                    @foreach($blockers as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            @endunless
        </x-erp.section-card>
    @elseif($screen === 'delivery')
        @include('sales.orders.partials.delivery-action')
    @elseif($screen === 'invoices')
        <x-erp.section-card title="الفواتير المرتبطة بأمر البيع" icon="document-text">
            @if($progress['invoicing'] === 'restricted')
                <p class="text-sm text-slate-600">عرض فواتير هذا الأمر يتطلب صلاحية عرض فواتير العملاء.</p>
            @elseif($progress['invoices'] === [])
                <x-ui.empty-state title="لا توجد فواتير مرتبطة بعد" description="تظهر الفواتير المرتبطة ببنود هذا الأمر فور إنشائها من التسليمات المؤكدة." icon="document-text" />
            @else
                <ul class="space-y-3">
                    @foreach($progress['invoices'] as $invoice)
                        <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 px-4 py-3">
                            <div>
                                <p class="font-semibold text-slate-900">{{ $invoice['number'] ?: 'فاتورة قيد الإعداد #'.$invoice['id'] }}</p>
                                <p class="mt-0.5 text-sm text-slate-600 tabular-nums">
                                    <bdi>{{ $invoice['invoice_date'] }}</bdi> · {{ ['draft' => 'مسودة', 'validated' => 'تم التحقق', 'issued' => 'صادرة', 'posted' => 'مرحلة', 'partially_collected' => 'محصلة جزئيًا', 'collected' => 'محصلة', 'credited' => 'لها إشعار دائن'][$invoice['status']] ?? $invoice['status'] }} · <strong><bdi>{{ D::money((string) $invoice['total_amount']) }} ج.م</bdi></strong>
                                </p>
                            </div>
                            <flux:button size="sm" :href="route('financial.invoices.show', ['type' => 'customer', 'invoice' => $invoice['id']])" wire:navigate>عرض الفاتورة</flux:button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-erp.section-card>
    @elseif($screen === 'closure')
        <div class="grid gap-4 xl:grid-cols-3">
            <x-erp.section-card title="إغلاق أمر البيع" icon="archive-box">
                <p class="text-sm text-slate-600">الإغلاق ينتظر اكتمال الاعتماد والتسليم والفوترة والتسوية التشغيلية.</p>
                <ul class="mt-4 list-inside list-disc space-y-2 text-sm text-amber-900">
                    @foreach($dependencyBlockers['close'] ?? [] as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
                <flux:button class="mt-4" disabled icon="archive-box">إغلاق الأمر</flux:button>
            </x-erp.section-card>

            <x-erp.section-card title="مرتجعات البيع" icon="arrow-uturn-left">
                <p class="text-sm text-slate-600">تُنشأ المرتجعات المستقلة من شاشة مرتجعات البيع للتسليمات المؤكدة والمفوترة.</p>
                @if($canViewReturns)
                    <flux:button class="mt-4" variant="primary" :href="route('sales-returns.index')" wire:navigate icon="arrow-uturn-left">فتح مرتجعات البيع</flux:button>
                @else
                    <p class="mt-4 text-xs text-slate-500">عرض أو إنشاء المرتجعات يتطلب صلاحية مرتجعات البيع.</p>
                @endif
            </x-erp.section-card>

            <x-erp.section-card title="إلغاء أمر البيع" icon="x-circle">
                <p class="text-sm text-slate-600">إلغاء المسودة أو الأمر غير المنفذ يتطلب سببًا موثقًا من البيانات الرئيسية.</p>
                @if($can('cancel') && in_array($record->status, ['draft', 'pending_approval'], true))
                    <flux:button class="mt-4" :href="route('sales-orders.show', ['order' => $record->id])" wire:navigate icon="arrow-right">فتح بيانات الإلغاء</flux:button>
                @elseif($record->status === 'cancelled')
                    <p class="mt-4 text-sm font-medium text-slate-500">تم إلغاء هذا الأمر.</p>
                @else
                    <p class="mt-4 text-xs text-slate-500">الأمر المعتمد لا يُلغى مباشرة عبر إلغاء المسودات.</p>
                @endif
            </x-erp.section-card>
        </div>
    @endif
</div>
