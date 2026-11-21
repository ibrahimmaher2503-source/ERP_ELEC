@php
    use App\Modules\Purchasing\Presentation\PurchaseOrderDisplay as D;
@endphp
<div data-erp-archetype="list" class="orders-ui space-y-5">
    <x-page.heading title="أوامر الشراء" description="إدارة ومراجعة أوامر الشراء ومتابعة الإصدار الرسمي وحالة الاستلام" :breadcrumbs="['المشتريات', 'أوامر الشراء']">
        <x-slot:actions>
            @if($canCreate)
                <flux:button variant="primary" icon="plus" :href="route('purchasing.orders.create')" wire:navigate>أمر شراء جديد</flux:button>
            @endif
        </x-slot:actions>
        @if($canExport || $canViewReturns)
        <x-slot:secondaryActions>
            @if($canExport)
                <flux:button icon="arrow-down-tray" :href="route('purchasing.export', array_filter(['entity' => 'orders', 'branch' => $branch, 'from' => $from, 'to' => $to, 'supplier' => $search, 'status' => $status ?: $receiving]))">تصدير Excel</flux:button>
            @endif
            @if($canViewReturns)
                <flux:button icon="arrow-uturn-left" :href="route('purchasing.returns')" wire:navigate>مرتجعات المشتريات</flux:button>
            @endif
        </x-slot:secondaryActions>
        @endif
    </x-page.heading>

    <x-ui.validation-summary />

    <section class="orders-kpis" aria-label="ملخص أوامر الشراء حسب حالة الاعتماد">
        <button type="button" wire:click="toggleStatus('')" @class(['is-active' => $status === '']) aria-pressed="{{ $status === '' ? 'true' : 'false' }}">
            <span>جميع الأوامر</span>
            <strong>{{ number_format($stats['total']) }}</strong>
            <small>إجمالي النطاق المصرح</small>
        </button>
        <button type="button" wire:click="toggleStatus('draft')" @class(['is-active' => $status === 'draft']) aria-pressed="{{ $status === 'draft' ? 'true' : 'false' }}">
            <span>المسودات</span>
            <strong>{{ number_format($stats['draft']) }}</strong>
            <small>قيد الإعداد والمراجعة</small>
        </button>
        <button type="button" wire:click="toggleStatus('submitted')" @class(['is-warning', 'is-active' => $status === 'submitted']) aria-pressed="{{ $status === 'submitted' ? 'true' : 'false' }}">
            <span>بانتظار الاعتماد</span>
            <strong>{{ number_format($stats['submitted']) }}</strong>
            <small>تنتظر قرار الاعتماد</small>
        </button>
        <button type="button" wire:click="toggleStatus('approved')" @class(['is-success', 'is-active' => $status === 'approved']) aria-pressed="{{ $status === 'approved' ? 'true' : 'false' }}">
            <span>معتمد داخليًا</span>
            <strong>{{ number_format($stats['approved']) }}</strong>
            <small>مؤهل للإصدار والاستلام</small>
        </button>
        <button type="button" wire:click="toggleStatus('rejected')" @class(['is-danger', 'is-active' => $status === 'rejected']) aria-pressed="{{ $status === 'rejected' ? 'true' : 'false' }}">
            <span>مرفوض</span>
            <strong>{{ number_format($stats['rejected']) }}</strong>
            <small>يتطلب مراجعة أو إعادة للمسودة</small>
        </button>
    </section>

    <x-ui.panel class="erp-filters !p-4">
        <x-ui.filters id="purchase-order-filters" target="search,status,receiving,branch,issued,from,to,perPage,clearFilters" :reset="['search' => '', 'status' => '', 'receiving' => '', 'branch' => '', 'issued' => '', 'from' => '', 'to' => '']" clear="clearFilters">
            <x-slot:search>
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="البحث" type="search" placeholder="رقم الأمر أو المورد أو رقم الإصدار الرسمي…" />
            </x-slot:search>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <flux:select label="الفرع" wire:model.live="branch">
                    <option value="">كل الفروع المصرح بها</option>
                    @foreach($branches as $b)
                        <option value="{{ $b['id'] }}">{{ $b['name'] }}</option>
                    @endforeach
                </flux:select>
                <flux:select label="حالة الاعتماد" wire:model.live="status">
                    <option value="">جميع الحالات</option>
                    <option value="draft">مسودة</option>
                    <option value="submitted">بانتظار الاعتماد</option>
                    <option value="approved">معتمد داخليًا</option>
                    <option value="rejected">مرفوض</option>
                </flux:select>
                <flux:select label="حالة الاستلام" wire:model.live="receiving">
                    <option value="">كل حالات الاستلام</option>
                    @foreach(D::RECEIVING_STATUSES as $key => [$label, $tone])
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                <flux:select label="الإصدار الرسمي" wire:model.live="issued">
                    <option value="">الكل</option>
                    <option value="issued">صادر رسميًا</option>
                    <option value="not_issued">غير صادر</option>
                </flux:select>
                <flux:input type="date" label="من تاريخ الأمر" wire:model.live="from" />
                <flux:input type="date" label="إلى تاريخ الأمر" wire:model.live="to" />
                <flux:select label="عدد الصفوف" wire:model.live="perPage">
                    <option value="15">15 صفًا</option>
                    <option value="30">30 صفًا</option>
                    <option value="50">50 صفًا</option>
                </flux:select>
            </div>
        </x-ui.filters>
    </x-ui.panel>

    @if($filtersValid && $rows)
    <x-erp.section-card :title="'سجل أوامر الشراء ('.number_format($rows->total()).')'" icon="shopping-cart" class="!p-0">
        <div class="erp-mobile-table-tools">
            <flux:button size="sm" wire:click="sortBy('order_date')" icon="arrows-up-down">ترتيب بتاريخ الأمر</flux:button>
            <flux:button size="sm" wire:click="sortBy('expected_date')" icon="arrows-up-down">ترتيب بموعد التوريد</flux:button>
        </div>
        <x-ui.responsive-table label="قائمة أوامر الشراء">
            <table role="table" class="w-full min-w-[1040px] text-sm">
                <thead role="rowgroup" class="text-end text-xs text-slate-600">
                    <tr role="row">
                        <th role="columnheader" scope="col" class="px-4 py-3">
                            <span class="erp-mobile-column-label">الأمر</span>
                            <button type="button" wire:click="sortBy('id')" class="inline-flex items-center gap-1 font-semibold">
                                الأمر
                                @if($sort === 'id')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                            </button>
                        </th>
                        <th role="columnheader" scope="col" class="px-4 py-3">الفرع</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">المورد</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">
                            <span class="erp-mobile-column-label">تاريخ الأمر</span>
                            <button type="button" wire:click="sortBy('order_date')" class="inline-flex items-center gap-1 font-semibold">
                                تاريخ الأمر
                                @if($sort === 'order_date')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                            </button>
                        </th>
                        <th role="columnheader" scope="col" class="px-4 py-3">
                            <span class="erp-mobile-column-label">التوريد المتوقع</span>
                            <button type="button" wire:click="sortBy('expected_date')" class="inline-flex items-center gap-1 font-semibold">
                                التوريد المتوقع
                                @if($sort === 'expected_date')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                            </button>
                        </th>
                        <th role="columnheader" scope="col" class="px-4 py-3">
                            <span class="erp-mobile-column-label">الصافي</span>
                            <button type="button" wire:click="sortBy('net_amount')" class="inline-flex items-center gap-1 font-semibold">
                                الصافي
                                @if($sort === 'net_amount')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                            </button>
                        </th>
                        <th role="columnheader" scope="col" class="px-4 py-3">الاعتماد</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">الإصدار والاستلام</th>
                        <th role="columnheader" scope="col" class="px-4 py-3">الإجراءات</th>
                    </tr>
                </thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @forelse($rows as $r)
                        @php
                            [$statusLabel, $statusTone] = D::status($r->status);
                            [$recLabel, $recTone] = D::receiving($r->receiving_status);
                            $overdue = $r->expected_date && $r->expected_date < $todayCairo && $r->receiving_status !== 'received';
                            $canReceiveRow = $r->status === 'approved' && in_array((int) $r->branch_id, $receiptBranches, true) && $r->receiving_status !== 'received';
                        @endphp
                        <tr role="row" wire:key="po-{{ $r->id }}" class="hover:bg-slate-50/70">
                            <td role="cell" data-label="الأمر" class="px-4 py-3">
                                <a class="font-semibold text-blue-700 hover:underline" href="{{ route('purchasing.orders.show', $r->id) }}" wire:navigate>
                                    <bdi>{{ $r->official_number ?? '#'.$r->id }}</bdi>
                                </a>
                                @if($r->official_number)
                                    <span class="mt-0.5 block text-[11px] text-slate-500" dir="ltr">#{{ $r->id }}</span>
                                @endif
                            </td>
                            <td role="cell" data-label="الفرع" class="px-4 py-3">{{ $branchNames[$r->branch_id] ?? '—' }}</td>
                            <td role="cell" data-label="المورد" class="px-4 py-3">
                                @if($canViewSuppliers)
                                    <a class="font-medium text-slate-800 hover:text-blue-700 hover:underline" href="{{ route('suppliers.show', $r->supplier_id) }}" wire:navigate>{{ $r->supplier_name }}</a>
                                @else
                                    {{ $r->supplier_name }}
                                @endif
                            </td>
                            <td role="cell" data-label="تاريخ الأمر" class="px-4 py-3 tabular-nums"><bdi>{{ $r->order_date }}</bdi></td>
                            <td role="cell" data-label="التوريد المتوقع" class="px-4 py-3 tabular-nums">
                                @if($r->expected_date)
                                    <bdi>{{ $r->expected_date }}</bdi>
                                    @if($overdue)
                                        <span class="mt-0.5 block text-[11px] font-semibold text-amber-700">تجاوز الموعد المتوقع</span>
                                    @endif
                                @else
                                    <span class="text-slate-400">غير محدد</span>
                                @endif
                            </td>
                            <td role="cell" data-label="الصافي" class="px-4 py-3 tabular-nums">
                                <strong class="font-semibold text-slate-900"><bdi>{{ D::money($r->net_amount) }}</bdi></strong>
                                <span class="text-xs text-slate-500">{{ D::currencyLabel($r->currency) }}</span>
                            </td>
                            <td role="cell" data-label="الاعتماد" class="px-4 py-3">
                                <span class="orders-badge" data-tone="{{ $statusTone }}">{{ $statusLabel }}</span>
                            </td>
                            <td role="cell" data-label="الإصدار والاستلام" class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if($r->official_number)
                                        <span class="orders-badge" data-size="sm" data-tone="info">صادر رسميًا</span>
                                    @else
                                        <span class="orders-badge" data-size="sm" data-tone="neutral">غير صادر</span>
                                    @endif
                                    <span class="orders-badge" data-size="sm" data-tone="{{ $recTone }}">{{ $recLabel }}</span>
                                </div>
                            </td>
                            <td role="cell" data-label="الإجراءات" class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-1">
                                    <flux:button size="xs" variant="ghost" icon="eye" :href="route('purchasing.orders.show', $r->id)" wire:navigate aria-label="تفاصيل أمر الشراء" />
                                    @if($r->official_number)
                                        <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('purchasing.orders.pdf', $r->id)" aria-label="تحميل PDF الرسمي" />
                                    @endif
                                    @if($canReceiveRow)
                                        <flux:button size="xs" variant="ghost" icon="truck" :href="route('purchasing.receipts.create', ['order' => $r->id])" wire:navigate aria-label="مسودة استلام" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr role="row">
                            <td role="cell" colspan="9" class="p-8">
                                <x-ui.empty-state title="لا توجد أوامر شراء ضمن نطاقك" description="غيّر معايير البحث أو الفلاتر، أو راجع نطاق الفرع المصرح به." icon="shopping-cart">
                                    @if($canCreate)
                                        <flux:button variant="primary" :href="route('purchasing.orders.create')" wire:navigate>أمر شراء جديد</flux:button>
                                    @endif
                                </x-ui.empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.responsive-table>
        <div class="p-4">{{ $rows->links() }}</div>
    </x-erp.section-card>
    @endif
</div>
