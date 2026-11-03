<div data-erp-archetype="details" class="space-y-6" dir="rtl">
    @php
        $kindLabels = ['serialized_equipment' => 'معدات برقم تسلسلي', 'bulk' => 'مواد بالكمية', 'service' => 'خدمة'];
        $trackingLabels = ['serial' => 'تتبع الرقم التسلسلي', 'quantity' => 'تتبع الكمية', 'none' => 'بدون تتبع مخزني'];
        $productUnits = collect([$product->baseUnit])->merge($product->conversions->pluck('unit'))->filter()->keyBy('id');
        $productAttributes = collect($product->attributes ?? []);
        $productImages = $canMedia ? $media->filter(fn ($file) => $file->is_image) : collect();
        $createdAt = \Carbon\CarbonImmutable::parse($product->created_at)->utc();
        $updatedAt = \Carbon\CarbonImmutable::parse($product->updated_at)->utc();
    @endphp


    <x-page.heading :title="$product->name" description="بيانات تعريف الصنف ومواصفاته" :breadcrumbs="['المنتجات', 'الأصناف', 'تفاصيل الصنف']">
        <x-slot:actions>
            @if ($canUpdate)<flux:button :href="route('products.edit', $product->id)" variant="primary" icon="pencil-square" wire:navigate>تعديل الصنف</flux:button>@endif
            <flux:button :href="route('products.index')" icon="arrow-right" wire:navigate>العودة للأصناف</flux:button>
        </x-slot:actions>
    </x-page.heading>
    <x-ui.validation-summary />

    @if (session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif

    <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="space-y-5">
            <x-ui.panel x-data="{ activeTab: 'identity' }" class="!p-0 overflow-hidden">
                <nav class="erp-tabs flex flex-wrap gap-x-2 overflow-x-auto border-b border-[#E5E8EE] px-4" aria-label="أقسام بيانات الصنف">
                    <button type="button" @click="activeTab = 'identity'" class="border-b-2 border-transparent px-3 py-4 text-sm text-slate-600 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-blue-600" :class="activeTab === 'identity' && 'border-blue-600 font-semibold text-blue-700'" :aria-pressed="activeTab === 'identity'"><flux:icon name="information-circle" class="size-4 shrink-0" />المعلومات الأساسية</button>
                    <button type="button" @click="activeTab = 'specifications'" class="border-b-2 border-transparent px-3 py-4 text-sm text-slate-600 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-blue-600" :class="activeTab === 'specifications' && 'border-blue-600 font-semibold text-blue-700'" :aria-pressed="activeTab === 'specifications'"><flux:icon name="adjustments-horizontal" class="size-4 shrink-0" />المواصفات والوحدات</button>
                    @if ($canMedia)<button type="button" @click="activeTab = 'media'" class="border-b-2 border-transparent px-3 py-4 text-sm text-slate-600 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-blue-600" :class="activeTab === 'media' && 'border-blue-600 font-semibold text-blue-700'" :aria-pressed="activeTab === 'media'"><flux:icon name="photo" class="size-4 shrink-0" />الصور والمرفقات</button>@endif
                    <button type="button" @click="activeTab = 'prices'" class="border-b-2 border-transparent px-3 py-4 text-sm text-slate-600 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-blue-600" :class="activeTab === 'prices' && 'border-blue-600 font-semibold text-blue-700'" :aria-pressed="activeTab === 'prices'"><flux:icon name="banknotes" class="size-4 shrink-0" />الأسعار</button>
                    <button type="button" @click="activeTab = 'inventory'" class="border-b-2 border-transparent px-3 py-4 text-sm text-slate-600 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-blue-600" :class="activeTab === 'inventory' && 'border-blue-600 font-semibold text-blue-700'" :aria-pressed="activeTab === 'inventory'"><flux:icon name="cube" class="size-4 shrink-0" />المخزون</button>
                    @if (isset($audit))<button type="button" @click="activeTab = 'audit'" class="border-b-2 border-transparent px-3 py-4 text-sm text-slate-600 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-blue-600" :class="activeTab === 'audit' && 'border-blue-600 font-semibold text-blue-700'" :aria-pressed="activeTab === 'audit'"><flux:icon name="clock" class="size-4 shrink-0" />سجل التغييرات</button>@endif
                    @if ($canNotes)<button type="button" @click="activeTab = 'notes'" class="border-b-2 border-transparent px-3 py-4 text-sm text-slate-600 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-blue-600" :class="activeTab === 'notes' && 'border-blue-600 font-semibold text-blue-700'" :aria-pressed="activeTab === 'notes'"><flux:icon name="document-text" class="size-4 shrink-0" />ملاحظات داخلية</button>@endif
                </nav>

                <div class="p-5 sm:p-6">
                    <section x-show="activeTab === 'identity'" aria-label="المعلومات الأساسية">
                        <flux:heading size="lg">المعلومات الأساسية</flux:heading>
                        <dl class="mt-5 grid gap-x-8 sm:grid-cols-2">
                            <x-erp.information-row label="اسم الصنف">{{ $product->name }}</x-erp.information-row>
                            @if ($product->name_en)<x-erp.information-row label="الاسم بالإنجليزية"><bdi>{{ $product->name_en }}</bdi></x-erp.information-row>@endif
                            <x-erp.information-row label="رمز الصنف (SKU)"><bdi>{{ $product->sku }}</bdi></x-erp.information-row>
                            <x-erp.information-row label="الباركود"><bdi>{{ $product->barcode ?: 'غير مسجل' }}</bdi></x-erp.information-row>
                            <x-erp.information-row label="التصنيف">{{ $product->category?->name ?: 'بدون تصنيف' }}</x-erp.information-row>
                            <x-erp.information-row label="العلامة التجارية">{{ $product->brand?->name ?: 'غير محددة' }}</x-erp.information-row>
                            <x-erp.information-row label="الموديل">{{ $product->model ?: 'غير مسجل' }}</x-erp.information-row>
                            <x-erp.information-row label="نوع الصنف">{{ $kindLabels[$product->kind] ?? '—' }}</x-erp.information-row>
                            <x-erp.information-row label="سياسة التتبع">{{ $trackingLabels[$product->tracking] ?? '—' }}</x-erp.information-row>
                            <x-erp.information-row label="الوحدة الأساسية">{{ $product->baseUnit?->name ?: '—' }}</x-erp.information-row>
                            <x-erp.information-row label="وحدة الشراء">{{ $product->purchaseUnit?->name ?: $product->baseUnit?->name ?: '—' }}</x-erp.information-row>
                            <x-erp.information-row label="وحدة البيع">{{ $product->salesUnit?->name ?: $product->baseUnit?->name ?: '—' }}</x-erp.information-row>
                            <x-erp.information-row label="الحالة"><x-erp.status-badge :active="$product->active" /></x-erp.information-row>
                            <x-erp.information-row label="الوصف المختصر">{{ $product->short_description ?: 'غير مسجل' }}</x-erp.information-row>
                            <x-erp.information-row label="تاريخ الإنشاء"><time datetime="{{ $createdAt->toIso8601String() }}">{{ $createdAt->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</time></x-erp.information-row>
                            <x-erp.information-row label="آخر تحديث"><time datetime="{{ $updatedAt->toIso8601String() }}">{{ $updatedAt->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</time></x-erp.information-row>
                        </dl>
                    </section>

                    <section x-show="activeTab === 'specifications'" x-cloak style="display: none" aria-label="المواصفات والوحدات">
                        <flux:heading size="lg">المواصفات والوحدات</flux:heading>
                        @if ($product->description)<p class="mt-4 whitespace-pre-line text-sm leading-7 text-slate-700">{{ $product->description }}</p>@endif
                        <div class="mt-5 grid gap-5 lg:grid-cols-2">
                            <div>
                                <flux:heading size="sm">الخصائص</flux:heading>
                                @if ($productAttributes->isNotEmpty())
                                    <dl class="mt-3 divide-y divide-slate-100">
                                        @foreach ($productAttributes as $attribute)
                                            <div wire:key="detail-attribute-{{ $loop->index }}" class="flex items-start justify-between gap-4 py-3 text-sm">
                                                <dt class="text-slate-500">{{ data_get($attribute, 'name') }}</dt>
                                                <dd class="text-end font-medium">{{ data_get($attribute, 'value') }}@if (data_get($attribute, 'unit_id') && $productUnits->has(data_get($attribute, 'unit_id')))<span class="ms-1 text-slate-500">{{ $productUnits->get(data_get($attribute, 'unit_id'))->name }}</span>@endif</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @else
                                    <p class="mt-3 text-sm text-slate-500">لا توجد خصائص مسجلة.</p>
                                @endif
                            </div>
                            <div>
                                <flux:heading size="sm">وحدات التحويل</flux:heading>
                                @if ($product->conversions->isNotEmpty())
                                    <div role="region" tabindex="0" aria-label="جدول تفصيلي؛ يمكن التمرير أفقياً لعرض جميع الأعمدة" class="mt-3 overflow-x-auto rounded-lg border border-slate-200">
                                        <table class="w-full text-start text-sm">
                                            <thead class="bg-slate-50 text-slate-500"><tr><th class="p-3 text-start">الوحدة</th><th class="p-3 text-start">معامل التحويل</th></tr></thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach ($product->conversions as $conversion)<tr wire:key="product-conversion-{{ $conversion->id }}"><td class="p-3">{{ $conversion->unit?->name ?: '—' }}</td><td class="p-3"><bdi>{{ $conversion->factor }}</bdi></td></tr>@endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <p class="mt-3 text-sm text-slate-500">لا توجد وحدات تحويل مسجلة.</p>
                                @endif
                            </div>
                        </div>
                    </section>

                    @if ($canMedia)
                        <section x-show="activeTab === 'media'" x-cloak style="display: none" aria-label="الصور والمرفقات">
                            <flux:heading size="lg">الصور والمرفقات</flux:heading>
                            @if ($media->isNotEmpty())
                                <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($media as $file)
                                        <article wire:key="product-media-{{ $file->id }}" class="overflow-hidden rounded-lg border border-slate-200">
                                            @if ($file->is_image)<a href="{{ route('products.media', $file->id) }}" target="_blank" rel="noopener"><img src="{{ route('products.media', $file->id) }}" alt="{{ $file->name }}" class="h-40 w-full bg-slate-50 object-contain"></a>@else<div class="flex h-40 items-center justify-center bg-slate-50 text-slate-400"><flux:icon name="document" class="size-12" /></div>@endif
                                            <div class="flex items-center justify-between gap-3 border-t border-slate-200 p-3"><span class="min-w-0 truncate text-sm">{{ $file->name }}</span><a href="{{ route('products.media.download', $file->id) }}" class="shrink-0 text-sm font-medium text-blue-700 hover:underline">تنزيل</a></div>
                                        </article>
                                    @endforeach
                                </div>
                            @else
                                <div class="mt-4"><x-ui.empty-state title="لا توجد صور أو مرفقات" description="لم تُسجل ملفات لهذا الصنف." icon="photo" /></div>
                            @endif
                        </section>
                    @endif

                    <section x-show="activeTab === 'prices'" x-cloak style="display: none" aria-label="الأسعار">
                        <flux:heading size="lg">الأسعار</flux:heading>
                        <div class="mt-4"><x-ui.empty-state title="بيانات الأسعار غير متاحة" description="ستظهر الأسعار المعتمدة وسجلها عند اكتمال تكامل إدارة الأسعار." icon="banknotes" /></div>
                    </section>

                    <section x-show="activeTab === 'inventory'" x-cloak style="display: none" aria-label="المخزون">
                        <flux:heading size="lg">المخزون</flux:heading>
                        <div class="mt-4"><x-ui.empty-state title="بيانات المخزون غير متاحة" description="لا توجد بيانات مخزون مرتبطة بهذا العرض حتى يكتمل تكامل إدارة المخزون." icon="building-storefront" /></div>
                    </section>

                    @if (isset($audit))
                        <section x-show="activeTab === 'audit'" x-cloak style="display: none" aria-label="سجل التغييرات">
                            <flux:heading size="lg">سجل التغييرات</flux:heading>
                            <div class="mt-5"><x-erp.audit-timeline :events="$audit" /></div>
                        </section>
                    @endif

                    @if ($canNotes)
                        <section x-show="activeTab === 'notes'" x-cloak style="display: none" aria-label="ملاحظات داخلية">
                            <flux:heading size="lg">ملاحظات داخلية</flux:heading>
                            <p class="mt-4 whitespace-pre-line text-sm leading-7 text-slate-700">{{ $product->internal_notes ?: 'لا توجد ملاحظات داخلية.' }}</p>
                        </section>
                    @endif
                </div>
            </x-ui.panel>
        </div>

        <aside class="space-y-5 xl:sticky xl:top-5">
            <x-ui.panel>
                @if (! $canMedia)
                    <div class="flex h-48 flex-col items-center justify-center gap-3 rounded-lg bg-slate-50 text-center text-sm text-slate-500"><flux:icon name="lock-closed" class="size-8" />معاينة الصور غير متاحة</div>
                @elseif ($productImages->isNotEmpty())
                    @php($coverImage = $productImages->first())
                    <a href="{{ route('products.media', $coverImage->id) }}" target="_blank" rel="noopener" class="block"><img src="{{ route('products.media', $coverImage->id) }}" alt="{{ $product->name }}" class="max-h-80 w-full rounded-lg bg-slate-50 object-contain"></a>
                    @if ($productImages->count() > 1)<div class="mt-3 grid grid-cols-4 gap-2">@foreach ($productImages->take(4) as $image)<a wire:key="product-thumb-{{ $image->id }}" href="{{ route('products.media', $image->id) }}" target="_blank" rel="noopener" class="rounded-md border border-slate-200 p-1"><img src="{{ route('products.media', $image->id) }}" alt="" class="aspect-square w-full rounded object-cover"></a>@endforeach</div>@endif
                @else
                    <x-ui.empty-state title="لا توجد صورة للصنف" description="يمكن إضافة صورة من قسم الصور والمرفقات." icon="photo" />
                @endif
                <div class="mt-5 border-t border-slate-100 pt-4">
                    <div class="flex items-start justify-between gap-3"><h2 class="font-semibold text-slate-900">{{ $product->name }}</h2><x-erp.status-badge :active="$product->active" /></div>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">SKU</dt><dd class="font-medium"><bdi>{{ $product->sku }}</bdi></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">التصنيف</dt><dd class="text-end font-medium">{{ $product->category?->name ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">الوحدة الأساسية</dt><dd class="text-end font-medium">{{ $product->baseUnit?->name ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">النوع</dt><dd class="text-end font-medium">{{ $kindLabels[$product->kind] ?? '—' }}</dd></div>
                    </dl>
                </div>
            </x-ui.panel>
        </aside>
    </div>
</div>
