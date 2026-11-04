<div data-erp-archetype="form" class="sales-order-form space-y-4">
    <x-page.heading :title="$orderId ? 'تعديل أمر البيع' : 'إنشاء أمر بيع مباشر'" description="حدد العميل والبنود والأسعار، ثم احفظ الأمر وقدمه للمراجعة" :breadcrumbs="['المبيعات', 'أوامر البيع', $orderId ? 'تعديل المسودة' : 'إنشاء أمر بيع']">
        <x-slot:actions>
            <flux:button :href="route('sales-orders.index')" wire:navigate icon="arrow-right">العودة إلى أوامر البيع</flux:button>
            <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" icon="bookmark-square">حفظ أمر البيع</flux:button>
        </x-slot:actions>
    </x-page.heading>

    @include('sales.orders.partials.feedback')

    <form wire:submit="save" class="erp-document-form space-y-5">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-erp.section-card title="معلومات الأمر" icon="document-text">
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input value="جنيه مصري (ج.م)" label="العملة" disabled />
                    <flux:select wire:model="form.branch_id" label="الفرع" required>
                        <option value="">اختر فرعًا مصرحًا به</option>
                        @foreach($branches as $branch)<option value="{{ $branch['id'] }}">{{ $branch['name'] }} ({{ $branch['code'] }})</option>@endforeach
                    </flux:select>
                    <flux:input type="date" wire:model="form.order_date" label="تاريخ الأمر" required />
                    <flux:input type="date" wire:model="form.requested_delivery_date" label="تاريخ التسليم المطلوب" />
                    <flux:input field:class="sm:col-span-2" wire:model="form.customer_reference" label="مرجع العميل" maxlength="100" />
                </div>
            </x-erp.section-card>

            <x-erp.section-card title="معلومات العميل" icon="users">
                <div class="space-y-3">
                    <flux:select wire:model="form.customer_id" label="العميل" search-model="customerSearch" required>
                        <option value="">اختر عميلًا نشطًا</option>
                        @foreach($customers as $customer)<option value="{{ $customer['id'] }}">{{ $customer['name'] }} ({{ $customer['code'] }})</option>@endforeach
                    </flux:select>
                    <flux:input wire:model="form.project_name" label="اسم المشروع (اختياري)" maxlength="200" />
                    <p class="erp-form-hint">اسم المشروع مرجع نصي. يصدر الرقم الرسمي عند اعتماد الأمر.</p>
                </div>
            </x-erp.section-card>
        </div>

        <x-erp.section-card title="بنود أمر البيع" icon="rectangle-stack">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-500">يعرض السعر المعتمد للصنف والوحدة والتاريخ عند توفره وصلاحية عرضه. يعاد التحقق من مصدر السعر عند الحفظ.</p>
                <flux:button type="button" wire:click="addLine" icon="plus" size="sm" variant="primary">إضافة بند</flux:button>
            </div>
            <div class="space-y-5">
                @foreach($lines as $index => $line)
                    <fieldset wire:key="sales-order-line-{{ $index }}" class="erp-order-line">
                        <legend class="sr-only">البند {{ $index + 1 }}</legend>
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold">البند {{ $index + 1 }} <bdi class="ms-2 text-xs font-normal text-slate-500">{{ $line['sku'] ?: '' }}</bdi></h3>
                            <flux:button type="button" size="sm" variant="ghost" icon="trash" wire:click="removeLine({{ $index }})" aria-label="حذف البند {{ $index + 1 }}">حذف البند</flux:button>
                        </div>
                        <div class="erp-sales-line-grid">
                            <flux:select wire:model.live="lines.{{ $index }}.product_id" label="الصنف" search-model="productSearch" required><option value="">اختر صنفًا</option>@foreach($products as $product)<option value="{{ $product['id'] }}">{{ $product['name'] }} · {{ $product['sku'] }}</option>@endforeach</flux:select>
                            <flux:select wire:model="lines.{{ $index }}.unit_id" label="الوحدة" required><option value="">اختر الوحدة</option>@foreach($units as $unit)<option value="{{ $unit['id'] }}">{{ $unit['name'] }} ({{ $unit['code'] }})</option>@endforeach</flux:select>
                            <flux:input wire:model.live.debounce.500ms="lines.{{ $index }}.quantity" label="الكمية" inputmode="decimal" required />
                            <flux:input wire:model.live.debounce.500ms="lines.{{ $index }}.requested_price" label="السعر المقترح (ج.م)" inputmode="decimal" placeholder="السعر المرجعي عند تركه فارغًا" />
                            <flux:select wire:model.live="lines.{{ $index }}.discount_mode" label="نوع الخصم"><option value="percent">نسبة %</option><option value="fixed">مبلغ ج.م</option></flux:select>
                            <flux:input wire:model.live.debounce.500ms="lines.{{ $index }}.discount_value" label="قيمة الخصم" inputmode="decimal" />
                        </div>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <flux:input wire:model="lines.{{ $index }}.description" label="وصف الصنف في الأمر" />
                            <flux:input wire:model="lines.{{ $index }}.override_reason" label="سبب السعر المقترح (اختياري)" />
                        </div>
                        <div class="erp-order-line-summary"><p>السعر المرجعي: <bdi>{{ ($line['suggested_price'] ?? '') !== '' && ($line['suggested_price'] ?? null) !== null ? \App\Modules\Sales\Application\DraftAmounts::display((string) $line['suggested_price']).' ج.م' : 'يتحدد عند التحقق من السعر' }}</bdi></p><p>صافي البند: <strong><bdi>{{ \App\Modules\Sales\Application\DraftAmounts::display($amounts['lines'][$index]['net'] ?? null) }} ج.م</bdi></strong></p></div>
                    </fieldset>
                @endforeach
            </div>
            @if($amountErrors)<ul class="mt-3 list-inside list-disc text-sm text-red-700">@foreach($amountErrors as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
        </x-erp.section-card>

        <div class="grid gap-4 xl:grid-cols-2">
            <x-erp.section-card title="الشروط والملاحظات" icon="clipboard-document-list">
                <div class="space-y-3"><flux:textarea wire:model="form.payment_terms" label="شروط الدفع المقترحة" rows="3" /><flux:textarea wire:model="form.delivery_terms" label="شروط التسليم المقترحة" rows="3" /><flux:textarea wire:model="form.notes" label="ملاحظات داخلية" rows="3" /></div>
            </x-erp.section-card>
            <x-erp.section-card title="ملخص المسودة" icon="banknotes">
                <div class="mb-4 grid grid-cols-2 gap-3"><flux:select wire:model.live="form.discount_mode" label="نوع خصم الأمر"><option value="percent">نسبة %</option><option value="fixed">مبلغ ثابت ج.م</option></flux:select><flux:input wire:model.live.debounce.400ms="form.discount_value" label="قيمة خصم الأمر" inputmode="decimal" /></div>
                @include('sales.orders.partials.amounts')
            </x-erp.section-card>
        </div>

        <div class="erp-form-footer">
            <p>الحفظ يجهز الأمر للمراجعة. الاعتماد والحجز من صفحة التفاصيل.</p>
            <flux:button type="submit" variant="primary" icon="bookmark-square" wire:loading.attr="disabled">حفظ أمر البيع</flux:button>
        </div>
    </form>
</div>
