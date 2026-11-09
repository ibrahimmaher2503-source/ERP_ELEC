<div data-erp-archetype="list" class="space-y-5">
    <x-page.heading title="استلام المشتريات" description="الترحيل المؤكد يحفظ الاستلام والمخزون وFIFO وGRNI معًا" :breadcrumbs="['المشتريات','استلام المشتريات']">
        <x-slot:actions>@if($canCreate)<flux:button variant="primary" icon="plus" :href="route('purchasing.receipts.create')" wire:navigate>مسودة استلام جديدة</flux:button>@endif</x-slot:actions>
    </x-page.heading>
    <div class="rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ \App\Modules\Purchasing\Application\PurchaseOrders::ISSUE_BLOCKER }}</div>
    <x-ui.panel>
        <div class="overflow-x-auto"><x-ui.responsive-table label="السجلات المعروضة"><table role="table" class="w-full text-sm"><thead role="rowgroup" class="bg-blue-50 text-blue-900"><tr role="row">
            @foreach(['المرجع الداخلي','أمر الشراء','التاريخ','مرجع التوريد','الحالة','المخزون',''] as $label)<th role="columnheader" class="p-3 text-start">{{ $label }}</th>@endforeach
        </tr></thead><tbody role="rowgroup">
            @forelse($rows as $r)<tr role="row" wire:key="receipt-{{ $r->id }}" class="border-b border-slate-100">
                <td role="cell" data-label="المرجع الداخلي" class="p-3"><div>#{{ $r->id }}</div></td><td role="cell" data-label="أمر الشراء" class="p-3"><div>#{{ $r->order_id }}</div></td><td role="cell" data-label="التاريخ" class="p-3"><div>{{ $r->receipt_date }}</div></td><td role="cell" data-label="مرجع التوريد" class="p-3"><div>{{ $r->delivery_reference }}</div></td>
                <td role="cell" data-label="الحالة" class="p-3"><div>{{ ['draft'=>'مسودة','integration_pending'=>'بانتظار المراجعة والترحيل','posted'=>'مرحل وثابت'][$r->status] }}</div></td>
                <td role="cell" data-label="المخزون" class="p-3 {{ $r->status==='posted'?'text-emerald-800':'text-amber-800' }}"><div>{{ $r->status==='posted'?'مرحل مع الصرف من الأقدم أولًا وGRNI':'لم يرحل' }}</div></td>
                <td role="cell" data-label="الإجراءات" class="p-3"><div><flux:button size="sm" :href="route('purchasing.receipts.show',$r->id)" wire:navigate>التفاصيل</flux:button></div></td>
            </tr>@empty<tr role="row"><td colspan="7" class="p-10"><x-ui.empty-state title="لا توجد استلامات" description="تظهر هنا الاستلامات المحفوظة ضمن فروعك ومستودعاتك المتاحة." /></td></tr>@endforelse
        </tbody></table></x-ui.responsive-table></div>
        <div class="mt-4">{{ $rows->links() }}</div>
    </x-ui.panel>
</div>
