<div data-erp-archetype="list" class="space-y-6">
    <x-page.heading :title="$kindLabel" :breadcrumbs="['المنتجات', $kindLabel]" description="إدارة بيانات الكتالوج الأساسية">
        <x-slot:actions>
            @foreach (['category' => ['products.categories', 'الفئات'], 'brand' => ['products.brands', 'العلامات التجارية'], 'unit' => ['products.units', 'وحدات القياس']] as $navKind => [$routeName, $label])
                @if ($navKind !== $kind && \Illuminate\Support\Facades\Route::has($routeName))
                    <flux:button :href="route($routeName)" wire:navigate>{{ $label }}</flux:button>
                @endif
            @endforeach
        @if ($canManage)<flux:button variant="primary" icon="plus" wire:click="create">إضافة سجل</flux:button>@endif</x-slot:actions>
    </x-page.heading>

    @if (session('status'))
        <flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>
    @endif

    <div class="erp-kpi-summary grid gap-3 sm:grid-cols-3">
        @foreach (['total' => ['إجمالي السجلات', 'rectangle-stack', 'blue'], 'active' => ['السجلات النشطة', 'check-circle', 'emerald'], 'inactive' => ['السجلات غير النشطة', 'clock', 'orange']] as $key => [$label, $icon, $color])
            <x-erp.stat-card :label="$label" :value="$stats[$key]" :icon="$icon" :tone="$color" />
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(18rem,1fr)]">
        <x-ui.panel>
            <x-ui.filters id="master-filters" target="search,statusFilter" :reset="['search' => '', 'statusFilter' => '']"><x-slot:search><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="البحث في {{ $kindLabel }}" placeholder="الاسم أو الكود..." /></x-slot:search><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">

                <flux:select wire:model.live="statusFilter" label="الحالة"><option value="">كل الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option></flux:select>

            </div></x-ui.filters>

            <div class="overflow-x-auto" wire:loading.class="opacity-60">
                <x-ui.responsive-table label="السجلات المعروضة"><table role="table" class="w-full min-w-[560px] text-start text-sm">
                    <thead role="rowgroup" class="bg-slate-50 text-slate-500">
                        <tr role="row">
                            <th role="columnheader" class="p-4 text-start">#</th>
                            <th role="columnheader" class="p-4 text-start">الاسم</th>
                            <th role="columnheader" class="p-4 text-start">الكود</th>
                            @if ($kind === 'category')<th role="columnheader" class="p-4 text-start">المنتجات</th>@endif
                            <th role="columnheader" class="p-4 text-start">الحالة</th>
                            <th role="columnheader" class="p-4 text-start">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="divide-y divide-slate-100">
                        @forelse ($records as $record)
                            <tr role="row" wire:key="{{ $kind }}-{{ $record->id }}" @class(['bg-blue-50/60' => $selected?->id === $record->id])>
                                <td role="cell" data-label="الصف" class="p-4"><div>{{ $records->firstItem() + $loop->index }}</div></td>
                                <td role="cell" data-label="الاسم" class="p-4"><div>
                                    <button type="button" class="text-start font-medium hover:text-blue-700" wire:click="showDetails({{ $record->id }})" aria-label="عرض تفاصيل {{ $record->name }}">{{ $record->name }}</button>
                                    @if ($kind === 'category' && $record->parent)<div class="mt-1 text-xs text-slate-500">تابع لـ {{ $record->parent->name }}</div>@endif
                                </div></td>
                                <td role="cell" data-label="الكود" class="p-4"><div><bdi>{{ $record->code }}</bdi></div></td>
                                @if ($kind === 'category')<td role="cell" data-label="المنتجات" class="p-4"><div>{{ $record->products_count }}</div></td>@endif
                                <td role="cell" data-label="الحالة" class="p-4"><div><x-erp.status-badge :active="$record->active" /></div></td>
                                <td role="cell" data-label="الإجراءات" class="p-4"><div>
                                    <div class="flex items-center gap-1">
                                        <flux:button variant="ghost" size="sm" icon="eye" wire:click="showDetails({{ $record->id }})" aria-label="تفاصيل {{ $record->name }}" />
                                        @if ($canManage)<flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="edit({{ $record->id }})" aria-label="تعديل {{ $record->name }}" />@endif
                                    </div>
                                </div></td>
                            </tr>
                        @empty
                            <tr role="row"><td colspan="{{ $kind === 'category' ? 6 : 5 }}" class="p-8"><x-ui.empty-state title="لا توجد سجلات للعرض" description="جرّب تغيير البحث أو حالة السجلات." /></td></tr>
                        @endforelse
                    </tbody>
                </table></x-ui.responsive-table>
            </div>
            <div class="mt-4 border-t border-slate-100 pt-4">{{ $records->links() }}</div>
        </x-ui.panel>

        <x-ui.panel>
            @if ($selected)
                <div class="flex items-start justify-between gap-3">
                    <div><flux:heading size="lg">{{ $selected->name }}</flux:heading><p class="mt-1 text-sm text-slate-500">تفاصيل {{ $kindLabel }}</p></div>
                    @if ($canManage)<flux:button variant="ghost" icon="pencil-square" wire:click="edit({{ $selected->id }})">تعديل</flux:button>@endif
                </div>
                <dl class="mt-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-1">
                    <x-erp.information-row label="الاسم">{{ $selected->name }}</x-erp.information-row>
                    <x-erp.information-row label="الكود"><bdi>{{ $selected->code }}</bdi></x-erp.information-row>
                    <x-erp.information-row label="الحالة"><x-erp.status-badge :active="$selected->active" /></x-erp.information-row>
                    @if ($kind === 'category')
                        <x-erp.information-row label="الفئة الأم">{{ $selected->parent?->name ?: 'بدون فئة أم' }}</x-erp.information-row>
                        <x-erp.information-row label="عدد المنتجات">{{ $selected->products_count }}</x-erp.information-row>
                    @elseif ($kind === 'unit')
                        <x-erp.information-row label="البعد">{{ $dimensions[$selected->dimension] ?? $selected->dimension }}</x-erp.information-row>
                    @endif
                    <x-erp.information-row label="رقم الإصدار"><bdi>{{ $selected->version }}</bdi></x-erp.information-row>
                </dl>
            @else
                <x-ui.empty-state title="اختر سجلًا" description="اختر سجلًا من القائمة لعرض تفاصيله." />
            @endif
        </x-ui.panel>
    </div>

    <flux:modal wire:model="editing" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <x-ui.validation-summary />
            <flux:heading size="lg">{{ $editingId ? 'تعديل سجل' : 'إضافة سجل' }} · {{ $kindLabel }}</flux:heading>
            <flux:input wire:model="form.code" label="الكود" required maxlength="50" />
            <flux:input wire:model="form.name" label="الاسم" required maxlength="200" />
            @if ($kind === 'category')
                <flux:select wire:model="form.parent_id" label="الفئة الأم">
                    <option value="">بدون فئة أم</option>
                    @foreach ($parentChoices as $parent)
                        <option value="{{ $parent->id }}">{{ $parent->name }}{{ $parent->active ? '' : ' · غير نشط' }}</option>
                    @endforeach
                </flux:select>
            @elseif ($kind === 'unit')
                <flux:select wire:model="form.dimension" label="البعد" required>
                    @foreach ($dimensions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </flux:select>
            @endif
            <flux:select wire:model="form.active" label="الحالة" required><option value="1">نشط</option><option value="0">غير نشط</option></flux:select>
            <div class="flex gap-3"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ</flux:button><flux:button type="button" wire:click="cancel">إلغاء</flux:button></div>
        </form>
    </flux:modal>
</div>
