<div class="sales-order-form space-y-4" x-data="{
    dirty: false,
    stopSaveRedirect: null,
    init() {
        this.stopSaveRedirect = this.$wire.$interceptRequest('save', ({ onRedirect }) => {
            onRedirect(() => { this.dirty = false })
        })
    },
    destroy() { this.stopSaveRedirect?.() }
}" x-on:input="dirty = true" x-on:change="dirty = true" x-on:sales-order-dirty.window="dirty = true" x-on:sales-order-saved.window="dirty = false" x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }" x-on:livewire:navigate.window="if (dirty && ! window.confirm('توجد تعديلات غير محفوظة. هل تريد مغادرة المسودة؟')) { $event.preventDefault(); }">
    <x-page.heading :title="$orderId ? 'تعديل أمر البيع' : 'إنشاء أمر بيع مباشر'" description="أدخل البيانات والأسعار المقترحة، ثم احفظ الأمر كمسودة" :breadcrumbs="['المبيعات', 'أوامر البيع', $orderId ? 'تعديل المسودة' : 'إنشاء أمر بيع']">
        <x-slot:actions>
            <flux:button :href="route('sales-orders.index')" wire:navigate icon="arrow-right">العودة إلى أوامر البيع</flux:button>
            <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" icon="bookmark-square">حفظ المسودة</flux:button>
        </x-slot:actions>
    </x-page.heading>

    @include('sales.orders.partials.feedback')

    <form wire:submit="save" class="space-y-4">
        <div class="grid gap-3 xl:grid-cols-3">
            <x-erp.section-card title="معلومات الأمر" icon="document-text">
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input value="غير مخصص في المسودة" label="رقم الأمر" disabled />
                    <flux:input value="جنيه مصري (EGP)" label="العملة" disabled />
                    <flux:select wire:model="form.branch_id" label="الفرع *" required>
                        <option value="">اختر فرعًا مصرحًا به</option>
                        @foreach($branches as $branch)<option value="{{ $branch['id'] }}">{{ $branch['name'] }} ({{ $branch['code'] }})</option>@endforeach
                    </flux:select>
                    <flux:input type="date" wire:model="form.order_date" label="تاريخ الأمر *" required />
                    <flux:input type="date" wire:model="form.requested_delivery_date" label="تاريخ التسليم المطلوب" />
                    <flux:input wire:model="form.customer_reference" label="مرجع العميل" maxlength="100" />
                </div>
            </x-erp.section-card>

            <x-erp.section-card title="معلومات العميل" icon="users">
                <div class="space-y-3">
                    <flux:input wire:model.live.debounce.300ms="customerSearch" label="البحث عن عميل" placeholder="اسم العميل أو الكود…" icon="magnifying-glass" />
                    <flux:select wire:model="form.customer_id" label="العميل *" required>
                        <option value="">اختر عميلًا نشطًا</option>
                        @foreach($customers as $customer)<option value="{{ $customer['id'] }}">{{ $customer['name'] }} ({{ $customer['code'] }})</option>@endforeach
                    </flux:select>
                    <p class="text-xs leading-5 text-slate-500">يحفظ الأمر لقطة من هوية العميل والفرع عند الحفظ.</p>
                </div>
            </x-erp.section-card>

            <x-erp.section-card title="معلومات المشروع" icon="building-office">
                <div class="space-y-3">
                    <flux:input wire:model="form.project_name" label="اسم المشروع (اختياري)" maxlength="200" />
                    <p class="text-xs leading-5 text-slate-500">المشروع والمرجع نصان يدويان؛ لا يوجد ربط بسجل مشروع.</p>
                </div>
            </x-erp.section-card>
        </div>

        <x-erp.section-card title="بنود أمر البيع" icon="rectangle-stack">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-500">الأسعار المقترحة يدوية للمسودة، ولا يتوفر مصدر سعر معتمد.</p>
                <flux:button type="button" wire:click="addLine" icon="plus" size="sm" variant="primary">إضافة بند</flux:button>
            </div>
            <div class="mb-4 max-w-lg"><flux:input wire:model.live.debounce.350ms="productSearch" label="البحث في كتالوج الأصناف" icon="magnifying-glass" placeholder="اسم الصنف أو SKU…" /></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1180px] text-sm">
                    <thead class="bg-slate-50 text-right text-xs text-slate-600"><tr><th class="px-3 py-2">#</th><th class="px-3 py-2">الصنف *</th><th class="px-3 py-2">SKU</th><th class="px-3 py-2">الوحدة *</th><th class="px-3 py-2">الكمية *</th><th class="px-3 py-2">السعر المرجعي</th><th class="px-3 py-2">السعر المقترح (ج.م)</th><th class="px-3 py-2">نوع الخصم</th><th class="px-3 py-2">الخصم</th><th class="px-3 py-2">صافي البند</th><th class="px-3 py-2">حذف</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($lines as $index => $line)
                            <tr wire:key="sales-order-line-{{ $index }}">
                                <td class="px-3 py-2">{{ $index + 1 }}</td>
                                <td class="min-w-56 px-3 py-2"><flux:select wire:model.live="lines.{{ $index }}.product_id" aria-label="الصنف للبند {{ $index + 1 }}"><option value="">اختر صنفًا</option>@foreach($products as $product)<option value="{{ $product['id'] }}">{{ $product['name'] }}</option>@endforeach</flux:select><flux:error name="lines.{{ $index }}.product_id" /></td>
                                <td class="px-3 py-2"><bdi>{{ $line['sku'] ?: '—' }}</bdi></td>
                                <td class="min-w-36 px-3 py-2"><flux:select wire:model="lines.{{ $index }}.unit_id" aria-label="الوحدة للبند {{ $index + 1 }}"><option value="">اختر</option>@foreach($units as $unit)<option value="{{ $unit['id'] }}">{{ $unit['name'] }} ({{ $unit['code'] }})</option>@endforeach</flux:select></td>
                                <td class="w-28 px-3 py-2"><flux:input wire:model.live.debounce.500ms="lines.{{ $index }}.quantity" inputmode="decimal" aria-label="كمية البند {{ $index + 1 }}" /></td>
                                <td class="whitespace-nowrap px-3 py-2 text-slate-500">{{ ($line['suggested_price'] ?? null) !== null && ($line['suggested_price'] ?? '') !== '' ? \App\Modules\Sales\Application\DraftAmounts::display((string) $line['suggested_price']).' ج.م' : 'يتحدد بعد التحقق عند الحفظ' }}</td>
                                <td class="w-36 px-3 py-2"><flux:input wire:model.live.debounce.500ms="lines.{{ $index }}.requested_price" inputmode="decimal" aria-label="السعر المقترح للبند {{ $index + 1 }}" placeholder="اختياري" /></td>
                                <td class="w-32 px-3 py-2"><flux:select wire:model.live="lines.{{ $index }}.discount_mode" aria-label="نوع خصم البند {{ $index + 1 }}"><option value="percent">نسبة %</option><option value="fixed">مبلغ ج.م</option></flux:select></td>
                                <td class="w-32 px-3 py-2"><flux:input wire:model.live.debounce.500ms="lines.{{ $index }}.discount_value" inputmode="decimal" aria-label="خصم البند {{ $index + 1 }}" /></td>
                                <td class="whitespace-nowrap px-3 py-2 font-semibold tabular-nums"><bdi>{{ \App\Modules\Sales\Application\DraftAmounts::display($amounts['lines'][$index]['net'] ?? null) }}</bdi></td>
                                <td class="px-3 py-2"><flux:button type="button" size="xs" variant="ghost" icon="trash" wire:click="removeLine({{ $index }})" aria-label="حذف البند {{ $index + 1 }}" /></td>
                            </tr>
                            <tr wire:key="sales-order-line-description-{{ $index }}" class="bg-slate-50/60"><td></td><td colspan="5" class="px-3 pb-3"><flux:input wire:model="lines.{{ $index }}.description" label="وصف الصنف في الأمر" /></td><td colspan="5" class="px-3 pb-3"><flux:input wire:model="lines.{{ $index }}.override_reason" label="ملاحظة السعر المقترح (اختياري)" /></td></tr>
                        @endforeach
                    </tbody>
                </table>
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

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
            <span class="text-sm text-slate-500">لا يوجد حفظ تلقائي. الحفظ ينشئ مسودة غير معتمدة.</span>
            <flux:button type="submit" variant="primary" icon="bookmark-square" wire:loading.attr="disabled">حفظ المسودة</flux:button>
        </div>
    </form>
</div>
