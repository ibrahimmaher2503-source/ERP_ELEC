<div class="space-y-6" dir="rtl">
    <x-page.heading title="الأصناف والمنتجات" description="إدارة بيانات الأصناف والتصنيفات والوحدات" :breadcrumbs="['المنتجات', 'الأصناف']">
        <x-slot:actions>
            @if ($canCreate)
                <flux:button :href="route('products.create')" variant="primary" icon="plus" wire:navigate>إضافة صنف</flux:button>
            @endif
        </x-slot:actions>
    </x-page.heading>

    @if (session('status'))<flux:callout variant="success">{{ session('status') }}</flux:callout>@endif
    <flux:error name="status" />
    <flux:error name="export" />
    <flux:error name="ids" />

    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ([['إجمالي الأصناف', 'total', 'cube', 'blue'], ['الأصناف النشطة', 'active', 'check-circle', 'emerald'], ['التصنيفات', 'categories', 'rectangle-stack', 'orange']] as [$label, $key, $icon, $tone])
            <x-erp.stat-card :label="$label" :value="$stats[$key]" :icon="$icon" :tone="$tone" />
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_17rem]">
        <x-ui.panel class="!p-0 overflow-hidden">
            <div class="erp-filters space-y-4 border-b border-[#E5E8EE] p-5">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-48 flex-1"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="البحث عن صنف" placeholder="الاسم أو SKU أو الباركود..." /></div>
                    <flux:select wire:model.live="brandFilter" label="العلامة التجارية" class="!w-44">
                        <option value="">كل العلامات التجارية</option>
                        @foreach ($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model.live="statusFilter" label="الحالة" class="!w-36">
                        <option value="">كل الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option>
                    </flux:select>
                </div>
                <details class="group">
                    <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-md text-sm font-medium text-slate-600 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        <flux:icon name="funnel" class="size-4" /> فلاتر إضافية
                    </summary>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <flux:select wire:model.live="typeFilter" label="نوع الصنف">
                            <option value="">كل الأنواع</option><option value="serialized_equipment">معدات برقم تسلسلي</option><option value="bulk">مواد بالكمية</option><option value="service">خدمة</option>
                        </flux:select>
                        <flux:select wire:model.live="unitFilter" label="وحدة القياس">
                            <option value="">كل الوحدات</option>
                            @foreach ($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach
                        </flux:select>
                    </div>
                </details>
            </div>
            <x-erp.selection-toolbar :selected-count="count($selected)" :can-bulk="$canBulk" :can-import="$canImport" :can-export="$canExport" />

            <div class="overflow-x-auto" wire:loading.class="opacity-60">
                <table class="w-full min-w-[1100px] text-start text-sm">
                    <thead class="bg-slate-50 text-slate-500">
                        @php($visibleIds = $products->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->all())
                        @php($selectedValues = collect($selected)->map(fn ($id) => (string) $id)->all())
                        @php($visibleSelectedCount = count(array_intersect($visibleIds, $selectedValues)))
                        @php($visibleSelected = $visibleIds !== [] && $visibleSelectedCount === count($visibleIds))
                        @php($visiblePartiallySelected = $visibleSelectedCount > 0 && ! $visibleSelected)
                        <tr>
                            <th class="p-4 text-start">
                                <button type="button" role="checkbox" aria-label="تحديد الأصناف الظاهرة" aria-checked="{{ $visibleSelected ? 'true' : ($visiblePartiallySelected ? 'mixed' : 'false') }}" @disabled($products->count() === 0) wire:click="selectVisible" class="inline-flex size-6 items-center justify-center rounded border border-slate-300 bg-white text-white transition hover:border-blue-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:cursor-not-allowed disabled:opacity-50 {{ $visibleSelected ? 'border-blue-600 bg-blue-600' : '' }}">
                                    @if ($visibleSelected)<flux:icon name="check" class="size-4" aria-hidden="true" />@elseif ($visiblePartiallySelected)<span aria-hidden="true" class="h-0.5 w-3 rounded bg-blue-600"></span>@endif
                                </button>
                            </th>
                            <th class="p-4 text-start">#</th>
                            <th class="p-4 text-start"><button type="button" wire:click="sortBy('name')" class="font-semibold hover:text-slate-900">الصنف</button></th>
                            <th class="p-4 text-start"><button type="button" wire:click="sortBy('sku')" class="font-semibold hover:text-slate-900">SKU</button></th>
                            <th class="p-4 text-start">الباركود</th>
                            <th class="p-4 text-start">التصنيف</th>
                            <th class="p-4 text-start">العلامة التجارية</th>
                            <th class="p-4 text-start">الوحدة الأساسية</th>
                            <th class="p-4 text-start">النوع</th>
                            <th class="p-4 text-start">الحالة</th>
                            <th class="p-4 text-start">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($products as $product)
                            @php($productImage = isset($canMedia) && $canMedia ? $product->media->first(fn ($file) => $file->is_image) : null)
                            <tr wire:key="product-{{ $product->id }}">
                                <td class="p-4"><flux:checkbox wire:model.live="selected" value="{{ $product->id }}" :aria-label="'تحديد '.$product->name" /></td>
                                <td class="p-4 text-slate-500">{{ $products->firstItem() + $loop->index }}</td>
                                <td class="p-4">
                                    <a href="{{ route('products.show', $product->id) }}" wire:navigate class="flex items-center gap-3 font-medium text-slate-800 hover:text-blue-700">
                                        @if ($productImage)
                                            <img src="{{ route('products.media', $productImage->id) }}" alt="" class="size-10 rounded-md border border-slate-200 object-cover" loading="lazy">
                                        @else
                                            <span class="flex size-10 items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-slate-400"><flux:icon name="cube" class="size-5" /></span>
                                        @endif
                                        <span class="min-w-0"><span class="block truncate">{{ $product->name }}</span><span class="mt-1 block text-xs font-normal text-slate-500">{{ $product->short_description ?: '—' }}</span></span>
                                    </a>
                                </td>
                                <td class="p-4"><bdi class="font-medium">{{ $product->sku }}</bdi></td>
                                <td class="p-4"><bdi>{{ $product->barcode ?: '—' }}</bdi></td>
                                <td class="p-4">{{ $product->category?->name ?: '—' }}</td>
                                <td class="p-4">{{ $product->brand?->name ?: '—' }}</td>
                                <td class="p-4">{{ $product->baseUnit?->name ?: '—' }}</td>
                                <td class="p-4 text-xs">{{ ['serialized_equipment' => 'معدات برقم تسلسلي', 'bulk' => 'مواد بالكمية', 'service' => 'خدمة'][$product->kind] ?? '—' }}</td>
                                <td class="p-4"><x-erp.status-badge :active="$product->active" /></td>
                                <td class="p-4">
                                    <flux:dropdown>
                                        <flux:button icon="ellipsis-horizontal" variant="ghost" size="sm" aria-label="إجراءات {{ $product->name }}" />
                                        <flux:menu>
                                            <flux:menu.item :href="route('products.show', $product->id)" icon="eye" wire:navigate>تفاصيل الصنف</flux:menu.item>
                                            @if ($canUpdate)<flux:menu.item :href="route('products.edit', $product->id)" icon="pencil-square" wire:navigate>تعديل البيانات</flux:menu.item>@endif
                                            @if ($canStatus)<flux:menu.item wire:click="changeStatus({{ $product->id }}, {{ $product->version }})" icon="power">{{ $product->active ? 'تعطيل الصنف' : 'تفعيل الصنف' }}</flux:menu.item>@endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="p-8"><x-ui.empty-state title="لا توجد أصناف للعرض" description="عدّل معايير البحث أو أضف صنفًا بصلاحية مناسبة." icon="cube" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-100 px-5 py-4">
                <p class="text-xs text-slate-500">{{ $products->total() }} صنف</p>
                <div class="flex flex-wrap items-center gap-4">
                    <flux:select wire:model.live="perPage" aria-label="عدد الأصناف في الصفحة" class="!w-24"><option value="5">5</option><option value="10">10</option><option value="25">25</option><option value="50">50</option></flux:select>
                    {{ $products->links() }}
                </div>
            </div>
        </x-ui.panel>

        <aside class="space-y-4">
            <x-ui.panel>
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">التصنيفات</flux:heading>
                    <flux:button :href="route('products.categories')" variant="ghost" size="sm" icon="arrow-top-right-on-square" aria-label="إدارة التصنيفات" wire:navigate />
                </div>
                <div class="mt-4 space-y-1">
                    <button type="button" wire:click="$set('categoryFilter', '')" @class(['flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-start text-sm', 'bg-blue-50 font-semibold text-blue-800' => ! $categoryFilter, 'text-slate-600 hover:bg-slate-50' => (bool) $categoryFilter]) aria-pressed="{{ ! $categoryFilter ? 'true' : 'false' }}">
                        <span class="flex items-center gap-2"><flux:icon name="squares-2x2" class="size-4" /> كل التصنيفات</span><span>{{ $stats['total'] }}</span>
                    </button>
                    <x-products.category-tree :categories="$categories" :selected-id="$categoryFilter" />
                </div>
            </x-ui.panel>

            <x-ui.panel>
                <flux:heading size="lg">بيانات الكتالوج</flux:heading>
                <nav class="mt-3 divide-y divide-slate-100" aria-label="إدارة بيانات الكتالوج">
                    <a href="{{ route('products.brands') }}" wire:navigate class="flex items-center justify-between gap-3 py-3 text-sm text-slate-600 hover:text-blue-700"><span class="flex items-center gap-2"><flux:icon name="tag" class="size-4" /> العلامات التجارية</span><flux:icon name="chevron-left" class="size-4" /></a>
                    <a href="{{ route('products.units') }}" wire:navigate class="flex items-center justify-between gap-3 py-3 text-sm text-slate-600 hover:text-blue-700"><span class="flex items-center gap-2"><flux:icon name="scale" class="size-4" /> وحدات القياس</span><flux:icon name="chevron-left" class="size-4" /></a>
                </nav>
            </x-ui.panel>
        </aside>
    </div>

    <flux:modal wire:model="bulkOpen" class="w-full max-w-3xl">
        <form wire:submit="previewBulk" class="space-y-5">
            <div>
                <flux:heading size="lg">تعديل الأصناف المحددة</flux:heading>
                <p class="mt-1 text-sm text-slate-500">اختر الحقول المطلوب تغييرها. المعاينة تسبق أي حفظ.</p>
            </div>

            <flux:callout color="blue">سيتم تطبيق التغييرات على {{ count($selected) }} صنفًا محددًا فقط.</flux:callout>

            <div class="space-y-4">
                @if ($canStatus)
                    <div class="grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] sm:items-end">
                        <flux:select wire:model.live="changes.active.mode" label="حالة الصنف">
                            <option value="keep">بدون تغيير</option><option value="set">تعيين حالة</option>
                        </flux:select>
                        @if (($changes['active']['mode'] ?? 'keep') === 'set')
                            <flux:select wire:model.live="changes.active.value" label="الحالة الجديدة" required>
                                <option value="1">نشط</option><option value="0">غير نشط</option>
                            </flux:select>
                        @endif
                    </div>
                @endif

                @foreach ([
                    'category_id' => ['التصنيف', $categories->where('active', true)],
                    'brand_id' => ['العلامة التجارية', $brands->where('active', true)],
                ] as $field => [$label, $options])
                    <div class="grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] sm:items-end">
                        <flux:select wire:model.live="changes.{{ $field }}.mode" :label="$label">
                            <option value="keep">بدون تغيير</option><option value="set">تعيين قيمة</option><option value="clear">مسح القيمة</option>
                        </flux:select>
                        @if (($changes[$field]['mode'] ?? 'keep') === 'set')
                            <flux:select wire:model.live="changes.{{ $field }}.value" :label="$label.' الجديدة'" required>
                                <option value="">اختر {{ $label }}</option>
                                @foreach ($options as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                            </flux:select>
                        @endif
                    </div>
                @endforeach

                @foreach ([
                    'model' => ['الموديل', '200'],
                    'short_description' => ['الوصف المختصر', '500'],
                    'description' => ['الوصف والمواصفات', '10000'],
                ] as $field => [$label, $maxlength])
                    <div class="grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] sm:items-end">
                        <flux:select wire:model.live="changes.{{ $field }}.mode" :label="$label">
                            <option value="keep">بدون تغيير</option><option value="set">تعيين قيمة</option><option value="clear">مسح القيمة</option>
                        </flux:select>
                        @if (($changes[$field]['mode'] ?? 'keep') === 'set')
                            @if ($field === 'description')
                                <flux:textarea wire:model.live="changes.description.value" :label="$label.' الجديد'" rows="3" :maxlength="$maxlength" />
                            @else
                                <flux:input wire:model.live="changes.{{ $field }}.value" :label="$label.' الجديد'" :maxlength="$maxlength" />
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>

            <flux:error name="changes" />
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
                <flux:button type="button" variant="ghost" wire:click="$set('bulkOpen', false)">إلغاء</flux:button>
                <flux:button type="submit" variant="primary" icon="eye" :disabled="count($selected) === 0" wire:loading.attr="disabled" wire:target="previewBulk">معاينة التغييرات</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
