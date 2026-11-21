@php
    use App\Modules\Sales\Presentation\SalesOrderDisplay as D;
@endphp
<div data-erp-archetype="form" class="orders-ui sales-order-form space-y-5">
    <x-page.heading :title="$orderId ? 'تعديل أمر البيع' : 'إنشاء أمر بيع مباشر'" description="حدد العميل والبنود والأسعار والقوالب المعتمدة، ثم احفظ المسودة وقدّمها للمراجعة" :breadcrumbs="['المبيعات', 'أوامر البيع', $orderId ? 'تعديل المسودة' : 'إنشاء أمر بيع']">
        <x-slot:actions>
            <flux:button :href="$orderId ? route('sales-orders.show', ['order' => $orderId]) : route('sales-orders.index')" wire:navigate icon="arrow-right">
                {{ $orderId ? 'العودة إلى التفاصيل' : 'العودة إلى أوامر البيع' }}
            </flux:button>
            <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save" icon="bookmark-square">حفظ أمر البيع</flux:button>
        </x-slot:actions>
    </x-page.heading>

    @include('sales.orders.partials.feedback')

    <form wire:submit="save" class="erp-document-form space-y-5">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-erp.section-card title="بيانات الأمر الأساسية" icon="document-text">
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input value="جنيه مصري (ج.م)" label="العملة" disabled />
                    <flux:select wire:model="form.branch_id" label="الفرع" required>
                        <option value="">اختر فرعًا مصرحًا به</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch['id'] }}">{{ $branch['name'] }} ({{ $branch['code'] }})</option>
                        @endforeach
                    </flux:select>
                    <flux:input type="date" wire:model.live="form.order_date" label="تاريخ الأمر" required />
                    <flux:input type="date" wire:model="form.requested_delivery_date" label="تاريخ التسليم المطلوب" />
                    <flux:input field:class="sm:col-span-2" wire:model="form.customer_reference" label="مرجع العميل" maxlength="100" placeholder="رقم طلب الشراء أو المرجع الخارجي للعميل…" />
                </div>
            </x-erp.section-card>

            <x-erp.section-card title="بيانات العميل والمشروع" icon="users">
                <div class="space-y-3">
                    <flux:select wire:model="form.customer_id" label="العميل" search-model="customerSearch" required>
                        <option value="">اختر عميلًا نشطًا</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer['id'] }}">{{ $customer['name'] }} ({{ $customer['code'] }})</option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="form.project_name" label="اسم المشروع (اختياري)" maxlength="200" />
                    <p class="erp-form-hint">يصدر الرقم الرسمي لأمر البيع والضريبة وحجز المخزون ذريًا عند الاعتماد المستقل.</p>
                </div>
            </x-erp.section-card>
        </div>

        <x-erp.section-card :title="'بنود أمر البيع ('.count($lines).')'" icon="rectangle-stack">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-500">يُجلب السعر المرجعي المعتمد حسب الصنف والوحدة وتاريخ الأمر. أي تعديل للسعر أو خصم يُسجل تلقائيًا ضمن مراجعة الاستثناءات.</p>
                <flux:button type="button" wire:click="addLine" icon="plus" size="sm" variant="primary">إضافة بند</flux:button>
            </div>
            <div class="space-y-4">
                @foreach($lines as $index => $line)
                    @php
                        $flags = $lineExceptions[$index] ?? [];
                        $hasExtra = trim((string) ($line['override_reason'] ?? '')) !== ''
                            || (trim((string) ($line['description'] ?? '')) !== '' && trim((string) ($line['description'] ?? '')) !== trim((string) ($line['product_name'] ?? '')));
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
                    <fieldset wire:key="sales-order-line-{{ $index }}" class="orders-line-card">
                        <legend class="sr-only">البند {{ $index + 1 }}</legend>
                        <div class="orders-line-header">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-bold text-slate-900">البند {{ $index + 1 }}</h3>
                                @if(! empty($line['sku']))
                                    <span class="orders-badge" data-size="sm" data-tone="neutral"><bdi>{{ $line['sku'] }}</bdi></span>
                                @endif
                                @if(!empty($line['spec_model']))
                                    <span class="orders-badge" data-size="sm" data-tone="neutral"><bdi>{{ $line['spec_model'] }}</bdi></span>
                                @endif
                                @if(!empty($line['spec_ampere']))
                                    <span class="orders-badge" data-size="sm" data-tone="info"><bdi>{{ $line['spec_ampere'] }}</bdi></span>
                                @endif
                                @if(!empty($line['spec_kilo']))
                                    <span class="orders-badge" data-size="sm" data-tone="info"><bdi>{{ $line['spec_kilo'] }}</bdi></span>
                                @endif
                                @if($flags !== [])
                                    <span class="orders-badge" data-size="sm" data-tone="warning">
                                        @if(in_array('price', $flags, true) && in_array('discount', $flags, true))
                                            استثناء سعر وخصم
                                        @elseif(in_array('price', $flags, true))
                                            سعر مختلف عن المرجعي
                                        @else
                                            خصم على البند
                                        @endif
                                    </span>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                <flux:button type="button" size="xs" variant="ghost" icon="arrow-up" wire:click="moveLine({{ $index }}, -1)" :disabled="$loop->first" aria-label="تحريك البند للأعلى" />
                                <flux:button type="button" size="xs" variant="ghost" icon="arrow-down" wire:click="moveLine({{ $index }}, 1)" :disabled="$loop->last" aria-label="تحريك البند للأسفل" />
                                <flux:button type="button" size="xs" variant="ghost" icon="document-duplicate" wire:click="duplicateLine({{ $index }})" aria-label="نسخ البند">نسخ</flux:button>
                                <flux:button type="button" size="xs" variant="ghost" icon="trash" wire:click="removeLine({{ $index }})" :disabled="count($lines) <= 1" aria-label="حذف البند {{ $index + 1 }}">حذف</flux:button>
                            </div>
                        </div>

                        <div class="mb-3 rounded-xl border border-blue-100/90 bg-blue-50/35 p-3">
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <span class="text-xs font-bold text-blue-950">1. مسار اختيار الصنف والمواصفات الفنية (التصنيف ← الماركة ← الطراز والنوع ← الأمبير ← أمر الكيلو)</span>
                                <span class="text-[11px] text-slate-500">قابلة للتعديل في أي وقت من البداية</span>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <flux:select wire:model.live="lines.{{ $index }}.category_id" label="التصنيف (مثل: قواطع / كابلات)">
                                    <option value="">كل التصنيفات</option>
                                    @foreach($categories ?? [] as $cat)
                                        <option value="{{ $cat['id'] }}">{{ $cat['name'] }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model.live="lines.{{ $index }}.brand_id" label="الماركة / العلامة التجارية">
                                    <option value="">كل الماركات</option>
                                    @foreach($brands ?? [] as $br)
                                        <option value="{{ $br['id'] }}">{{ $br['name'] }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model.live="lines.{{ $index }}.product_id" label="اختيار الصنف" search-model="productSearch" required>
                                    <option value="">اختر صنفًا ({{ $lineProducts->count() }} متاح)</option>
                                    @foreach($lineProducts as $product)
                                        <option value="{{ $product['id'] }}">{{ $product['name'] }} · {{ $product['sku'] }}</option>
                                    @endforeach
                                </flux:select>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <flux:input wire:model="lines.{{ $index }}.spec_model" label="الطرازات / الموديل" placeholder="مثال: MCCB-3P-100A" maxlength="200" />
                                <flux:input wire:model="lines.{{ $index }}.spec_type" label="النوع / الأقطاب" placeholder="مثال: ثلاثي 3P / هوائي / حراري" maxlength="200" />
                                <flux:input wire:model="lines.{{ $index }}.spec_ampere" label="الأمبير" placeholder="مثال: 100 A" maxlength="120" />
                                <flux:input wire:model="lines.{{ $index }}.spec_kilo" label="أمر الكيلو (kA / kW)" placeholder="مثال: 36 kA أو 15 kW" maxlength="120" />
                            </div>
                        </div>

                        <div class="orders-line-grid" data-cols="5">
                            <flux:select wire:model.live="lines.{{ $index }}.unit_id" label="الوحدة" required>
                                <option value="">اختر الوحدة</option>
                                @foreach($units as $unit)
                                    <option value="{{ $unit['id'] }}">{{ $unit['name'] }} ({{ $unit['code'] }})</option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model.live.debounce.500ms="lines.{{ $index }}.quantity" label="الكمية" inputmode="decimal" required />
                            <flux:input wire:model.live.debounce.500ms="lines.{{ $index }}.requested_price" label="السعر المقترح (ج.م)" inputmode="decimal" placeholder="السعر المرجعي افتراضيًا" />
                            <flux:select wire:model.live="lines.{{ $index }}.discount_mode" label="نوع الخصم">
                                <option value="percent">نسبة %</option>
                                <option value="fixed">مبلغ ج.م</option>
                            </flux:select>
                            <flux:input wire:model.live.debounce.500ms="lines.{{ $index }}.discount_value" label="قيمة الخصم" inputmode="decimal" />
                        </div>

                        <div class="mt-3 grid gap-3 md:grid-cols-2 rounded-xl border border-slate-200/80 bg-slate-50/70 p-3">
                            <flux:textarea rows="2" wire:model="lines.{{ $index }}.serial_reference" label="تسجيل السيريال (اختياري)" placeholder="أدخل السيريال أو رقم لكل سطر إن وجد…" />
                            <flux:textarea rows="2" wire:model="lines.{{ $index }}.accessories_note" label="تسجيل الملحقات (اختياري)" placeholder="مثال: نقاط مساعدة، ملف فصل Shunt Trip، يد تشغيل، كوفريه…" />
                        </div>

                        <details class="mt-3 rounded-lg border border-slate-200/80 bg-slate-50/70 px-3 py-2" @if($hasExtra || $flags !== []) open @endif>
                            <summary class="cursor-pointer text-xs font-semibold text-slate-600">وصف مخصص وسبب تعديل السعر (اختياري)</summary>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                <flux:input wire:model="lines.{{ $index }}.description" label="وصف الصنف في الأمر" />
                                <flux:input wire:model="lines.{{ $index }}.override_reason" label="سبب السعر المقترح / الخصم" />
                            </div>
                        </details>

                        <div class="erp-order-line-summary">
                            <p>السعر المرجعي: <bdi>{{ ($line['suggested_price'] ?? '') !== '' && ($line['suggested_price'] ?? null) !== null ? D::money((string) $line['suggested_price']).' ج.م' : 'يتحدد عند التحقق من السعر' }}</bdi></p>
                            <p>صافي البند: <strong><bdi>{{ D::money($amounts['lines'][$index]['net'] ?? null) }} ج.م</bdi></strong></p>
                        </div>
                    </fieldset>
                @endforeach
            </div>
            @if($amountErrors)
                <ul class="mt-3 list-inside list-disc text-sm text-red-700">
                    @foreach($amountErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif
        </x-erp.section-card>

        <div class="grid gap-4 xl:grid-cols-2">
            <x-erp.section-card title="الشروط والملاحظات" icon="clipboard-document-list">
                <div class="space-y-4">
                    <div class="space-y-2 rounded-lg border border-slate-200/80 p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-slate-700">شروط الدفع</span>
                            <span class="orders-badge" data-size="sm" data-tone="{{ $termStatus['payment_standard'] ? 'success' : 'warning' }}">
                                {{ $termStatus['payment_standard'] ? 'مطابق لقالب معتمد' : 'نص مخصص — يتطلب قرار استثناء' }}
                            </span>
                        </div>
                        @if($paymentTerms->isNotEmpty())
                            <flux:select wire:model.live="paymentTermId" label="قالب شروط الدفع المعتمد">
                                <option value="">اختر قالبًا معتمدًا أو اكتب نصًا مخصصًا</option>
                                @foreach($paymentTerms as $term)
                                    <option value="{{ $term->id }}">{{ $term->name }} ({{ $term->code }} · إصدار {{ $term->revision }})</option>
                                @endforeach
                            </flux:select>
                        @endif
                        <flux:textarea wire:model.live.debounce.500ms="form.payment_terms" label="نص شروط الدفع" rows="2" />
                    </div>

                    <div class="space-y-2 rounded-lg border border-slate-200/80 p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-slate-700">شروط التسليم</span>
                            <span class="orders-badge" data-size="sm" data-tone="{{ $termStatus['delivery_standard'] ? 'success' : 'warning' }}">
                                {{ $termStatus['delivery_standard'] ? 'مطابق لقالب معتمد' : 'نص مخصص — يتطلب قرار استثناء' }}
                            </span>
                        </div>
                        @if($deliveryTerms->isNotEmpty())
                            <flux:select wire:model.live="deliveryTermId" label="قالب شروط التسليم المعتمد">
                                <option value="">اختر قالبًا معتمدًا أو اكتب نصًا مخصصًا</option>
                                @foreach($deliveryTerms as $term)
                                    <option value="{{ $term->id }}">{{ $term->name }} ({{ $term->code }} · إصدار {{ $term->revision }})</option>
                                @endforeach
                            </flux:select>
                        @endif
                        <flux:textarea wire:model.live.debounce.500ms="form.delivery_terms" label="نص شروط التسليم" rows="2" />
                    </div>

                    <flux:textarea wire:model="form.notes" label="ملاحظات داخلية" rows="2" />
                </div>
            </x-erp.section-card>

            <x-erp.section-card title="ملخص المسودة والخصم" icon="banknotes">
                <div class="mb-4 grid grid-cols-2 gap-3">
                    <flux:select wire:model.live="form.discount_mode" label="نوع خصم الأمر">
                        <option value="percent">نسبة %</option>
                        <option value="fixed">مبلغ ثابت ج.م</option>
                    </flux:select>
                    <flux:input wire:model.live.debounce.400ms="form.discount_value" label="قيمة خصم الأمر" inputmode="decimal" />
                </div>
                <div class="mb-4 space-y-3 border-t border-slate-100 pt-3">
                    <flux:select wire:model.live="form.tax_treatment" label="المعاملة الضريبية لأمر البيع">
                        @if(($form['tax_treatment'] ?? 'vat_14') === 'rule')
                            <option value="rule">وفق القاعدة الضريبية للشركة</option>
                        @endif
                        <option value="vat_14">خاضع لضريبة 14%</option>
                        <option value="exempt_0">بدون ضريبة (0%)</option>
                    </flux:select>
                    @if(($form['tax_treatment'] ?? 'vat_14') === 'exempt_0')
                        <flux:textarea wire:model="form.tax_exempt_reason" label="سبب اختيار بدون ضريبة (إلزامي)" rows="2" placeholder="اكتب السبب الواضح لعدم احتساب الضريبة (5 أحرف على الأقل)…" required />
                    @endif
                </div>
                @if($termStatus['order_discount'])
                    <p class="mb-3 text-xs text-amber-800">الخصم الإجمالي على الأمر يُسجّل ضمن الاستثناءات التجارية عند المراجعة.</p>
                @endif
                @include('sales.orders.partials.amounts')
            </x-erp.section-card>
        </div>

        <div class="erp-form-footer">
            <div class="flex flex-wrap items-center gap-3 text-xs">
                <span class="text-slate-600">البنود: <strong>{{ count($lines) }}</strong></span>
                <span class="text-slate-600">الصافي قبل الضرائب: <strong class="text-sm text-slate-900 tabular-nums"><bdi>{{ D::money($amounts['net'] ?? null) }}</bdi> ج.م</strong></span>
                @if($exceptionCount > 0)
                    <span class="orders-badge" data-size="sm" data-tone="warning">{{ $exceptionCount }} بنود/شروط تحتاج قرار استثناء عند المراجعة</span>
                @else
                    <span class="orders-badge" data-size="sm" data-tone="success">مطابق تجاريًا بدون استثناءات</span>
                @endif
            </div>
            <flux:button type="submit" variant="primary" icon="bookmark-square" wire:loading.attr="disabled" wire:target="save">حفظ أمر البيع</flux:button>
        </div>
    </form>
</div>
