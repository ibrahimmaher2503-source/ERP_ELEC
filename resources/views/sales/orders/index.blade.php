@php
    use App\Modules\Sales\Presentation\SalesOrderDisplay as D;
@endphp
<div data-erp-archetype="list" class="orders-ui space-y-5">
    <x-page.heading title="أوامر البيع" description="إنشاء ومراجعة ومتابعة تنفيذ أوامر البيع ضمن نطاق الفروع المصرح به" :breadcrumbs="['المبيعات', 'أوامر البيع']">
        <x-slot:actions>
            @if($can('create'))
                <flux:button variant="primary" :href="route('sales-orders.create')" wire:navigate icon="plus">إنشاء أمر بيع</flux:button>
            @endif
        </x-slot:actions>
        @if($can('export') || $canViewReturns)
        <x-slot:secondaryActions>
            @if($can('export'))
                <flux:button wire:click="export" :disabled="! $filtersValid" icon="arrow-down-tray">تصدير Excel</flux:button>
            @endif
            @if($canViewReturns)
                <flux:button :href="route('sales-returns.index')" wire:navigate icon="arrow-uturn-left">مرتجعات البيع</flux:button>
            @endif
        </x-slot:secondaryActions>
        @endif
    </x-page.heading>

    @include('sales.orders.partials.feedback')

    <section class="orders-kpis" aria-label="ملخص أوامر البيع حسب الحالة">
        <button type="button" wire:click="toggleStatus('')" @class(['is-active' => $status === '']) aria-pressed="{{ $status === '' ? 'true' : 'false' }}">
            <span>جميع الأوامر</span>
            <strong>{{ number_format($stats['total']) }}</strong>
            <small>إجمالي النطاق المصرح</small>
        </button>
        <button type="button" wire:click="toggleStatus('draft')" @class(['is-active' => $status === 'draft']) aria-pressed="{{ $status === 'draft' ? 'true' : 'false' }}">
            <span>المسودات</span>
            <strong>{{ number_format($stats['draft']) }}</strong>
            <small>قابلة للتعديل والتقديم</small>
        </button>
        <button type="button" wire:click="toggleStatus('pending_approval')" @class(['is-warning', 'is-active' => $status === 'pending_approval']) aria-pressed="{{ $status === 'pending_approval' ? 'true' : 'false' }}">
            <span>بانتظار المراجعة</span>
            <strong>{{ number_format($stats['pending_approval']) }}</strong>
            <small>تنتظر الاعتماد المستقل</small>
        </button>
        <button type="button" wire:click="toggleStatus('approved')" @class(['is-success', 'is-active' => $status === 'approved']) aria-pressed="{{ $status === 'approved' ? 'true' : 'false' }}">
            <span>المعتمدة</span>
            <strong>{{ number_format($stats['approved']) }}</strong>
            <small>محجوزة أو قيد التسليم</small>
        </button>
        <button type="button" wire:click="toggleStatus('cancelled')" @class(['is-danger', 'is-active' => $status === 'cancelled']) aria-pressed="{{ $status === 'cancelled' ? 'true' : 'false' }}">
            <span>الملغاة</span>
            <strong>{{ number_format($stats['cancelled']) }}</strong>
            <small>أوامر غير منفذة ملغاة</small>
        </button>
    </section>

    <x-ui.panel class="erp-filters !p-4">
        <x-ui.filters id="sales-order-filters" target="search,status,branch,source,from,to,perPage,clearFilters" :reset="['search' => '', 'status' => '', 'branch' => '', 'source' => '', 'from' => '', 'to' => '']" clear="clearFilters">
            <x-slot:search>
                <flux:input wire:model.live.debounce.350ms="search" label="البحث" type="search" icon="magnifying-glass" placeholder="رقم الأمر أو العميل أو المشروع…" />
            </x-slot:search>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                <flux:select wire:model.live="status" label="الحالة">
                    <option value="">كل الحالات</option>
                    <option value="draft">مسودة</option>
                    <option value="pending_approval">بانتظار المراجعة</option>
                    <option value="approved">معتمد</option>
                    <option value="cancelled">ملغي</option>
                </flux:select>
                <flux:select wire:model.live="source" label="المصدر">
                    <option value="">كل المصادر</option>
                    <option value="direct">أمر مباشر</option>
                    <option value="quotation">من عرض سعر</option>
                </flux:select>
                <flux:select wire:model.live="branch" label="الفرع">
                    <option value="">كل الفروع المصرح بها</option>
                    @foreach($branches as $item)
                        <option value="{{ $item['id'] }}">{{ $item['name'] }} ({{ $item['code'] }})</option>
                    @endforeach
                </flux:select>
                <flux:input type="date" wire:model.live="from" label="من تاريخ الأمر" />
                <flux:input type="date" wire:model.live="to" label="إلى تاريخ الأمر" />
                <flux:select wire:model.live="perPage" label="عدد الصفوف">
                    <option value="10">10 صفوف</option>
                    <option value="25">25 صفًا</option>
                    <option value="50">50 صفًا</option>
                </flux:select>
            </div>
        </x-ui.filters>
    </x-ui.panel>

    @if($filtersValid)
    <x-erp.section-card :title="'سجل أوامر البيع ('.number_format($records->total()).')'" icon="document-text" class="!p-0">
        <div class="erp-mobile-table-tools">
            <flux:button size="sm" wire:click="sortBy('order_date')" icon="arrows-up-down">ترتيب بتاريخ الأمر</flux:button>
            <flux:button size="sm" wire:click="sortBy('number')" icon="arrows-up-down">ترتيب برقم الأمر</flux:button>
        </div>
        <x-ui.responsive-table label="قائمة أوامر البيع">
            <table role="table" class="w-full min-w-[1060px] text-sm">
                <thead role="rowgroup" class="text-end text-xs text-slate-600">
                    <tr role="row">
                        <th role="columnheader" scope="col" class="px-4 py-3">
                            <span class="erp-mobile-column-label">رقم الأمر</span>
                            <button type="button" wire:click="sortBy('number')" class="inline-flex items-center gap-1 font-semibold">
                                رقم الأمر
                                @if($sort === 'number')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                            </button>
                        </th>
                        <th role="columnheader" scope="col" class="px-4 py-3">الفرع</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">العميل</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">المشروع / المرجع</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">
                            <span class="erp-mobile-column-label">تاريخ الأمر</span>
                            <button type="button" wire:click="sortBy('order_date')" class="inline-flex items-center gap-1 font-semibold">
                                تاريخ الأمر
                                @if($sort === 'order_date')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                            </button>
                        </th>
                        <th role="columnheader" scope="col" class="px-4 py-3">التسليم المطلوب</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">الحالة / التنفيذ</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">القيمة</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">المنشئ</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">إجراءات</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @forelse($records as $record)
                        @php $isOfficial = (bool) ($record->calculation['official'] ?? false); @endphp
                        <tr role="row" wire:key="sales-order-{{ $record->id }}" class="hover:bg-slate-50/70">
                            <td role="cell" data-label="رقم الأمر" class="px-4 py-3">
                                <a class="font-semibold text-blue-700 hover:underline" href="{{ route('sales-orders.show', ['order' => $record->id]) }}" wire:navigate>
                                    <bdi>{{ $record->label() }}</bdi>
                                </a>
                                @if($record->source_type === 'quotation')
                                    <span class="mt-0.5 block text-[11px] text-slate-500">محول من عرض سعر</span>
                                @endif
                            </td>
                            <td role="cell" data-label="الفرع" class="px-4 py-3">{{ $record->branch_snapshot['name'] ?? '—' }}</td>
                            <td role="cell" data-label="العميل" class="px-4 py-3">
                                @if($canViewCustomer)
                                    <a class="font-medium text-slate-800 hover:text-blue-700 hover:underline" href="{{ route('customers.show', $record->customer_id) }}" wire:navigate>{{ $record->customer_snapshot['name'] ?? '—' }}</a>
                                @else
                                    {{ $record->customer_snapshot['name'] ?? '—' }}
                                @endif
                            </td>
                            <td role="cell" data-label="المشروع / المرجع" class="px-4 py-3">{{ $record->project_name ?: $record->customer_reference ?: '—' }}</td>
                            <td role="cell" data-label="تاريخ الأمر" class="px-4 py-3 tabular-nums"><bdi>{{ $record->order_date->format('Y/m/d') }}</bdi></td>
                            <td role="cell" data-label="التسليم المطلوب" class="px-4 py-3 tabular-nums"><bdi>{{ $record->requested_delivery_date?->format('Y/m/d') ?? 'غير محدد' }}</bdi></td>
                            <td role="cell" data-label="الحالة / التنفيذ" class="px-4 py-3">
                                @include('sales.orders.partials.workflow-statuses', ['record' => $record, 'progress' => $progressMap[$record->id] ?? null, 'compact' => true])
                            </td>
                            <td role="cell" data-label="القيمة" class="px-4 py-3 tabular-nums">
                                <strong class="font-semibold text-slate-900"><bdi>{{ D::money($isOfficial ? ($record->calculation['total'] ?? null) : ($record->calculation['net'] ?? null)) }}</bdi> ج.م</strong>
                                <span class="block text-[11px] text-slate-500">{{ $isOfficial ? 'شامل الضريبة' : 'قبل الضريبة' }}</span>
                            </td>
                            <td role="cell" data-label="المنشئ" class="px-4 py-3 text-slate-600">{{ $record->created_by ? \App\Modules\Organization\Presentation\UserNames::name($record->created_by) : 'غير محدد' }}</td>
                            <td role="cell" data-label="الإجراءات" class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-1">
                                    <flux:button size="xs" variant="ghost" icon="eye" :href="route('sales-orders.show', ['order' => $record->id])" wire:navigate aria-label="عرض أمر البيع" />
                                    @if($record->editable() && $record->source_type === 'direct' && $can('edit', (int) $record->branch_id))
                                        <flux:button size="xs" variant="ghost" icon="pencil-square" :href="route('sales-orders.edit', ['order' => $record->id])" wire:navigate aria-label="تعديل المسودة" />
                                    @endif
                                    @if($record->status === 'pending_approval' && $can('approve', (int) $record->branch_id))
                                        <flux:button size="xs" variant="ghost" icon="clipboard-document-check" :href="route('sales-orders.screen', ['order' => $record->id, 'screen' => 'review'])" wire:navigate aria-label="مراجعة واعتماد" />
                                    @endif
                                    @if($record->status === 'approved' && $can('export', (int) $record->branch_id))
                                        <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('sales-orders.pdf', $record->id)" aria-label="تحميل PDF" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr role="row">
                            <td role="cell" colspan="10" class="p-8">
                                <x-ui.empty-state title="لا توجد أوامر بيع" description="غيّر معايير البحث أو راجع نطاق الفرع المصرح به." icon="document-text">
                                    @if($can('create'))
                                        <flux:button variant="primary" :href="route('sales-orders.create')" wire:navigate>إنشاء أمر بيع</flux:button>
                                    @endif
                                </x-ui.empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.responsive-table>
        <div class="p-4">{{ $records->links() }}</div>
    </x-erp.section-card>
    @endif
</div>
