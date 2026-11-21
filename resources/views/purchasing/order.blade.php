@php
    use App\Modules\Purchasing\Presentation\PurchaseOrderDisplay as D;
    $titleText = $doc ? 'أمر الشراء '.($officialIssue?->official_number ?? '#'.$doc->id) : 'أمر شراء جديد';
    $recStatus = $receiving?->receiving_status ?? 'not_received';
    $activeStep = $doc ? D::stepIndex($doc->status, (bool) $officialIssue, $recStatus) : 0;
    $steps = ['مسودة', 'مراجعة واعتماد', 'إصدار رسمي', 'استلام مخزني', 'مُستلم بالكامل'];
    if ($doc) {
        [$statusLabel, $statusTone] = D::status($doc->status);
        [$recLabel, $recTone] = D::receiving($recStatus);
        $latestRejection = $doc->status === 'rejected' ? $history->firstWhere('action', 'reject') : null;
    }
@endphp
<div data-erp-archetype="{{ $isEditing ? 'form' : 'details' }}" class="orders-ui space-y-5">
    <x-page.heading :title="$titleText" :description="$doc ? ('المورد: '.$doc->supplier_name.' · النسخة '.$doc->version) : 'إعداد أمر شراء جديد مباشرة أو من ترسية معتمدة'" :breadcrumbs="$doc ? ['المشتريات', 'أوامر الشراء', $officialIssue?->official_number ?? '#'.$doc->id] : ['المشتريات', 'أوامر الشراء', 'أمر شراء جديد']">
        <x-slot:actions>
            @if($doc && !$isEditing && $editable)
                <flux:button type="button" wire:click="startEdit" icon="pencil-square">تعديل المسودة</flux:button>
            @elseif($doc && $isEditing)
                <flux:button type="button" wire:click="cancelEdit" icon="x-mark">إلغاء التعديل</flux:button>
            @endif
            @if(!$doc || $isEditing)
                <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save" icon="bookmark-square">حفظ أمر الشراء</flux:button>
            @endif
            <flux:button :href="route('purchasing.orders.index')" wire:navigate icon="arrow-right">أوامر الشراء</flux:button>
        </x-slot:actions>
        @if($doc && ($officialIssue || $permissions['return_view'] || $permissions['accounting_setup']))
        <x-slot:secondaryActions>
            @if($officialIssue)
                <flux:button :href="route('purchasing.orders.pdf', $doc->id)" icon="arrow-down-tray">أمر الشراء الرسمي PDF</flux:button>
            @endif
            @if($permissions['return_view'])
                <flux:button :href="route('purchasing.returns')" wire:navigate icon="arrow-uturn-left">مرتجعات المشتريات</flux:button>
            @endif
            @if($permissions['accounting_setup'])
                <flux:button :href="route('accounting.setup')" wire:navigate icon="cog-6-tooth">إعداد التشغيل المالي</flux:button>
            @endif
        </x-slot:secondaryActions>
        @endif
    </x-page.heading>

    @if(session('status'))
        <flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>
    @endif
    <x-ui.validation-summary />

    @if($isEditing)
        @if(! empty($form['award_id']))
            <flux:callout variant="info" icon="lock-closed">
                هذا الأمر مرتبط بترسية معتمدة؛ الأصناف والكميات والأسعار والعملة والشروط يجب أن تطابق عرض المورد المُرسى عليه.
            </flux:callout>
        @endif

        @if(empty($form['award_id']))
            <x-ui.panel>
                <details>
                    <summary class="erp-import-summary">
                        <flux:icon.arrow-up-tray class="size-5" />
                        <span>استيراد البنود من Excel (اختياري)</span>
                    </summary>
                    <div class="mt-4 space-y-4">
                        <p class="erp-form-hint">اختر الفرع أولًا، ثم نزّل القالب الرسمي وارفعه للمعاينة. تطبيق البنود يستبدل قائمة البنود الحالية في النموذج دون حفظ تلقائي.</p>
                        <div class="flex flex-wrap items-end gap-3">
                            <flux:button type="button" wire:click="lineTemplate" icon="arrow-down-tray">تنزيل القالب</flux:button>
                            <flux:input type="file" wire:model="lineFile" accept=".xlsx" label="ملف البنود (.xlsx)" />
                            <flux:button type="button" wire:click="previewLines" wire:loading.attr="disabled">التحقق والمعاينة</flux:button>
                        </div>
                        @if($linePreview)
                            <div class="max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 p-3">
                                @foreach($linePreview as $r)
                                    <p class="py-2 text-sm {{ $r['error'] ? 'text-red-700' : 'text-teal-800' }}">
                                        صف {{ $r['row'] }} · {{ $r['error'] ?? ($r['name'].' · كمية '.D::qty((string) $r['quantity']).' · سعر '.D::money((string) $r['unit_price'])) }}
                                    </p>
                                @endforeach
                            </div>
                            <flux:button type="button" variant="primary" wire:click="applyLines" wire:loading.attr="disabled">تطبيق البنود واستبدال الحالي (دون حفظ)</flux:button>
                        @endif
                    </div>
                </details>
            </x-ui.panel>
        @endif

        <form wire:submit="save" class="erp-document-form space-y-5">
            <x-erp.section-card title="بيانات أمر الشراء" icon="document-text">
                <div class="erp-form-grid">
                    <flux:select wire:model="form.branch_id" label="الفرع" required>
                        <option value="">اختر الفرع</option>
                        @foreach($branches as $b)
                            <option value="{{ $b['id'] }}">{{ $b['name'] }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="form.supplier_id" label="المورد" search-model="supplierSearch" required>
                        @if($doc)<option value="{{ $doc->supplier_id }}">{{ $doc->supplier_name }}</option>@endif
                        <option value="">اختر المورد</option>
                        @foreach($suppliers as $s)
                            <option value="{{ $s->id }}">{{ $s->code }} · {{ $s->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:input value="جنيه مصري (EGP)" label="العملة" disabled />
                    <flux:input type="date" wire:model="form.order_date" label="تاريخ الأمر" required />
                    <flux:input type="date" wire:model="form.expected_date" label="موعد التوريد المتوقع" />
                    <div>
                        <flux:select wire:model="form.award_id" label="الترسية المعتمدة (اختياري)">
                            <option value="">شراء مباشر دون ترسية</option>
                            @foreach($awards as $a)
                                <option value="{{ $a->id }}">ترسية #{{ $a->id }} · عرض #{{ $a->offer_id }} — {{ $a->supplier_name ?? '' }} ({{ D::money($a->offer_net ?? null) }} {{ D::currencyLabel($a->offer_currency ?? 'EGP') }})</option>
                            @endforeach
                        </flux:select>
                        <flux:button type="button" class="mt-2" size="sm" wire:click="loadAward" x-bind:disabled="!$wire.form.award_id">تحميل بنود الترسية</flux:button>
                    </div>
                </div>
            </x-erp.section-card>

            <x-erp.section-card title="الضريبة والشروط والملاحظات" icon="clipboard-document-list">
                <div class="erp-form-grid">
                    <flux:select wire:model="form.tax_mode" label="أساس الأسعار للضريبة">
                        <option value="exclusive">غير شامل الضريبة</option>
                        <option value="inclusive">شامل الضريبة</option>
                    </flux:select>
                    <flux:input wire:model.live.debounce.400ms="form.reported_tax" label="الضريبة المذكورة بعرض المورد (للمقارنة)" description="تُقارن مع القاعدة الضريبية الرسمية عند الإصدار." inputmode="decimal" />
                    <flux:textarea rows="3" wire:model="form.payment_terms" label="شروط الدفع" maxlength="3000" />
                    <flux:textarea field:class="erp-field-wide" rows="3" wire:model="form.notes" label="ملاحظات الأمر" maxlength="5000" />
                </div>
            </x-erp.section-card>

            <x-erp.section-card :title="'بنود أمر الشراء ('.count($form['lines']).')'" icon="rectangle-stack">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-slate-500">تُسجل الكميات بالوحدة الأساسية للصنف. يُحسب صافي البند وإجمالي الأمر لحظيًا بدون تقريب.</p>
                    @if(empty($form['award_id']))
                        <flux:button type="button" variant="primary" size="sm" icon="plus" wire:click="addLine">إضافة بند</flux:button>
                    @endif
                </div>

                <div class="space-y-4">
                    @forelse($form['lines'] as $i => $line)
                        @php
                            $lineNet = $draftCalc['lines'][$i]['net'] ?? null;
                            $selectedCatId = !empty($line['category_id']) ? (int) $line['category_id'] : null;
                            $selectedBrandId = !empty($line['brand_id']) ? (int) $line['brand_id'] : null;
                            $childCatIds = $selectedCatId ? collect($categories ?? [])->where('parent_id', $selectedCatId)->pluck('id')->all() : [];
                            $allowedCatIds = $selectedCatId ? array_merge([$selectedCatId], $childCatIds) : [];
                            $lineProducts = collect($products)->filter(function ($p) use ($allowedCatIds, $selectedBrandId, $line) {
                                if ((int) ($line['product_id'] ?? 0) === (int) $p['id']) {
                                    return true;
                                }
                                if ($allowedCatIds !== [] && !in_array((int) ($p['category_id'] ?? 0), $allowedCatIds, true)) {
                                    return false;
                                }
                                if ($selectedBrandId !== null && (int) ($p['brand_id'] ?? 0) !== $selectedBrandId) {
                                    return false;
                                }
                                return true;
                            })->values();
                        @endphp
                        <fieldset wire:key="poline-{{ $i }}" class="orders-line-card">
                            <legend class="sr-only">البند {{ $i + 1 }}</legend>
                            <div class="orders-line-header">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-bold text-slate-900">البند {{ $i + 1 }}</h3>
                                    @if(!empty($line['spec_model']))
                                        <span class="orders-badge" data-size="sm" data-tone="neutral"><bdi>{{ $line['spec_model'] }}</bdi></span>
                                    @endif
                                    @if(!empty($line['spec_ampere']))
                                        <span class="orders-badge" data-size="sm" data-tone="info"><bdi>{{ $line['spec_ampere'] }}</bdi></span>
                                    @endif
                                    @if(!empty($line['spec_kilo']))
                                        <span class="orders-badge" data-size="sm" data-tone="info"><bdi>{{ $line['spec_kilo'] }}</bdi></span>
                                    @endif
                                </div>
                                @if(empty($form['award_id']))
                                    <div class="flex items-center gap-1">
                                        <flux:button type="button" size="xs" variant="ghost" icon="arrow-up" wire:click="moveLine({{ $i }}, -1)" :disabled="$loop->first" aria-label="تحريك البند للأعلى" />
                                        <flux:button type="button" size="xs" variant="ghost" icon="arrow-down" wire:click="moveLine({{ $i }}, 1)" :disabled="$loop->last" aria-label="تحريك البند للأسفل" />
                                        <flux:button type="button" size="xs" variant="ghost" icon="document-duplicate" wire:click="duplicateLine({{ $i }})">نسخ</flux:button>
                                        <flux:button type="button" size="xs" variant="ghost" icon="trash" wire:click="removeLine({{ $i }})" :disabled="count($form['lines']) <= 1" aria-label="حذف البند">حذف</flux:button>
                                    </div>
                                @endif
                            </div>

                            <div class="mb-3 rounded-xl border border-blue-100/90 bg-blue-50/35 p-3">
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-xs font-bold text-blue-950">1. مسار اختيار الصنف والمواصفات الفنية (التصنيف ← الماركة ← الطراز والنوع ← الأمبير ← أمر الكيلو)</span>
                                    <span class="text-[11px] text-slate-500">قابلة للتعديل في أي وقت من البداية</span>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <flux:select label="التصنيف (مثل: قواطع / كابلات)" wire:model.live="form.lines.{{ $i }}.category_id" :disabled="!empty($form['award_id'])">
                                        <option value="">كل التصنيفات</option>
                                        @foreach($categories ?? [] as $cat)
                                            <option value="{{ $cat['id'] }}">{{ $cat['name'] }}</option>
                                        @endforeach
                                    </flux:select>
                                    <flux:select label="الماركة / العلامة التجارية" wire:model.live="form.lines.{{ $i }}.brand_id" :disabled="!empty($form['award_id'])">
                                        <option value="">كل الماركات</option>
                                        @foreach($brands ?? [] as $br)
                                            <option value="{{ $br['id'] }}">{{ $br['name'] }}</option>
                                        @endforeach
                                    </flux:select>
                                    <flux:select label="اختيار الصنف" search-model="search" wire:model.live="form.lines.{{ $i }}.product_id" :disabled="!empty($form['award_id'])" required>
                                        <option value="">اختر الصنف ({{ $lineProducts->count() }} متاح)</option>
                                        @foreach($lineProducts as $p)
                                            <option value="{{ $p['id'] }}">#{{ $p['id'] }} · {{ $p['sku'] }} — {{ $p['name'] }}</option>
                                        @endforeach
                                    </flux:select>
                                </div>

                                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <flux:input wire:model="form.lines.{{ $i }}.spec_model" label="الطرازات / الموديل" placeholder="مثال: MCCB-3P-100A" maxlength="200" />
                                    <flux:input wire:model="form.lines.{{ $i }}.spec_type" label="النوع / الأقطاب" placeholder="مثال: ثلاثي 3P / هوائي / حراري" maxlength="200" />
                                    <flux:input wire:model="form.lines.{{ $i }}.spec_ampere" label="الأمبير" placeholder="مثال: 100 A" maxlength="120" />
                                    <flux:input wire:model="form.lines.{{ $i }}.spec_kilo" label="أمر الكيلو (kA / kW)" placeholder="مثال: 36 kA أو 15 kW" maxlength="120" />
                                </div>
                            </div>

                            <div class="orders-line-grid">
                                <flux:input wire:model.live.debounce.400ms="form.lines.{{ $i }}.quantity" label="الكمية (بالوحدة الأساسية)" inputmode="decimal" :readonly="!empty($form['award_id'])" required />
                                <flux:input wire:model.live.debounce.400ms="form.lines.{{ $i }}.unit_price" label="سعر الوحدة (ج.م)" inputmode="decimal" :readonly="!empty($form['award_id'])" required />
                                <flux:input wire:model.live.debounce.400ms="form.lines.{{ $i }}.discount_amount" label="خصم البند (ج.م)" inputmode="decimal" :readonly="!empty($form['award_id'])" required />
                            </div>

                            <div class="mt-3 grid gap-3 md:grid-cols-2 rounded-xl border border-slate-200/80 bg-slate-50/70 p-3">
                                <flux:textarea rows="2" wire:model="form.lines.{{ $i }}.serial_reference" label="تسجيل السيريال (اختياري)" placeholder="أدخل السيريال أو رقم لكل سطر (ينتقل تلقائيًا لمسودة الاستلام عند التوريد)…" />
                                <flux:textarea rows="2" wire:model="form.lines.{{ $i }}.accessories_note" label="تسجيل الملحقات (اختياري)" placeholder="مثال: نقاط مساعدة، ملف فصل Shunt Trip، يد تشغيل، كوفريه…" />
                            </div>

                            <div class="erp-order-line-summary">
                                <p>الإجمالي قبل الخصم: <bdi>{{ D::money($draftCalc['lines'][$i]['gross'] ?? null, '—') }} ج.م</bdi></p>
                                <p>صافي البند: <strong><bdi>{{ D::money($lineNet, '—') }} ج.م</bdi></strong></p>
                            </div>
                        </fieldset>
                    @empty
                        <x-ui.empty-state title="لا توجد بنود مضافة" description="أضف الأصناف المطلوبة يدويًا، أو حمّل الترسية المعتمدة أو استورد البنود من Excel." icon="rectangle-stack" />
                    @endforelse
                </div>
            </x-erp.section-card>

            <x-erp.section-card title="ملخص المسودة المالي" icon="banknotes">
                <dl class="grid gap-4 sm:grid-cols-4 text-sm">
                    <div>
                        <dt class="text-slate-500">الإجمالي قبل الخصم</dt>
                        <dd class="mt-1 font-semibold tabular-nums"><bdi>{{ D::money($draftCalc['gross'], '—') }} ج.م</bdi></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">إجمالي خصومات البنود</dt>
                        <dd class="mt-1 font-semibold tabular-nums"><bdi>{{ D::money($draftCalc['discounts'], '—') }} ج.م</bdi></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">صافي الأمر</dt>
                        <dd class="mt-1 text-lg font-bold text-slate-900 tabular-nums"><bdi>{{ D::money($draftCalc['net'], '—') }} ج.م</bdi></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">الضريبة المذكورة للمقارنة</dt>
                        <dd class="mt-1 font-semibold tabular-nums"><bdi>{{ ($form['reported_tax'] ?? '') !== '' ? D::money((string) $form['reported_tax']).' ج.م' : 'غير مذكورة' }}</bdi></dd>
                    </div>
                </dl>
            </x-erp.section-card>

            <div class="erp-form-footer">
                <div class="flex flex-wrap items-center gap-3 text-xs">
                    <span class="text-slate-600">البنود: <strong>{{ count($form['lines']) }}</strong></span>
                    <span class="text-slate-600">الصافي: <strong class="text-sm text-slate-900 tabular-nums"><bdi>{{ D::money($draftCalc['net'], '—') }}</bdi> ج.م</strong></span>
                    <span class="text-slate-500">الحفظ يجهز المسودة؛ التقديم للاعتماد يتم من شاشة التفاصيل.</span>
                </div>
                <flux:button type="submit" variant="primary" icon="bookmark-square" wire:loading.attr="disabled" wire:target="save">حفظ أمر الشراء</flux:button>
            </div>
        </form>
    @elseif($doc)
        <ol class="orders-steps" aria-label="مراحل دورة أمر الشراء">
            @foreach($steps as $i => $stepLabel)
                @php
                    $stepClass = match (true) {
                        $activeStep === -1 && $i === 1 => 'is-danger',
                        $activeStep > $i => 'is-done',
                        $activeStep === $i && $recStatus === 'received' && $i === 4 => 'is-done',
                        $activeStep === $i => 'is-current',
                        default => '',
                    };
                @endphp
                <li class="{{ $stepClass }}">
                    <span>{{ $i + 1 }}</span>
                    <div>{{ $stepLabel }}@if($activeStep === -1 && $i === 1) (مرفوض)@endif</div>
                </li>
            @endforeach
        </ol>

        @if($doc->status === 'draft')
            <section class="orders-next-card">
                <div>
                    <h2>الإجراء التالي: {{ $permissions['submit'] ? 'إرسال الأمر للاعتماد الداخلي' : 'مسودة قيد الإعداد' }}</h2>
                    <p>{{ $doc->created_by === auth()->id() ? 'راجع الكميات والأسعار وشروط التوريد، ثم أرسل الأمر لمراجع مستقل لاعتماده.' : 'أعدّ هذا الأمر مستخدم آخر. يظل الإرسال للاعتماد والتعديل للمعدّ وفق صلاحياته.' }}</p>
                </div>
                @if($editable || $permissions['submit'])
                    <div class="flex flex-wrap gap-2">
                        @if($editable)
                            <flux:button type="button" wire:click="startEdit" icon="pencil-square">تعديل المسودة</flux:button>
                        @endif
                        @if($permissions['submit'])
                            <flux:button type="button" variant="primary" icon="paper-airplane" wire:click="applyAction('submit')" wire:loading.attr="disabled">إرسال للاعتماد</flux:button>
                        @endif
                    </div>
                @endif
            </section>
        @elseif($doc->status === 'submitted')
            <section class="orders-next-card" data-tone="warning">
                <div>
                    <h2>الإجراء التالي: {{ $permissions['approve'] ? 'قرار الاعتماد أو الرفض' : 'بانتظار مراجع مستقل' }}</h2>
                    <p>{{ $doc->created_by === auth()->id() && !$permissions['approve'] ? 'الاعتماد الذاتي ممنوع؛ بانتظار قرار مستخدم آخر يمتلك صلاحية اعتماد أوامر الشراء.' : ($doc->created_by === auth()->id() ? 'اعتماد ذاتي موثّق حسب سياسة الدور — راجع بيانات الأمر ثم أصدر قرار الاعتماد مع تسجيله في التدقيق.' : 'راجع بيانات الأمر والأسعار بالأسفل ثم أصدر قرار الاعتماد أو الرفض مع ذكر السبب عند الرفض.') }}</p>
                </div>
                @if($permissions['approve'])
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-56">
                            <flux:input wire:model="reason" label="ملاحظة / سبب الرفض" placeholder="إلزامي في حالة الرفض…" />
                        </div>
                        <flux:button type="button" variant="primary" icon="check" wire:click="applyAction('approve')" wire:loading.attr="disabled">اعتماد داخلي</flux:button>
                        <flux:button type="button" variant="danger" icon="x-mark" wire:click="applyAction('reject')" wire:confirm="هل أنت متأكد من رفض أمر الشراء؟" wire:loading.attr="disabled">رفض</flux:button>
                    </div>
                @endif
            </section>
        @elseif($doc->status === 'rejected')
            <section class="orders-next-card" data-tone="danger">
                <div>
                    <h2>تم رفض أمر الشراء (نسخة {{ $doc->version }})</h2>
                    <p>{{ $latestRejection?->reason ? 'سبب الرفض: '.$latestRejection->reason : 'راجع سجل المراجعة وأعد الأمر إلى المسودة لتصحيحه.' }}</p>
                </div>
                @if($permissions['reopen'])
                    <flux:button type="button" variant="primary" icon="arrow-uturn-right" wire:click="applyAction('reopen')" wire:loading.attr="disabled">إعادة للمسودة</flux:button>
                @endif
            </section>
        @elseif($doc->status === 'approved' && !$officialIssue)
            <section class="orders-next-card" data-tone="info">
                <div>
                    <h2>الإجراء التالي: الإصدار الرسمي وإنشاء الاستلام</h2>
                    <p>تم الاعتماد الداخلي. يتطلب ترحيل الاستلام الفعلي إصدار أمر الشراء الرسمي برقم ثابت ولقطة ضريبية معتمدة وتفعيل التشغيل المالي.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($permissions['issue'])
                        <flux:button type="button" variant="primary" icon="document-check" wire:click="issue" wire:confirm="إصدار أمر الشراء المعتمد برقم رسمي ولقطة ضريبية وPDF ثابت؟" wire:loading.attr="disabled">إصدار أمر الشراء الرسمي</flux:button>
                    @endif
                    @if($permissions['receipt_create'])
                        <flux:button icon="truck" :href="route('purchasing.receipts.create', ['order' => $doc->id])" wire:navigate>مسودة استلام</flux:button>
                    @endif
                </div>
            </section>
        @elseif($doc->status === 'approved' && $officialIssue)
            <section class="orders-next-card" data-tone="success">
                <div>
                    <h2>صادر رسميًا برقم <bdi>{{ $officialIssue->official_number }}</bdi></h2>
                    <p>الاستلام الحالي: {{ $recLabel }} · المتبقي للاستلام: <bdi class="tabular-nums font-semibold">{{ D::qty((string) $receiving->remaining_quantity) }}</bdi></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:button :href="route('purchasing.orders.pdf', $doc->id)" icon="arrow-down-tray">تحميل PDF الرسمي</flux:button>
                    @if($permissions['receipt_create'] && $recStatus !== 'received')
                        <flux:button variant="primary" icon="truck" :href="route('purchasing.receipts.create', ['order' => $doc->id])" wire:navigate>مسودة استلام جديد</flux:button>
                    @endif
                </div>
            </section>
        @endif

        <section class="orders-summary" aria-label="ملخص أمر الشراء">
            <div>
                <span>المورد</span>
                @if($permissions['supplier_view'])
                    <strong><a class="text-blue-700 hover:underline" href="{{ route('suppliers.show', $doc->supplier_id) }}" wire:navigate>{{ $doc->supplier_name }}</a></strong>
                @else
                    <strong>{{ $doc->supplier_name }}</strong>
                @endif
            </div>
            <div>
                <span>الفرع</span>
                <strong>{{ $branchName }}</strong>
            </div>
            <div>
                <span>التواريخ</span>
                <strong class="tabular-nums"><bdi>{{ $doc->order_date }}</bdi></strong>
                <small>التوريد المتوقع: <bdi>{{ $doc->expected_date ?: 'غير محدد' }}</bdi></small>
            </div>
            <div>
                <span>الحالة والإصدار والاستلام</span>
                <div class="mt-1 flex flex-wrap gap-1.5">
                    <span class="orders-badge" data-size="sm" data-tone="{{ $statusTone }}">{{ $statusLabel }}</span>
                    @if($officialIssue)
                        <span class="orders-badge" data-size="sm" data-tone="info">صادر رسميًا</span>
                    @endif
                    <span class="orders-badge" data-size="sm" data-tone="{{ $recTone }}">{{ $recLabel }}</span>
                </div>
            </div>
            <div>
                <span>{{ $officialTax ? 'الإجمالي الرسمي شامل الضريبة' : 'صافي أمر الشراء' }}</span>
                <strong class="text-base tabular-nums"><bdi>{{ D::money($officialTax['total'] ?? $doc->net_amount) }}</bdi> {{ D::currencyLabel($doc->currency) }}</strong>
                @if($officialTax)
                    <small>الضريبة الرسمية: <bdi>{{ D::money($officialTax['tax'] ?? null) }}</bdi> {{ D::currencyLabel($doc->currency) }}</small>
                @endif
            </div>
        </section>

        <nav class="orders-tabs" aria-label="أقسام أمر الشراء">
            <button type="button" wire:click="setTab('overview')" @class(['is-active' => $tab === 'overview'])>
                <flux:icon name="rectangle-stack" class="size-4" />
                <span>نظرة عامة والبنود</span>
                <span class="orders-tabs-count">{{ $savedLines->count() }}</span>
            </button>
            <button type="button" wire:click="setTab('review')" @class(['is-active' => $tab === 'review'])>
                <flux:icon name="clipboard-document-check" class="size-4" />
                <span>المراجعة والاعتماد</span>
                <span class="orders-tabs-count">{{ $history->count() }}</span>
            </button>
            <button type="button" wire:click="setTab('issue')" @class(['is-active' => $tab === 'issue'])>
                <flux:icon name="document-check" class="size-4" />
                <span>الإصدار والضريبة</span>
                @if($officialIssue)<span class="orders-badge" data-size="sm" data-tone="success">صادر</span>@endif
            </button>
            <button type="button" wire:click="setTab('receipts')" @class(['is-active' => $tab === 'receipts'])>
                <flux:icon name="truck" class="size-4" />
                <span>الاستلامات</span>
                <span class="orders-tabs-count">{{ $receipts->count() }}</span>
            </button>
            <button type="button" wire:click="setTab('amendments')" @class(['is-active' => $tab === 'amendments'])>
                <flux:icon name="pencil-square" class="size-4" />
                <span>طلبات التعديل</span>
                <span class="orders-tabs-count">{{ $amendments->count() }}</span>
            </button>
        </nav>

        @if($tab === 'overview')
            <div class="grid gap-4 xl:grid-cols-3">
                <x-erp.section-card :title="'بنود أمر الشراء ('.$savedLines->count().')'" icon="rectangle-stack" class="xl:col-span-2 !p-0">
                    <x-ui.responsive-table label="بنود أمر الشراء">
                        <table role="table" class="w-full min-w-[820px] text-sm">
                            <thead role="rowgroup" class="text-end text-xs text-slate-600">
                                <tr role="row">
                                    <th role="columnheader" scope="col" class="px-3 py-2.5">#</th>
                                    <th role="columnheader" scope="col" class="px-3 py-2.5">الصنف</th>
                                    <th role="columnheader" scope="col" class="px-3 py-2.5">الوحدة</th>
                                    <th role="columnheader" scope="col" class="px-3 py-2.5">الكمية</th>
                                    <th role="columnheader" scope="col" class="px-3 py-2.5">سعر الوحدة</th>
                                    <th role="columnheader" scope="col" class="px-3 py-2.5">الخصم</th>
                                    <th role="columnheader" scope="col" class="px-3 py-2.5">صافي البند</th>
                                    <th role="columnheader" scope="col" class="px-3 py-2.5">تقدم الاستلام</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup" class="divide-y divide-slate-100">
                                @foreach($savedLines as $index => $line)
                                    @php
                                        $lineRec = (string) ($receivedByLine[$line->id] ?? '0');
                                        $pct = bccomp((string) $line->quantity, '0', 8) > 0
                                            ? min(100, (int) floor((float) bcdiv(bcmul($lineRec, '100', 4), (string) $line->quantity, 2)))
                                            : 0;
                                    @endphp
                                    <tr role="row" wire:key="saved-poline-{{ $line->id }}" class="hover:bg-slate-50/70">
                                        <td role="cell" data-label="#" class="px-3 py-2.5 tabular-nums">{{ $index + 1 }}</td>
                                        <td role="cell" data-label="الصنف" class="px-3 py-2.5">
                                            <span class="font-medium text-slate-900">{{ $line->product_name }}</span>
                                            <span class="mt-0.5 block text-[11px] text-slate-500"><bdi>{{ $line->sku }}</bdi></span>
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
                                        </td>
                                        <td role="cell" data-label="الوحدة" class="px-3 py-2.5">{{ $unitNames[$line->unit_id] ?? 'الوحدة الأساسية' }}</td>
                                        <td role="cell" data-label="الكمية" class="px-3 py-2.5 tabular-nums"><bdi>{{ D::qty((string) $line->quantity) }}</bdi></td>
                                        <td role="cell" data-label="سعر الوحدة" class="px-3 py-2.5 tabular-nums"><bdi>{{ D::money((string) $line->unit_price) }}</bdi></td>
                                        <td role="cell" data-label="الخصم" class="px-3 py-2.5 tabular-nums text-slate-600"><bdi>{{ bccomp((string) $line->discount_amount, '0', 8) > 0 ? D::money((string) $line->discount_amount) : '—' }}</bdi></td>
                                        <td role="cell" data-label="صافي البند" class="px-3 py-2.5 font-semibold tabular-nums"><bdi>{{ D::money((string) $line->net_amount) }}</bdi> {{ D::currencyLabel($doc->currency) }}</td>
                                        <td role="cell" data-label="تقدم الاستلام" class="px-3 py-2.5 tabular-nums">
                                            <div class="text-xs text-slate-600"><bdi>{{ D::qty($lineRec) }}</bdi> / <bdi>{{ D::qty((string) $line->quantity) }}</bdi></div>
                                            <div class="orders-progress mt-1.5" aria-hidden="true">
                                                <div class="orders-progress-bar {{ $pct >= 100 ? 'is-done' : '' }}" style="width: {{ $pct }}%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.responsive-table>
                </x-erp.section-card>

                <x-erp.section-card title="الملخص المالي والضريبي" icon="banknotes">
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">الصافي قبل الضريبة</dt>
                            <dd class="font-semibold tabular-nums"><bdi>{{ D::money($officialTax['net'] ?? $doc->net_amount) }}</bdi> {{ D::currencyLabel($doc->currency) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">أساس احتساب الضريبة</dt>
                            <dd>{{ D::TAX_MODES[$doc->tax_mode] ?? $doc->tax_mode }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">الضريبة المقدمة من المورد</dt>
                            <dd class="tabular-nums"><bdi>{{ $doc->reported_tax !== null ? D::money((string) $doc->reported_tax).' '.D::currencyLabel($doc->currency) : 'غير مذكورة' }}</bdi></dd>
                        </div>
                        @if($officialTax)
                            <div class="flex justify-between gap-3 border-t border-slate-200 pt-3">
                                <dt class="text-slate-500">الضريبة الرسمية المعتمدة</dt>
                                <dd class="font-semibold tabular-nums"><bdi>{{ D::money($officialTax['tax'] ?? null) }}</bdi> {{ D::currencyLabel($doc->currency) }}</dd>
                            </div>
                            <div class="rounded-lg bg-emerald-50/70 px-3 py-2.5 ring-1 ring-emerald-200">
                                <dt class="text-xs font-semibold text-emerald-900">الإجمالي الرسمي شامل الضريبة</dt>
                                <dd class="mt-1 text-lg font-bold text-emerald-900 tabular-nums"><bdi>{{ D::money($officialTax['total'] ?? null) }}</bdi> {{ D::currencyLabel($doc->currency) }}</dd>
                            </div>
                        @endif
                    </dl>
                </x-erp.section-card>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <x-erp.section-card title="تفاصيل المستند والمصدر" icon="document-text">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">المرجع الداخلي</dt><dd class="tabular-nums" dir="ltr">#{{ $doc->id }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">الفرع</dt><dd>{{ $branchName }}</dd></div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">المصدر</dt>
                            <dd>
                                @if($awardInfo)
                                    @if($permissions['offer_view'])
                                        <a class="text-blue-700 hover:underline" href="{{ route('purchasing.offers.show', $awardInfo->offer_id) }}" wire:navigate>ترسية #{{ $awardInfo->id }} · عرض #{{ $awardInfo->offer_id }}</a>
                                    @else
                                        ترسية #{{ $awardInfo->id }} · عرض #{{ $awardInfo->offer_id }}
                                    @endif
                                @else
                                    شراء مباشر
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">أنشأه</dt><dd><x-erp.user-name :id="$doc->created_by" /></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">تاريخ الإنشاء</dt><dd class="tabular-nums"><bdi>{{ \Carbon\CarbonImmutable::parse($doc->created_at)->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</bdi></dd></div>
                        @if($doc->approved_by)
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">المُعتمِد</dt><dd><x-erp.user-name :id="$doc->approved_by" /></dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">وقت الاعتماد</dt><dd class="tabular-nums"><bdi>{{ \Carbon\CarbonImmutable::parse($doc->approved_at)->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</bdi></dd></div>
                        @endif
                    </dl>
                </x-erp.section-card>

                <x-erp.section-card title="شروط الدفع والملاحظات" icon="clipboard-document-list">
                    <dl class="space-y-4 text-sm">
                        <div>
                            <dt class="text-slate-500">شروط الدفع</dt>
                            <dd class="mt-1 whitespace-pre-line">{{ $doc->payment_terms ?: 'لم تحدد شروط دفع.' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">ملاحظات الأمر</dt>
                            <dd class="mt-1 whitespace-pre-line">{{ $doc->notes ?: 'لا توجد ملاحظات.' }}</dd>
                        </div>
                    </dl>
                </x-erp.section-card>
            </div>
        @elseif($tab === 'review')
            <x-erp.section-card title="المراجعة والاعتماد وسجل القرارات" icon="clipboard-document-check">
                @if($permissions['submit'] || $permissions['approve'] || $permissions['reopen'])
                    <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                        @if($permissions['submit'])
                            <flux:button type="button" variant="primary" icon="paper-airplane" wire:click="applyAction('submit')" wire:loading.attr="disabled">إرسال للاعتماد</flux:button>
                        @endif
                        @if($permissions['approve'])
                            <div class="min-w-64 flex-1">
                                <flux:input wire:model="reason" label="سبب القرار (إلزامي للرفض)" />
                            </div>
                            <flux:button type="button" variant="primary" icon="check" wire:click="applyAction('approve')" wire:loading.attr="disabled">اعتماد داخلي</flux:button>
                            <flux:button type="button" variant="danger" icon="x-mark" wire:click="applyAction('reject')" wire:confirm="هل أنت متأكد من رفض هذا المستند؟ لا يمكن التراجع عن هذا الإجراء." wire:loading.attr="disabled">رفض</flux:button>
                        @endif
                        @if($permissions['reopen'])
                            <flux:button type="button" variant="primary" icon="arrow-uturn-right" wire:click="applyAction('reopen')" wire:loading.attr="disabled">إعادة للمسودة</flux:button>
                        @endif
                    </div>
                @endif

                <ol class="space-y-3">
                    @forelse($history as $h)
                        @php [$actLabel, $actTone] = D::reviewAction($h->action); @endphp
                        <li class="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-slate-200 p-3 text-sm">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="orders-badge" data-size="sm" data-tone="{{ $actTone }}">{{ $actLabel }}</span>
                                    <strong class="text-slate-900"><x-erp.user-name :id="$h->actor_id" /></strong>
                                    <span class="text-xs text-slate-500">نسخة {{ $h->document_version }}</span>
                                </div>
                                @if($h->reason)
                                    <p class="mt-2 text-xs text-slate-700">السبب / الملاحظة: {{ $h->reason }}</p>
                                @endif
                            </div>
                            <span class="text-xs text-slate-500 tabular-nums"><bdi>{{ \Carbon\CarbonImmutable::parse($h->created_at)->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</bdi></span>
                        </li>
                    @empty
                        <li class="py-4 text-center text-sm text-slate-500">لا توجد قرارات مراجعة مسجلة بعد.</li>
                    @endforelse
                </ol>
            </x-erp.section-card>
        @elseif($tab === 'issue')
            <x-erp.section-card title="الإصدار الرسمي والضريبة" icon="document-check">
                @if($officialIssue)
                    <div class="grid gap-5 lg:grid-cols-2">
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">الرقم الرسمي لأمر الشراء</dt><dd class="font-bold text-slate-900 tabular-nums" dir="ltr">{{ $officialIssue->official_number }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">نسخة المصدر عند الإصدار</dt><dd class="tabular-nums">{{ $officialIssue->source_version }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">المُصدِر</dt><dd><x-erp.user-name :id="$officialIssue->issued_by" /></dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">وقت الإصدار</dt><dd class="tabular-nums"><bdi>{{ \Carbon\CarbonImmutable::parse($officialIssue->issued_at)->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</bdi></dd></div>
                            <div class="pt-2"><flux:button :href="route('purchasing.orders.pdf', $doc->id)" variant="primary" icon="arrow-down-tray">تحميل نسخة PDF الرسمية</flux:button></div>
                        </dl>

                        @if($officialTax)
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-4">
                                <h3 class="mb-3 font-bold text-emerald-950">لقطة الضريبة الرسمية المحفوظة</h3>
                                <dl class="space-y-2 text-sm">
                                    <div class="flex justify-between gap-3"><dt class="text-slate-600">الصافي قبل الضريبة</dt><dd class="font-semibold tabular-nums"><bdi>{{ D::money($officialTax['net'] ?? null) }}</bdi> {{ D::currencyLabel($doc->currency) }}</dd></div>
                                    @foreach($officialTax['tax_totals'] ?? [] as $taxRow)
                                        <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ $taxRow['name'] ?? 'ضريبة' }}</dt><dd class="tabular-nums"><bdi>{{ D::money($taxRow['amount'] ?? null) }}</bdi> {{ D::currencyLabel($doc->currency) }}</dd></div>
                                    @endforeach
                                    <div class="flex justify-between gap-3 border-t border-emerald-200 pt-2 font-bold text-emerald-950"><dt>الإجمالي الرسمي</dt><dd class="tabular-nums"><bdi>{{ D::money($officialTax['total'] ?? null) }}</bdi> {{ D::currencyLabel($doc->currency) }}</dd></div>
                                </dl>
                            </div>
                        @endif
                    </div>
                @elseif($doc->status === 'approved')
                    <div class="space-y-4">
                        <flux:callout variant="info" icon="information-circle">
                            الأمر معتمد داخليًا ولم يصدر رسميًا بعد. يتطلب الإصدار الرسمي التحقق من المعاملة الضريبية الفعلية وتفعيل التشغيل المالي ومطابقة الضريبة المبلغ عنها (إن ذُكرت).
                        </flux:callout>
                        <div class="flex flex-wrap gap-2">
                            @if($permissions['issue'])
                                <flux:button type="button" wire:click="issue" variant="primary" icon="document-check" wire:confirm="إصدار أمر الشراء المعتمد برقم رسمي ولقطة ضريبية وPDF ثابت؟" wire:loading.attr="disabled">إصدار أمر الشراء الرسمي</flux:button>
                            @endif
                            @if($permissions['accounting_setup'])
                                <flux:button :href="route('accounting.setup')" wire:navigate icon="cog-6-tooth">مراجعة إعداد التشغيل المالي</flux:button>
                            @endif
                        </div>
                    </div>
                @else
                    <x-ui.empty-state title="الإصدار غير متاح بعد" description="يلزم اعتماد أمر الشراء داخليًا أولًا قبل إتاحة الإصدار الرسمي وPDF." icon="document-check" />
                @endif
            </x-erp.section-card>
        @elseif($tab === 'receipts')
            <x-erp.section-card title="الاستلامات المرتبطة بأمر الشراء" icon="truck">
                <div class="mb-5 grid gap-3 sm:grid-cols-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-sm">
                    <div>
                        <span class="text-xs text-slate-500">حالة الاستلام الإجمالية</span>
                        <div class="mt-1"><span class="orders-badge" data-tone="{{ $recTone }}">{{ $recLabel }}</span></div>
                    </div>
                    <div>
                        <span class="text-xs text-slate-500">الكمية المتبقية للاستلام (بعد المرحّل)</span>
                        <strong class="mt-1 block text-base tabular-nums"><bdi>{{ D::qty((string) ($receiving?->remaining_quantity ?? '0')) }}</bdi></strong>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        @if($permissions['receipt_create'] && $recStatus !== 'received')
                            <flux:button variant="primary" size="sm" icon="plus" :href="route('purchasing.receipts.create', ['order' => $doc->id])" wire:navigate>إنشاء مسودة استلام</flux:button>
                        @endif
                        @if($permissions['return_view'])
                            <flux:button size="sm" icon="arrow-uturn-left" :href="route('purchasing.returns')" wire:navigate>مرتجعات المشتريات</flux:button>
                        @endif
                    </div>
                </div>

                <div class="space-y-3">
                    @forelse($receipts as $r)
                        @php [$rLabel, $rTone] = D::receiptStatus($r->status); @endphp
                        <a class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm hover:border-blue-300 hover:bg-blue-50/30" href="{{ route('purchasing.receipts.show', $r->id) }}" wire:navigate>
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="text-slate-900">استلام #{{ $r->id }}</strong>
                                    <span class="orders-badge" data-size="sm" data-tone="{{ $rTone }}">{{ $rLabel }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">التاريخ: <bdi>{{ $r->receipt_date }}</bdi> · مرجع التوريد: {{ $r->delivery_reference }}</p>
                            </div>
                            <span class="font-semibold text-blue-700">عرض التفاصيل ←</span>
                        </a>
                    @empty
                        <x-ui.empty-state title="لا توجد استلامات مرتبطة متاحة ضمن نطاقك" description="{{ $doc->status === 'approved' ? 'أنشئ مسودة استلام لتسجيل الدفعات الواردة لهذا الأمر.' : 'يتاح إنشاء الاستلامات بعد الاعتماد الداخلي لأمر الشراء.' }}" icon="truck" />
                    @endforelse
                </div>
            </x-erp.section-card>
        @elseif($tab === 'amendments')
            <x-erp.section-card title="طلبات التعديل وسجل النسخ" icon="pencil-square">
                <p class="mb-4 text-sm text-slate-600">النسخ المعتمدة محفوظة دون تغيير. طلب التعديل يسجل الكميات المقترحة ومبرراتها فقط دون المساس بالأمر الأصلي.</p>

                <div class="space-y-4">
                    @forelse($amendments as $a)
                        <div wire:key="amendment-{{ $a->id }}" class="rounded-xl border border-slate-200 bg-slate-50/50 p-4 text-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="text-slate-900">طلب تعديل #{{ $a->id }}</strong>
                                    <span class="orders-badge" data-size="sm" data-tone="neutral">النسخة الأصلية {{ $a->source_version }}</span>
                                    <span class="orders-badge" data-size="sm" data-tone="warning">بانتظار عقد التعديل الرسمي</span>
                                </div>
                                <span class="text-xs text-slate-500 tabular-nums"><bdi>{{ \Carbon\CarbonImmutable::parse($a->created_at)->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</bdi></span>
                            </div>
                            <p class="mt-2 text-slate-700">السبب: {{ $a->reason }}</p>
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead class="bg-slate-100 text-slate-600">
                                        <tr>
                                            <th class="px-3 py-2 text-start">الصنف</th>
                                            <th class="px-3 py-2 text-start">الكمية الأصلية</th>
                                            <th class="px-3 py-2 text-start">الكمية المقترحة</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 bg-white">
                                        @foreach(json_decode($a->proposed_changes, true) as $c)
                                            @php $targetLine = $savedLinesById->get((int) ($c['order_line_id'] ?? 0)); @endphp
                                            <tr>
                                                <td class="px-3 py-2 font-medium">{{ $targetLine?->product_name ?? ('بند #'.$c['order_line_id']) }} @if($targetLine)<span class="text-slate-500">(<bdi>{{ $targetLine->sku }}</bdi>)</span>@endif</td>
                                                <td class="px-3 py-2 tabular-nums"><bdi>{{ $targetLine ? D::qty((string) $targetLine->quantity) : '—' }}</bdi></td>
                                                <td class="px-3 py-2 font-bold text-blue-700 tabular-nums"><bdi>{{ D::qty((string) $c['quantity']) }}</bdi></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @empty
                        @unless($permissions['amend'])
                            <x-ui.empty-state title="لا توجد طلبات تعديل" description="لم يُسجّل أي طلب تعديل على هذا الأمر." icon="pencil-square" />
                        @endunless
                    @endforelse
                </div>

                @if($permissions['amend'])
                    <form wire:submit="requestAmendment" class="mt-5 space-y-4 rounded-xl border border-slate-200 bg-white p-4">
                        <h3 class="font-bold text-slate-900">تقديم طلب تعديل كميات جديد</h3>
                        <flux:textarea wire:model="amendmentReason" label="سبب التعديل المطلوب" rows="2" required />
                        <p class="text-xs text-slate-500">أدخل الكمية المقترحة الجديدة فقط للبنود المراد تعديلها واترك البقية فارغة.</p>
                        <div class="grid gap-3 md:grid-cols-2">
                            @foreach($savedLines as $l)
                                <flux:input wire:model="amendmentQuantities.{{ $l->id }}" :label="$l->product_name.' — الكمية الحالية: '.D::qty((string) $l->quantity)" inputmode="decimal" />
                            @endforeach
                        </div>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ طلب التعديل دون تغيير الأصل</flux:button>
                    </form>
                @endif
            </x-erp.section-card>
        @endif
    @endif
</div>
