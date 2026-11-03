<div data-erp-archetype="list" class="space-y-5">
    <x-page.heading title="طلبات الاحتياج" description="إدارة احتياجات الشراء ومراجعتها قبل بدء التوريد" :breadcrumbs="['المشتريات','طلبات الاحتياج']">
        <x-slot:actions>@if($canCreate)<flux:button variant="primary" icon="plus" :href="route('purchasing.requisitions.create')" wire:navigate>طلب احتياج جديد</flux:button>@endif</x-slot:actions>
    </x-page.heading>
    <div class="erp-kpi-summary grid gap-4 sm:grid-cols-4">
        @foreach(['draft'=>['المسودات','bg-blue-50 text-blue-700'],'submitted'=>['بانتظار الاعتماد','bg-amber-50 text-amber-700'],'approved'=>['المعتمدة','bg-emerald-50 text-emerald-700'],'rejected'=>['المرفوضة','bg-rose-50 text-rose-700']] as $state=>[$label,$color])
        <button type="button" wire:click="$set('status','{{ $state }}')" aria-pressed="{{ $status === $state ? 'true' : 'false' }}" class="purchase-status-filter rounded-xl border border-slate-200 bg-white p-5 text-start"><span class="rounded-lg px-2 py-1 text-sm {{ $color }}">{{ $label }}</span><strong class="mt-3 block text-3xl text-slate-800">{{ $stats[$state] ?? 0 }}</strong></button>
        @endforeach
    </div>
    <x-ui.panel>
        <x-ui.filters id="requisition-filters" target="search,branch,status" :reset="['search' => '', 'branch' => '', 'status' => '']"><x-slot:search><flux:input label="البحث" wire:model.live.debounce.300ms="search" placeholder="ابحث في سبب الاحتياج..." icon="magnifying-glass" /></x-slot:search><div class="mb-5 grid gap-3 sm:grid-cols-3">

            <flux:select label="الفرع" wire:model.live="branch"><option value="">جميع الفروع المتاحة</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
            <flux:select label="الحالة" wire:model.live="status"><option value="">جميع الحالات</option>@foreach(['draft'=>'مسودة','submitted'=>'بانتظار الاعتماد','approved'=>'معتمد','rejected'=>'مرفوض'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</flux:select>
        </div></x-ui.filters>
        <div class="overflow-x-auto"><x-ui.responsive-table label="السجلات المعروضة"><table role="table" class="w-full text-start text-sm"><thead role="rowgroup" class="bg-blue-50 text-blue-900"><tr role="row">@foreach(['المرجع الداخلي','التاريخ','الفرع','سبب الاحتياج','البنود','الحالة',''] as $label)<th role="columnheader" class="p-3 text-start">{{ $label }}</th>@endforeach</tr></thead><tbody role="rowgroup">
        @forelse($rows as $row)<tr role="row" wire:key="req-{{ $row->id }}" class="border-b border-slate-100"><td role="cell" data-label="المرجع الداخلي" class="p-3"><div>#{{ $row->id }}</div></td><td role="cell" data-label="التاريخ" class="p-3"><div>{{ $row->request_date }}</div></td><td role="cell" data-label="الفرع" class="p-3"><div>{{ collect($branches)->firstWhere('id',$row->branch_id)['name'] ?? '' }}</div></td><td role="cell" data-label="سبب الاحتياج" class="max-w-64 truncate p-3"><div>{{ $row->purpose }}</div></td><td role="cell" data-label="البنود" class="p-3"><div>{{ $row->lines_count }}</div></td><td role="cell" data-label="الحالة" class="p-3"><div><flux:badge :color="['draft'=>'zinc','submitted'=>'amber','approved'=>'green','rejected'=>'red'][$row->status]">{{ ['draft'=>'مسودة','submitted'=>'بانتظار الاعتماد','approved'=>'معتمد','rejected'=>'مرفوض'][$row->status] }}</flux:badge></div></td><td role="cell" data-label="الإجراءات" class="p-3"><div><flux:button size="sm" :href="route('purchasing.requisitions.show',$row->id)" wire:navigate>التفاصيل</flux:button></div></td></tr>
        @empty<tr role="row"><td colspan="7" class="p-10 text-center text-slate-500">لا توجد طلبات احتياج ضمن نطاقك.</td></tr>@endforelse
        </tbody></table></x-ui.responsive-table></div><div class="mt-4">{{ $rows->links() }}</div>
    </x-ui.panel>
</div>
