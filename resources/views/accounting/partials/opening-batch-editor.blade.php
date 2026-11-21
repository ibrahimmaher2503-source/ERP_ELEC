<div class="space-y-5">
    {{-- 3-Card Opening Mode Selector --}}
    <div class="erp-setup-mode-grid" role="group" aria-label="طريقة إعداد الأرصدة الافتتاحية">
        <button
            type="button"
            wire:click="setOpeningMode('zero')"
            aria-pressed="{{ $openingMode === 'zero' ? 'true' : 'false' }}"
            @class(['erp-setup-mode-card', 'is-active' => $openingMode === 'zero'])
        >
            <span class="flex w-full items-center justify-between gap-2">
                <span class="flex items-center gap-2">
                    <flux:icon name="check-circle" class="size-4 shrink-0 text-blue-700" aria-hidden="true" />
                    <strong>شركة جديدة بدون أرصدة</strong>
                </span>
                @if($openingMode === 'zero')<span class="erp-setup-pill is-info">مختار</span>@endif
            </span>
            <span>تبدأ الشركة بدون أرصدة سابقة، وتُسجّل إقراراً صريحاً بذلك للاعتماد المستقل.</span>
        </button>

        <button
            type="button"
            wire:click="setOpeningMode('excel')"
            aria-pressed="{{ $openingMode === 'excel' ? 'true' : 'false' }}"
            @class(['erp-setup-mode-card', 'is-active' => $openingMode === 'excel'])
        >
            <span class="flex w-full items-center justify-between gap-2">
                <span class="flex items-center gap-2">
                    <flux:icon name="arrow-up-tray" class="size-4 shrink-0 text-blue-700" aria-hidden="true" />
                    <strong>استيراد من ملف Excel</strong>
                </span>
                @if($openingMode === 'excel')<span class="erp-setup-pill is-info">مختار</span>@endif
            </span>
            <span>حمّل القالب المعتمد واملأه بالأرصدة ثم ارفعه للمعاينة والمصالحة الفورية.</span>
        </button>

        <button
            type="button"
            wire:click="setOpeningMode('manual')"
            aria-pressed="{{ $openingMode === 'manual' ? 'true' : 'false' }}"
            @class(['erp-setup-mode-card', 'is-active' => $openingMode === 'manual'])
        >
            <span class="flex w-full items-center justify-between gap-2">
                <span class="flex items-center gap-2">
                    <flux:icon name="pencil-square" class="size-4 shrink-0 text-blue-700" aria-hidden="true" />
                    <strong>إدخال الأرصدة يدوياً</strong>
                </span>
                @if($openingMode === 'manual')<span class="erp-setup-pill is-info">مختار</span>@endif
            </span>
            <span>أضف بنود الأستاذ العام وتفاصيل الموردين والعملاء والنقدية والمخزون من النموذج مباشرة.</span>
        </button>
    </div>

    {{-- Mode A: Zero Opening Balances --}}
    @if($openingMode === 'zero')
        <div wire:key="opening-mode-zero" class="space-y-4">
            <div class="rounded-lg border border-blue-200 bg-blue-50/70 p-4 text-sm leading-7 text-slate-700">
                اختر هذا المسار إذا كانت شركتك تبدأ العمل من الصفر ولا توجد أرصدة منقولة من نظام أو فترة سابقة. لن يُنشئ النظام أي قيود افتتاحية وهمية، وسيُلزم اعتماد هذا الإقرار من مراجع مستقل في الخطوة التالية.
            </div>
            <form wire:submit="previewOpening" class="space-y-4">
                <flux:textarea
                    wire:model="evidence"
                    rows="2"
                    label="مرجع إقرار البدء بدون أرصدة (مثل: محضر الإدارة أو رقم المذكرة)"
                    required
                />
                <flux:button type="submit" wire:loading.attr="disabled">معاينة وتأكيد الإقرار</flux:button>
            </form>
        </div>

    {{-- Mode B: Excel Import --}}
    @elseif($openingMode === 'excel')
        <div wire:key="opening-mode-excel" class="space-y-4">
            <ol class="erp-setup-mini-steps">
                <li><strong>١.</strong> حمّل قالب Excel الفارغ المعتمد.</li>
                <li><strong>٢.</strong> املأ المبالغ والتكاليف كنصوص عشرية دون صيغ أو فواصل آلاف.</li>
                <li><strong>٣.</strong> ارفع الملف واضغط «استيراد ومعاينة» ثم احفظ الحزمة للمراجعة.</li>
            </ol>

            <flux:textarea
                wire:model="evidence"
                rows="2"
                label="مرجع كشف الأرصدة الفعلي المعتمد"
                description="أدخل المرجع قبل الاستيراد أو اضغط «تحديث المعاينة» بعد كتابته."
                required
            />

            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="button" wire:click="template" wire:loading.attr="disabled" icon="arrow-down-tray">
                    تحميل قالب Excel الفارغ
                </flux:button>

                <label for="opening-workbook-input" class="erp-setup-file-drop">
                    <flux:icon name="arrow-up-tray" class="size-4 shrink-0 text-blue-700" aria-hidden="true" />
                    <span>{{ $workbook ? 'تم اختيار الملف: '.$workbook->getClientOriginalName() : 'اختر ملف Excel (.xlsx) من جهازك' }}</span>
                    <input id="opening-workbook-input" type="file" wire:model="workbook" accept=".xlsx" class="sr-only" />
                </label>

                <flux:button
                    type="button"
                    variant="primary"
                    wire:click="importPreview"
                    wire:loading.attr="disabled"
                    wire:target="workbook,importPreview"
                >
                    استيراد ومعاينة
                </flux:button>

                @if($lines !== [])
                    <flux:button type="button" wire:click="previewOpening" wire:loading.attr="disabled">
                        تحديث المعاينة
                    </flux:button>
                    <flux:button type="button" variant="ghost" wire:click="setOpeningMode('manual')">
                        عرض/تعديل البنود ({{ count($lines) }}) يدوياً
                    </flux:button>
                @endif
            </div>

            <p class="text-xs text-slate-500" wire:loading wire:target="workbook" role="status">جارٍ رفع ملف Excel…</p>
            <flux:error name="workbook" />
            <p class="text-xs text-slate-500">الحد الأقصى 5 ميجابايت و500 سطر. تُرفض الصيغ الحسابية ويُحذف الملف المؤقت تلقائياً بعد قراءته.</p>
        </div>

    {{-- Mode C: Manual Line Entry --}}
    @else
        <div wire:key="opening-mode-manual" class="space-y-4">
            <div class="space-y-1 rounded-lg border border-blue-200 bg-blue-50/70 p-4 text-sm leading-7 text-slate-700">
                <p>قيود <strong>الأستاذ العام</strong> تمثل ميزان الافتتاح الكامل لكل فرع (إجمالي المدين = إجمالي الدائن). وعند وجود أرصدة للموردين أو العملاء أو النقدية أو البنوك أو المخزون، أضف بنود تفاصيلها المطابقة لتُصالح تلقائياً دون تكرار قيدها.</p>
                <p class="text-xs text-slate-600">تُجلب قوائم الحسابات والموردين والعملاء والمستودعات والأصناف مباشرة من أدلة النظام النشطة المسموح لك بعرضها.</p>
            </div>

            <form wire:submit="previewOpening" class="space-y-4">
                <flux:textarea
                    wire:model="evidence"
                    rows="2"
                    label="مرجع كشف الأرصدة الفعلي المعتمد للتحضير"
                    required
                />

                <div class="flex flex-wrap items-center gap-2" role="toolbar" aria-label="إضافة بند افتتاحي">
                    <flux:button type="button" size="sm" icon="plus" wire:click="addLine('gl')">قيد أستاذ عام</flux:button>
                    <flux:button type="button" size="sm" icon="plus" wire:click="addLine('ap')">تفاصيل مورد</flux:button>
                    <flux:button type="button" size="sm" icon="plus" wire:click="addLine('ar')">تفاصيل عميل</flux:button>
                    <flux:button type="button" size="sm" icon="plus" wire:click="addLine('cash')">نقدية</flux:button>
                    <flux:button type="button" size="sm" icon="plus" wire:click="addLine('bank')">بنك</flux:button>
                    <flux:button type="button" size="sm" icon="plus" wire:click="addLine('inventory')">مخزون</flux:button>
                </div>

                <div class="erp-setup-totals-bar">
                    <div><span>إجمالي مدين الأستاذ:</span> <strong><bdi dir="ltr">{{ $draftTotals['debit'] }}</bdi> ج.م</strong></div>
                    <div><span>إجمالي دائن الأستاذ:</span> <strong><bdi dir="ltr">{{ $draftTotals['credit'] }}</bdi> ج.م</strong></div>
                    <div>
                        <span>الفرق:</span>
                        <strong @class(['text-emerald-700' => bccomp($draftTotals['diff'], '0', 2) === 0, 'text-amber-800' => bccomp($draftTotals['diff'], '0', 2) !== 0])>
                            <bdi dir="ltr">{{ $draftTotals['diff'] }}</bdi> ج.م
                        </strong>
                    </div>
                    <div><span>البنود:</span> <strong>{{ $draftTotals['glCount'] }} أستاذ · {{ $draftTotals['detailCount'] }} تفاصيل</strong></div>
                </div>

                @forelse($lines as $i => $line)
                    @php
                        $lineKind = $line['kind'] ?? 'gl';
                        $branchWarehouses = ! empty($line['branch_id']) ? ($warehousesByBranch[(int) $line['branch_id']] ?? []) : [];
                        $selectedTracking = $productTrackings[(int) ($line['product_id'] ?? 0)] ?? null;
                    @endphp
                    <fieldset wire:key="opening-line-{{ $creationKey }}-{{ $i }}-{{ $lineKind }}" class="erp-setup-line-card">
                        <div class="erp-setup-line-head">
                            <div class="flex flex-wrap items-center gap-2">
                                <legend class="text-sm font-bold text-[#172b4d]">بند #{{ $i + 1 }}</legend>
                                <span class="erp-setup-pill is-info">{{ $openingKindLabels[$lineKind] ?? $lineKind }}</span>
                            </div>
                            <flux:button type="button" wire:click="removeLine({{ $i }})" size="sm" variant="ghost" icon="trash">
                                حذف البند
                            </flux:button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <flux:select wire:model.live="lines.{{ $i }}.kind" label="النوع">
                                <option value="gl">قيد الأستاذ العام</option>
                                <option value="ap">تفاصيل مورد</option>
                                <option value="ar">تفاصيل عميل</option>
                                <option value="cash">تفاصيل نقدية</option>
                                <option value="bank">تفاصيل بنك</option>
                                <option value="inventory">مخزون افتتاحي (الأقدم أولاً)</option>
                            </flux:select>

                            <flux:input wire:model="lines.{{ $i }}.reference" label="مرجع البند الفريد" placeholder="مثال: OP-001" required />

                            <flux:select wire:model.live="lines.{{ $i }}.branch_id" label="الفرع" required>
                                <option value="">اختر الفرع</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch['id'] }}">{{ $branch['name'] }}</option>
                                @endforeach
                            </flux:select>

                            @if($lineKind === 'gl')
                                <flux:select wire:model="lines.{{ $i }}.account_id" label="حساب الأستاذ العام" required>
                                    <option value="">اختر الحساب</option>
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:input wire:model.live.debounce.300ms="lines.{{ $i }}.debit" label="مدين (ج.م)" inputmode="decimal" />
                                <flux:input wire:model.live.debounce.300ms="lines.{{ $i }}.credit" label="دائن (ج.م)" inputmode="decimal" />
                            @elseif($lineKind === 'ap')
                                @if($canLookupSuppliers)
                                    <flux:select wire:model="lines.{{ $i }}.supplier_id" label="المورد" search-model="supplierSearch" required>
                                        <option value="">اختر المورد…</option>
                                        @if(!empty($line['supplier_id']) && !collect($suppliers)->contains('id', (int) $line['supplier_id']))
                                            <option value="{{ $line['supplier_id'] }}">{{ $supplierLabels[(int) $line['supplier_id']] ?? '#'.$line['supplier_id'] }}</option>
                                        @endif
                                        @foreach($suppliers as $supplier)
                                            <option value="{{ $supplier->id }}">{{ $supplier->code }} · {{ $supplier->name }}</option>
                                        @endforeach
                                    </flux:select>
                                @else
                                    <flux:input wire:model="lines.{{ $i }}.supplier_id" label="رقم المورد" inputmode="numeric" required />
                                @endif
                                <flux:input wire:model="lines.{{ $i }}.amount" label="القيمة المستحقة (ج.م)" inputmode="decimal" required />
                            @elseif($lineKind === 'ar')
                                @if($canLookupCustomers)
                                    <flux:select wire:model="lines.{{ $i }}.customer_id" label="العميل" search-model="customerSearch" required>
                                        <option value="">اختر العميل…</option>
                                        @if(!empty($line['customer_id']) && !collect($customers)->contains('id', (int) $line['customer_id']))
                                            <option value="{{ $line['customer_id'] }}">{{ $customerLabels[(int) $line['customer_id']] ?? '#'.$line['customer_id'] }}</option>
                                        @endif
                                        @foreach($customers as $customer)
                                            <option value="{{ $customer->id }}">{{ $customer->code }} · {{ $customer->name }}</option>
                                        @endforeach
                                    </flux:select>
                                @else
                                    <flux:input wire:model="lines.{{ $i }}.customer_id" label="رقم العميل" inputmode="numeric" required />
                                @endif
                                <flux:input wire:model="lines.{{ $i }}.amount" label="القيمة المستحقة (ج.م)" inputmode="decimal" required />
                            @elseif($lineKind === 'inventory')
                                @if($branchWarehouses !== [] || empty($line['branch_id']))
                                    <flux:select wire:model="lines.{{ $i }}.warehouse_id" label="المستودع" :disabled="empty($line['branch_id'])" required>
                                        <option value="">{{ empty($line['branch_id']) ? 'اختر الفرع أولاً' : 'اختر المستودع' }}</option>
                                        @foreach($branchWarehouses as $wh)
                                            <option value="{{ $wh['id'] }}">{{ $wh['code'] ? $wh['code'].' · ' : '' }}{{ $wh['name'] }}</option>
                                        @endforeach
                                    </flux:select>
                                @else
                                    <flux:input wire:model="lines.{{ $i }}.warehouse_id" label="رقم المستودع" inputmode="numeric" required />
                                @endif

                                @if($canLookupInventoryProducts)
                                    <flux:select wire:model.live="lines.{{ $i }}.product_id" label="الصنف المخزني" search-model="inventoryProductSearch" required>
                                        <option value="">اختر الصنف…</option>
                                        @if(!empty($line['product_id']) && !collect($inventoryProducts)->contains('id', (int) $line['product_id']))
                                            <option value="{{ $line['product_id'] }}">{{ $productLabels[(int) $line['product_id']] ?? '#'.$line['product_id'] }}</option>
                                        @endif
                                        @foreach($inventoryProducts as $product)
                                            <option value="{{ $product['id'] }}">{{ $product['sku'] }} · {{ $product['name'] }}{{ $product['unit_name'] !== '' ? ' ('.$product['unit_name'].')' : '' }}</option>
                                        @endforeach
                                    </flux:select>
                                @else
                                    <flux:input wire:model="lines.{{ $i }}.product_id" label="رقم الصنف المخزني" inputmode="numeric" required />
                                @endif

                                <flux:input wire:model.live.debounce.300ms="lines.{{ $i }}.quantity" label="الكمية بالوحدة الأساسية" inputmode="decimal" required />
                                <flux:input wire:model.live.debounce.300ms="lines.{{ $i }}.unit_cost" label="تكلفة الوحدة (ج.م)" inputmode="decimal" required />
                                <flux:input
                                    wire:model="lines.{{ $i }}.amount"
                                    label="القيمة الإجمالية (ج.م)"
                                    inputmode="decimal"
                                    description="تُحسب تلقائياً من الكمية × التكلفة وتُقرب لمنزلتين."
                                    required
                                />

                                @if($selectedTracking === 'serial' || $selectedTracking === null)
                                    <div class="sm:col-span-2 xl:col-span-4">
                                        <flux:textarea
                                            wire:model="lines.{{ $i }}.serial_text"
                                            rows="3"
                                            :label="$selectedTracking === 'serial' ? 'الأرقام التسلسلية المطلوبة (رقم واحد في كل سطر)' : 'الأرقام التسلسلية (للصنف المسلسل فقط: رقم في كل سطر)'"
                                            :required="$selectedTracking === 'serial'"
                                        />
                                    </div>
                                @else
                                    <p class="sm:col-span-2 xl:col-span-4 text-xs text-slate-500">هذا الصنف يُتتبع بالكمية فقط ولا يحتاج أرقاماً تسلسلية.</p>
                                @endif
                            @else
                                <flux:input wire:model="lines.{{ $i }}.amount" label="القيمة التفصيلية (ج.م)" inputmode="decimal" required />
                            @endif
                        </div>
                    </fieldset>
                @empty
                    <x-ui.empty-state
                        title="لا توجد بنود افتتاحية مضافة"
                        description="أضف بنود الأستاذ العام والتفاصيل من الأزرار أعلاه، أو اختر «شركة جديدة بدون أرصدة»."
                        icon="document-text"
                    />
                @endforelse

                <div>
                    <flux:button type="submit" wire:loading.attr="disabled">معاينة ومصالحة</flux:button>
                </div>
            </form>
        </div>
    @endif

    {{-- Shared Preview Reconciliation Result --}}
    @if($previewReconciliation)
        <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4" aria-live="polite">
            <h3 class="text-sm font-bold text-[#172b4d]">
                نتيجة المعاينة ({{ count($preview) }} بنداً — لم يُحفظ أو يُرحل شيء بعد)
            </h3>

            @foreach($previewReconciliation['errors'] as $message)
                <p class="text-sm text-amber-800">{{ $message }}</p>
            @endforeach

            @if($previewReconciliation['balanced'])
                <p class="text-sm font-semibold text-emerald-800">المعاينة متطابقة وجاهزة للحفظ والمراجعة المستقلة.</p>
            @endif

            <flux:button type="button" wire:click="saveOpening" variant="primary" wire:loading.attr="disabled">
                حفظ حزمة الافتتاح للمراجعة
            </flux:button>
        </div>
    @endif
</div>
