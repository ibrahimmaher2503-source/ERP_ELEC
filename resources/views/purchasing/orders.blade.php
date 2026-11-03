<div data-erp-archetype="list" class="space-y-5">
    <x-page.heading title="أوامر الشراء" description="المراجعة التجارية منفصلة عن الإصدار الرسمي والاستلام والفوترة" :breadcrumbs="['المشتريات','أوامر الشراء']">
        <x-slot:actions>@if($canCreate)<flux:button variant="primary" icon="plus" :href="route('purchasing.orders.create')" wire:navigate>أمر شراء جديد</flux:button>@endif</x-slot:actions>
    </x-page.heading>
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach(['draft'=>['المسودات','blue'],'submitted'=>['بانتظار الاعتماد','amber'],'approved'=>['معتمد داخليًا','green'],'rejected'=>['مرفوض','red']] as $key=>[$label,$color])
            <button type="button" wire:click="$set('status','{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}" class="purchase-status-filter rounded-xl border border-slate-200 bg-white p-4 text-start">
                <flux:badge :color="$color">{{ $label }}</flux:badge><strong class="mt-3 block text-2xl">{{ $stats[$key]??0 }}</strong>
            </button>
        @endforeach
    </div>
    <x-ui.panel>
        <x-ui.filters id="purchase-order-filters" target="search,status" class="mb-5" :reset="['search' => '', 'status' => '']"><x-slot:search><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="البحث" type="search" placeholder="رقم الأمر أو اسم المورد أو رقم الإصدار" /></x-slot:search>
            <div class="grid gap-3 sm:grid-cols-2">

                <flux:select label="الحالة" wire:model.live="status">
                    <option value="">جميع الحالات</option>
                    @foreach(['draft'=>'مسودة','submitted'=>'بانتظار الاعتماد','approved'=>'معتمد داخليًا','rejected'=>'مرفوض'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    @foreach(\App\Modules\Purchasing\Application\PurchaseOrderProjection::RECEIVING_STATUSES as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </flux:select>
            </div>
        </x-ui.filters>
        <x-ui.responsive-table label="قائمة أوامر الشراء">
            <table role="table" class="w-full text-sm">
                <thead role="rowgroup" class="bg-blue-50 text-blue-900"><tr role="row">@foreach(['المرجع الداخلي','المورد','التاريخ','العملة','الصافي','حالة الاعتماد','الإصدار','الاستلام','الإجراءات'] as $label)<th role="columnheader" scope="col" class="p-3 text-start">{{ $label }}</th>@endforeach</tr></thead>
                <tbody role="rowgroup">
                    @forelse($rows as $r)
                        <tr role="row" wire:key="po-{{ $r->id }}" class="border-b border-slate-100">
                            <td role="cell" data-label="المرجع الداخلي" class="p-3"><bdi>#{{ $r->id }}</bdi></td>
                            <td role="cell" data-label="المورد" class="p-3">{{ $r->supplier_name }}</td>
                            <td role="cell" data-label="التاريخ" class="p-3"><bdi>{{ $r->order_date }}</bdi></td>
                            <td role="cell" data-label="العملة" class="p-3"><bdi>{{ $r->currency }}</bdi></td>
                            <td role="cell" data-label="الصافي" class="p-3"><bdi>{{ $r->net_amount }}</bdi></td>
                            <td role="cell" data-label="حالة الاعتماد" class="p-3">{{ ['draft'=>'مسودة','submitted'=>'بانتظار الاعتماد','approved'=>'معتمد داخليًا','rejected'=>'مرفوض'][$r->status] }}</td>
                            <td role="cell" data-label="الإصدار" class="p-3 text-amber-700"><bdi>{{ $r->official_number??'غير صادر' }}</bdi></td>
                            <td role="cell" data-label="الاستلام" class="p-3">{{ ['not_received'=>'لم يستلم','partially_received'=>'استلام جزئي','received'=>'مستلم بالكامل'][$r->receiving_status] }}</td>
                            <td role="cell" data-label="الإجراءات" class="p-3"><flux:button size="sm" :href="route('purchasing.orders.show',$r->id)" wire:navigate>التفاصيل</flux:button></td>
                        </tr>
                    @empty
                        <tr role="row"><td role="cell" colspan="9"><x-ui.empty-state title="لا توجد أوامر شراء ضمن نطاقك" description="غيّر معايير البحث أو راجع نطاق الفرع المصرح به." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.responsive-table>
        <div class="mt-4">{{ $rows->links() }}</div>
    </x-ui.panel>
</div>
