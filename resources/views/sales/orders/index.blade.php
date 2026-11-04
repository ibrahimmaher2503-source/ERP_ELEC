<div data-erp-archetype="list" class="space-y-4">
    <x-page.heading title="أوامر البيع" description="إنشاء ومتابعة أوامر البيع ضمن نطاق الفروع المصرح به" :breadcrumbs="['المبيعات', 'أوامر البيع']">
        <x-slot:actions>@if($can('create'))<flux:button variant="primary" :href="route('sales-orders.create')" wire:navigate icon="plus">إنشاء أمر بيع</flux:button>@endif</x-slot:actions><x-slot:secondaryActions>@if($can('export'))<flux:button wire:click="export" :disabled="! $filtersValid" icon="arrow-down-tray">تصدير Excel</flux:button>@endif</x-slot:secondaryActions>
    </x-page.heading>

    @include('sales.orders.partials.feedback')

    <nav class="erp-tabs flex flex-wrap gap-2 rounded-xl border border-[#E5E8EE] bg-white px-4" aria-label="تصفية أوامر البيع حسب الحالة">
        <button type="button" wire:click="$set('status', '')" @class(['inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold', 'border-blue-600 text-blue-700' => $status === '', 'border-transparent text-slate-600' => $status !== '']) :aria-pressed="$wire.status === ''"><flux:icon.document-text class="size-4" />جميع أوامر البيع ({{ $stats['total'] }})</button>
        <button type="button" wire:click="$set('status', 'draft')" @class(['inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold', 'border-blue-600 text-blue-700' => $status === 'draft', 'border-transparent text-slate-600' => $status !== 'draft']) :aria-pressed="$wire.status === 'draft'"><flux:icon.pencil-square class="size-4" />المسودات ({{ $stats['draft'] }})</button>
        <button type="button" wire:click="$set('status', 'pending_approval')" @class(['inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold', 'border-blue-600 text-blue-700' => $status === 'pending_approval', 'border-transparent text-slate-600' => $status !== 'pending_approval']) :aria-pressed="$wire.status === 'pending_approval'"><flux:icon.clock class="size-4" />بانتظار المراجعة ({{ $stats['pending_approval'] }})</button>
        <button type="button" wire:click="$set('status', 'cancelled')" @class(['inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold', 'border-blue-600 text-blue-700' => $status === 'cancelled', 'border-transparent text-slate-600' => $status !== 'cancelled']) :aria-pressed="$wire.status === 'cancelled'"><flux:icon.x-circle class="size-4" />الملغاة ({{ $stats['cancelled'] }})</button>
    </nav>


    <x-ui.panel class="erp-filters !p-4">
        <x-ui.filters id="sales-order-filters" target="search,status,branch,from,to,perPage,clearFilters" :reset="['search' => '', 'status' => '', 'branch' => '', 'from' => '', 'to' => '']" clear="clearFilters"><x-slot:search><flux:input wire:model.live.debounce.350ms="search" label="البحث" type="search" icon="magnifying-glass" placeholder="رقم الأمر أو العميل أو المشروع…" /></x-slot:search>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">

            <flux:select wire:model.live="status" label="الحالة">
                <option value="">كل الحالات</option>
                <option value="draft">مسودة</option>
                <option value="pending_approval">بانتظار المراجعة</option>
                <option value="cancelled">ملغي</option>
                <option value="approved">معتمد</option>
            </flux:select>
            <flux:select wire:model.live="branch" label="الفرع">
                <option value="">كل الفروع المصرح بها</option>
                @foreach($branches as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }} ({{ $item['code'] }})</option>@endforeach
            </flux:select>
            <flux:input type="date" wire:model.live="from" label="من تاريخ الأمر" />
            <flux:input type="date" wire:model.live="to" label="إلى تاريخ الأمر" />
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-slate-500">الاعتماد مستقل عن الحجز والتسليم والفوترة. تعرض المسودات مبالغ مقترحة، وتحفظ الأوامر المعتمدة مبالغ الإصدار المقبول.</p>
            <div class="flex items-center gap-2">
                <flux:select wire:model.live="perPage" label="عدد الصفوف" class="w-28">
                    <option value="10">10 صفوف</option><option value="25">25 صفًا</option><option value="50">50 صفًا</option>
                </flux:select>

            </div>
        </div>
        </x-ui.filters>
    </x-ui.panel>

    @if($filtersValid)
    <x-erp.section-card title="قائمة أوامر البيع" icon="document-text" class="!p-0">
        <div class="erp-mobile-table-tools"><flux:button size="sm" wire:click="sortBy('order_date')" icon="arrows-up-down">ترتيب حسب تاريخ الأمر</flux:button></div>
        <x-ui.responsive-table label="قائمة أوامر البيع">
            <table role="table" class="w-full min-w-[1050px] text-sm">
                <thead role="rowgroup" class="bg-slate-50 text-end text-xs text-slate-600">
                    <tr role="row">
                        <th role="columnheader" scope="col" class="px-4 py-3">رقم الأمر</th><th role="columnheader" scope="col" class="px-4 py-3">الفرع</th><th role="columnheader" scope="col" class="px-4 py-3">العميل</th><th role="columnheader" scope="col" class="px-4 py-3">المشروع / المرجع</th>
                        <th role="columnheader" scope="col" class="px-4 py-3"><span class="erp-mobile-column-label">تاريخ الأمر</span><button type="button" wire:click="sortBy('order_date')" class="font-semibold">تاريخ الأمر</button></th>
                        <th role="columnheader" scope="col" class="px-4 py-3">التسليم المطلوب</th><th role="columnheader" scope="col" class="px-4 py-3">الحالة / العمليات</th><th role="columnheader" scope="col" class="px-4 py-3">الصافي قبل الضرائب</th><th role="columnheader" scope="col" class="px-4 py-3">المنشئ</th><th role="columnheader" scope="col" class="px-4 py-3">إجراءات</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @forelse($records as $record)
                        <tr role="row" wire:key="sales-order-{{ $record->id }}" class="hover:bg-slate-50/70">
                            <td role="cell" data-label="رقم الأمر" class="px-4 py-3 font-semibold"><a class="text-blue-700 hover:underline" href="{{ route('sales-orders.show', ['order' => $record->id]) }}" wire:navigate><bdi>{{ $record->label() }}</bdi></a></td>
                            <td role="cell" data-label="الفرع" class="px-4 py-3">{{ $record->branch_snapshot['name'] ?? '—' }}</td>
                            <td role="cell" data-label="العميل" class="px-4 py-3">{{ $record->customer_snapshot['name'] ?? '—' }}</td>
                            <td role="cell" data-label="المشروع / المرجع" class="px-4 py-3">{{ $record->project_name ?: $record->customer_reference ?: '—' }}</td>
                            <td role="cell" data-label="تاريخ الأمر" class="px-4 py-3 tabular-nums"><bdi>{{ $record->order_date->format('Y/m/d') }}</bdi></td>
                            <td role="cell" data-label="التسليم المطلوب" class="px-4 py-3 tabular-nums"><bdi>{{ $record->requested_delivery_date?->format('Y/m/d') ?? 'غير محدد' }}</bdi></td>
                            <td role="cell" data-label="الحالة / العمليات" class="px-4 py-3"><div>@include('sales.orders.partials.workflow-statuses', ['record' => $record, 'compact' => true])</div></td>
                            <td role="cell" data-label="الصافي قبل الضرائب" class="px-4 py-3 tabular-nums"><bdi>{{ \App\Modules\Sales\Application\DraftAmounts::display($record->calculation['net'] ?? null) }} ج.م</bdi></td>
                            <td role="cell" data-label="المنشئ" class="px-4 py-3 text-slate-600">{{ $record->created_by ? 'مستخدم #'.$record->created_by : 'غير محدد' }}</td>
                            <td role="cell" data-label="الإجراءات" class="px-4 py-3"><div class="flex items-center gap-1">
                                <flux:button size="xs" variant="ghost" icon="eye" :href="route('sales-orders.show', ['order' => $record->id])" wire:navigate aria-label="عرض أمر البيع" />
                                @if($record->editable() && $can('edit', (int) $record->branch_id))<flux:button size="xs" variant="ghost" icon="pencil-square" :href="route('sales-orders.edit', ['order' => $record->id])" wire:navigate aria-label="تعديل المسودة" />@endif
                            </div></td>
                        </tr>
                    @empty
                        <tr role="row"><td role="cell" colspan="10" class="p-8"><x-ui.empty-state title="لا توجد أوامر بيع" description="غيّر معايير البحث أو راجع نطاق الفرع المصرح به." icon="document-text">@if($can('create'))<flux:button variant="primary" :href="route('sales-orders.create')" wire:navigate>إنشاء أمر بيع</flux:button>@endif</x-ui.empty-state></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.responsive-table>
        <div class="p-4">{{ $records->links() }}</div>
    </x-erp.section-card>
    @endif
</div>
