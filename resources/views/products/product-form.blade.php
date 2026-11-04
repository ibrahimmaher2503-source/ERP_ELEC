<div data-erp-archetype="form" class="space-y-6" dir="rtl">
    @php
        $kindLabels = ['serialized_equipment' => 'معدات برقم تسلسلي', 'bulk' => 'مواد بالكمية', 'service' => 'خدمة'];
        $trackingLabels = ['serial' => 'تتبع الرقم التسلسلي', 'quantity' => 'تتبع الكمية', 'none' => 'بدون تتبع مخزني'];
        $formActive = filter_var($form['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $selectedCategory = $categories->firstWhere('id', $form['category_id'] ?? null);
        $baseUnit = $units->firstWhere('id', $form['base_unit_id'] ?? null);
        $savedConversionUnitIds = $productId ? collect($conversions)->pluck('unit_id')->all() : [];
        $conversionUnits = $baseUnit ? $units->where('dimension', $baseUnit->dimension)->where('id', '!=', $baseUnit->id)->filter(fn ($unit) => $unit->active || in_array($unit->id, $savedConversionUnitIds, false)) : collect();
        $previewUpload = $canMedia ? collect($uploads)->first(fn ($file) => str_starts_with((string) $file->getMimeType(), 'image/')) : null;
        $savedPreviewImage = $media->first(fn ($file) => $file->is_image);
    @endphp

    <x-page.heading :title="$productId ? 'تعديل بيانات الصنف' : 'إضافة صنف جديد'" description="أدخل هوية الصنف ووحداته ومواصفاته" :breadcrumbs="['المنتجات', 'الأصناف', $productId ? 'تعديل الصنف' : 'إضافة صنف']">
        <x-slot:actions>
            @if ($productId)<flux:button :href="route('products.show', $productId)" icon="eye" wire:navigate>عرض الصنف</flux:button>@endif
            <flux:button :href="route('products.index')" icon="arrow-right" wire:navigate>العودة للأصناف</flux:button>
        </x-slot:actions>
    </x-page.heading>

    @if (session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif

    <form wire:submit="save" class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_19rem]">
        <x-ui.validation-summary />
        <div class="space-y-5">
            <x-erp.section-card title="المعلومات الأساسية">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <flux:input wire:model.live="form.name" label="اسم الصنف" required maxlength="200" />
                    <flux:input wire:model.live="form.name_en" label="الاسم بالإنجليزية" maxlength="200" dir="ltr" />
                    <flux:input wire:model.live="form.sku" label="رمز الصنف (SKU)" required maxlength="50" dir="ltr" :disabled="(bool) $productId" />
                    <flux:input wire:model.live="form.barcode" label="الباركود" maxlength="100" dir="ltr" />
                    <flux:select wire:model.live="form.kind" label="نوع الصنف" required :disabled="(bool) $productId">
                        <option value="">اختر النوع</option>
                        @foreach ($kindLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model.live="form.tracking" label="سياسة التتبع" required :disabled="(bool) $productId">
                        <option value="">اختر سياسة التتبع</option>
                        @foreach ($trackingLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </flux:select>
                </div>
                <div class="mt-4 grid gap-4">
                    <flux:input wire:model.live="form.short_description" label="وصف مختصر" maxlength="500" />
                    <flux:textarea wire:model.live="form.description" label="الوصف والمواصفات" rows="4" maxlength="10000" />
                </div>
                @if ($productId && $canStatus)
                    <div class="mt-4 max-w-xs"><flux:select wire:model.live="form.active" label="الحالة" required><option value="1">نشط</option><option value="0">غير نشط</option></flux:select></div>
                @endif
            </x-erp.section-card>

            <x-erp.section-card title="التصنيف والهيكل">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <flux:select wire:model.live="form.category_id" label="التصنيف">
                        <option value="">بدون تصنيف</option>
                        @foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) ($form['category_id'] ?? '') === (string) $category->id) @disabled(! $category->active && (string) ($form['category_id'] ?? '') !== (string) $category->id)>{{ $category->parent_id ? '↳ ' : '' }}{{ $category->name }}{{ $category->active ? '' : ' · غير نشط' }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model.live="form.brand_id" label="العلامة التجارية">
                        <option value="">بدون علامة تجارية</option>
                        @foreach ($brands as $brand)<option value="{{ $brand->id }}" @selected((string) ($form['brand_id'] ?? '') === (string) $brand->id) @disabled(! $brand->active && (string) ($form['brand_id'] ?? '') !== (string) $brand->id)>{{ $brand->name }}{{ $brand->active ? '' : ' · غير نشط' }}</option>@endforeach
                    </flux:select>
                    <flux:input wire:model.live="form.model" label="الموديل" maxlength="200" />
                </div>
            </x-erp.section-card>

            <x-erp.section-card title="وحدات التحويل">
                <p class="mb-4 text-sm text-slate-500">أضف وحدات بديلة ومعامل التحويل إلى الوحدة الأساسية.</p>
                @if ($productId)<flux:callout color="blue" class="mb-4">تُحفظ وحدات التحويل ووحدتا الشراء والبيع مع إنشاء الصنف، ثم تُقفل لحماية الكميات المسجلة.</flux:callout>@endif
                <div class="mb-4 max-w-sm">
                    <flux:select wire:model.live="form.base_unit_id" label="الوحدة الأساسية" required :disabled="(bool) $productId">
                        <option value="">اختر الوحدة الأساسية</option>
                        @foreach ($units as $unit)<option value="{{ $unit->id }}" @selected((string) ($form['base_unit_id'] ?? '') === (string) $unit->id) @disabled(! $unit->active && (string) ($form['base_unit_id'] ?? '') !== (string) $unit->id)>{{ $unit->name }} ({{ $unit->code }}){{ $unit->active ? '' : ' · غير نشط' }}</option>@endforeach
                    </flux:select>
                </div>
                <div class="space-y-3">
                    @forelse ($conversions as $index => $conversion)
                        @php($conversionUnit = $units->firstWhere('id', $conversion['unit_id'] ?? null))
                        <div wire:key="conversion-{{ $index }}" class="grid items-end gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                            <flux:select wire:model.live="conversions.{{ $index }}.unit_id" label="وحدة التحويل" required :disabled="(bool) $productId">
                                <option value="">اختر الوحدة</option>
                                @foreach ($conversionUnits as $unit)<option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->code }})</option>@endforeach
                            </flux:select>
                            <flux:input wire:model.live="conversions.{{ $index }}.factor" type="number" min="0.00000001" step="0.00000001" label="عدد الوحدات الأساسية" required :disabled="(bool) $productId" />
                            @unless ($productId)<flux:button type="button" variant="ghost" icon="trash" aria-label="حذف وحدة التحويل" wire:click="removeConversion({{ $index }})" />@endunless
                            <p class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600 sm:col-span-2">
                                @if ($conversionUnit && ($conversion['factor'] ?? '') !== '')
                                    1 {{ $conversionUnit->name }} = <bdi class="font-semibold text-slate-800">{{ $conversion['factor'] }}</bdi> {{ $baseUnit?->name ?: 'الوحدة الأساسية' }}
                                @elseif ($conversionUnit)
                                    أدخل معامل التحويل لوحدة {{ $conversionUnit->name }}.
                                @else
                                    اختر وحدة التحويل لعرض العلاقة مع الوحدة الأساسية.
                                @endif
                            </p>
                        </div>
                    @empty
                        <p class="rounded-lg border border-dashed border-slate-300 px-4 py-5 text-center text-sm text-slate-500">لا توجد وحدات تحويل مضافة.</p>
                    @endforelse
                </div>
                @unless ($productId)<div class="mt-4"><flux:button type="button" variant="outline" icon="plus" wire:click="addConversion">إضافة وحدة تحويل</flux:button></div>@endunless
                <div class="mt-5 border-t border-slate-100 pt-5">
                    <p class="mb-4 text-sm text-slate-500">يمكن تحديد وحدات الشراء والبيع. عند تركهما فارغتين، تُستخدم الوحدة الأساسية.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model.live="form.purchase_unit_id" label="وحدة الشراء" :disabled="(bool) $productId">
                            <option value="">{{ $baseUnit?->name ?: 'الوحدة الأساسية' }} (افتراضي)</option>
                            @if ($baseUnit)<option value="{{ $baseUnit->id }}" @selected((string) ($form['purchase_unit_id'] ?? '') === (string) $baseUnit->id)>{{ $baseUnit->name }} ({{ $baseUnit->code }})</option>@endif
                            @foreach ($conversionUnits as $unit)<option value="{{ $unit->id }}" @selected((string) ($form['purchase_unit_id'] ?? '') === (string) $unit->id)>{{ $unit->name }} ({{ $unit->code }})</option>@endforeach
                        </flux:select>
                        <flux:select wire:model.live="form.sales_unit_id" label="وحدة البيع" :disabled="(bool) $productId">
                            <option value="">{{ $baseUnit?->name ?: 'الوحدة الأساسية' }} (افتراضي)</option>
                            @if ($baseUnit)<option value="{{ $baseUnit->id }}" @selected((string) ($form['sales_unit_id'] ?? '') === (string) $baseUnit->id)>{{ $baseUnit->name }} ({{ $baseUnit->code }})</option>@endif
                            @foreach ($conversionUnits as $unit)<option value="{{ $unit->id }}" @selected((string) ($form['sales_unit_id'] ?? '') === (string) $unit->id)>{{ $unit->name }} ({{ $unit->code }})</option>@endforeach
                        </flux:select>
                    </div>
                </div>
            </x-erp.section-card>

            <x-erp.section-card title="المواصفات والخصائص">
                <p class="mb-4 text-sm text-slate-500">سجّل الخصائص المعتمدة لهذا الصنف، مع وحدة قياس عند الحاجة.</p>
                <div class="space-y-3">
                    @forelse ($specifications as $index => $specification)
                        <div wire:key="attribute-{{ $index }}" class="grid items-end gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,.8fr)_auto]">
                            <flux:input wire:model="specifications.{{ $index }}.name" label="اسم الخاصية" required maxlength="100" />
                            <flux:input wire:model="specifications.{{ $index }}.value" label="القيمة" required maxlength="1000" />
                            <flux:select wire:model="specifications.{{ $index }}.unit_id" label="الوحدة">
                                <option value="">بدون وحدة</option>
                                @foreach ($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach
                            </flux:select>
                            <flux:button type="button" variant="ghost" icon="trash" aria-label="حذف الخاصية" wire:click="removeAttribute({{ $index }})" />
                        </div>
                    @empty
                        <p class="rounded-lg border border-dashed border-slate-300 px-4 py-5 text-center text-sm text-slate-500">لا توجد خصائص مضافة.</p>
                    @endforelse
                </div>
                <div class="mt-4"><flux:button type="button" variant="outline" icon="plus" wire:click="addAttribute">إضافة خاصية</flux:button></div>
            </x-erp.section-card>

            @if ($canMedia)
                <x-erp.section-card title="الصور والمرفقات">
                    <label class="flex cursor-pointer flex-col items-center gap-3 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center hover:border-blue-400 focus-within:ring-2 focus-within:ring-blue-500">
                        <flux:icon name="cloud-arrow-up" class="size-8 text-blue-600" />
                        <span><span class="block font-medium">اختر الملفات لإرفاقها بالصنف</span><span class="mt-1 block text-xs text-slate-500">JPG وPNG وWebP وPDF، حتى 5 ميجابايت للملف</span></span>
                        <input class="sr-only" type="file" wire:model="uploads" multiple accept=".jpg,.jpeg,.png,.webp,.pdf">
                    </label>
                    @foreach ($errors->get('uploads.*') as $uploadErrors)
                        @foreach ($uploadErrors as $message)<flux:callout variant="danger" class="mt-3">{{ $message }}</flux:callout>@endforeach
                    @endforeach
                    @foreach ($uploads as $index => $upload)
                        <div wire:key="upload-{{ $index }}" class="mt-3 flex items-center gap-3 rounded-md border border-slate-200 p-3 text-sm">
                            @if (str_starts_with((string) $upload->getMimeType(), 'image/'))<img src="{{ $upload->temporaryUrl() }}" alt="معاينة {{ $upload->getClientOriginalName() }}" class="size-12 rounded object-cover">@else<flux:icon name="document" class="size-7 text-slate-500" />@endif
                            <span class="min-w-0 flex-1 truncate">{{ $upload->getClientOriginalName() }}</span>
                        </div>
                    @endforeach
                    @if ($media->isNotEmpty())
                        <div class="mt-5 space-y-2">
                            <flux:heading size="sm">الملفات الحالية</flux:heading>
                            @foreach ($media as $file)
                                <div wire:key="media-{{ $file->id }}" class="flex items-center gap-3 rounded-md border border-slate-200 p-3 text-sm">
                                    @if ($file->is_image)<img src="{{ route('products.media', $file->id) }}" alt="" class="size-12 rounded object-cover">@else<flux:icon name="document" class="size-7 text-slate-500" />@endif
                                    <a href="{{ route('products.media.download', $file->id) }}" class="min-w-0 flex-1 truncate text-blue-700 hover:underline">{{ $file->name }}</a>
                                    <flux:button type="button" variant="ghost" icon="trash" aria-label="إزالة {{ $file->name }}" wire:click="removeMedia({{ $file->id }})" />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-erp.section-card>
            @endif

            @if ($canNotes)
                <x-erp.section-card title="ملاحظات داخلية">
                        <flux:textarea wire:model="form.internal_notes" rows="4" maxlength="10000" label="ملاحظات لا تظهر للمستخدمين غير المخولين" />
                </x-erp.section-card>
            @endif

            <div class="grid gap-5 sm:grid-cols-2">
                <x-erp.section-card title="التسعير">
                    <div class="flex items-start gap-3 text-sm text-slate-600"><flux:icon name="banknotes" class="mt-0.5 size-5 shrink-0 text-slate-400" /><div class="space-y-2"><p>تُدار أسعار الصنف من صفحة الأسعار بعد حفظ الصنف.</p>@if($productId && \Illuminate\Support\Facades\Route::has('products.prices'))<flux:button :href="route('products.prices', $productId)" size="sm">فتح صفحة الأسعار</flux:button>@endif</div></div>
                </x-erp.section-card>
                <x-erp.section-card title="المخزون">
                    <div class="flex items-start gap-3 text-sm text-slate-600"><flux:icon name="building-storefront" class="mt-0.5 size-5 shrink-0 text-slate-400" /><div class="space-y-2"><p>تظهر أرصدة المخزون في صفحة الأرصدة.</p>@if(\Illuminate\Support\Facades\Route::has('inventory.balances'))<flux:button :href="route('inventory.balances')" size="sm">فتح صفحة الأرصدة</flux:button>@endif</div></div>
                </x-erp.section-card>
            </div>
        </div>

        <aside class="space-y-5 xl:sticky xl:top-5">
            <x-ui.panel>
                <div class="flex items-center gap-2"><flux:icon name="eye" class="size-5 text-blue-600" /><flux:heading size="lg">معاينة الصنف</flux:heading></div>
                @if ($previewUpload)<img src="{{ $previewUpload->temporaryUrl() }}" alt="معاينة صورة الصنف" class="mt-4 max-h-52 w-full rounded-lg border border-slate-200 bg-slate-50 object-contain">@elseif ($savedPreviewImage)<img src="{{ route('products.media', $savedPreviewImage->id) }}" alt="{{ $form['name'] ?: 'الصنف' }}" class="mt-4 max-h-52 w-full rounded-lg border border-slate-200 bg-slate-50 object-contain">@else<div class="mt-4 flex h-36 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 text-slate-400"><flux:icon name="cube" class="size-12" /></div>@endif
                <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0"><h2 class="break-words font-semibold text-slate-900">{{ $form['name'] ?: 'اسم الصنف' }}</h2><p class="mt-1 text-xs text-slate-500"><bdi>{{ $form['sku'] ?: 'SKU' }}</bdi></p></div>
                        <x-erp.status-badge :active="$formActive" />
                    </div>
                    <dl class="mt-4 space-y-3 border-t border-slate-200 pt-4 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">التصنيف</dt><dd class="text-end font-medium">{{ $selectedCategory?->name ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">النوع</dt><dd class="text-end font-medium">{{ $kindLabels[$form['kind'] ?? ''] ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">التتبع</dt><dd class="text-end font-medium">{{ $trackingLabels[$form['tracking'] ?? ''] ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">الوحدة الأساسية</dt><dd class="text-end font-medium">{{ $units->firstWhere('id', $form['base_unit_id'] ?? null)?->name ?: '—' }}</dd></div>
                        @if (! empty($form['barcode']))<div class="flex justify-between gap-3"><dt class="text-slate-500">الباركود</dt><dd class="text-end font-medium"><bdi>{{ $form['barcode'] }}</bdi></dd></div>@endif
                    </dl>
                </div>
            </x-ui.panel>

            <x-ui.panel>
                <flux:heading size="lg">حالة الصنف</flux:heading>
                <div class="mt-4"><x-erp.status-badge :active="$formActive" /></div>
                <flux:text class="mt-2">تعطيل الصنف لا يحذف بياناته أو تاريخه.</flux:text>
            </x-ui.panel>
        </aside>

        <div class="flex flex-wrap items-center justify-between gap-3 xl:col-span-2">
            <flux:button :href="route('products.index')" variant="ghost" wire:navigate>إلغاء</flux:button>
            @if ($productId ? $canUpdate : $canCreate)
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">{{ $productId ? 'حفظ التعديلات' : 'حفظ الصنف' }}</flux:button>
            @endif
        </div>
    </form>
</div>
