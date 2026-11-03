<div data-erp-archetype="report" class="space-y-5">
    <x-page.heading title="الملخص المالي للمشروع" :description="$project->name" :breadcrumbs="['المشروعات']">
        <x-slot:actions><flux:button :href="route('projects.show', $project->id)" wire:navigate>عرض المشروع</flux:button>@if(app(\App\Modules\Projects\Application\ProjectAccess::class)->allows(auth()->id(),$project,'export'))<flux:button :href="route('projects.financial.export',[$project->id,'xlsx'])">Excel</flux:button><flux:button :href="route('projects.financial.export',[$project->id,'pdf'])">PDF</flux:button>@endif</x-slot:actions>
    </x-page.heading>
    @if($summary['over_budget'])<div role="alert" class="rounded border border-red-200 bg-red-50 p-4 text-red-700">تجاوزت التكاليف المعترف بها الميزانية المعتمدة.</div>@endif
    <x-ui.panel>
        <h2 class="mb-4 font-bold text-blue-900">الميزانية والتكلفة والإيراد، EGP</h2>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach(['approved_budget'=>'الميزانية المعتمدة','remaining_budget'=>'المتبقي بعد التكلفة الفعلية','forecast_remaining'=>'المتبقي بعد الالتزامات المفتوحة'] as $key=>$label)
                <div class="border-b pb-3"><dt class="text-sm text-slate-600">{{ $label }}</dt><dd class="mt-1 font-bold tabular-nums">{{ $summary[$key] ?? 'غير معتمد' }}</dd></div>
            @endforeach
            @foreach(['recognized_cost'=>'التكلفة المعترف بها','material_cost'=>'مواد وصافي تكلفة المبيعات','ppv'=>'فروق أسعار الشراء','recognized_revenue'=>'الإيراد المعترف به','margin'=>'الهامش المعترف به'] as $key=>$label)
                <div class="border-b pb-3"><dt class="text-sm text-slate-600">{{ $label }}</dt><dd class="mt-1 font-bold tabular-nums">{{ $key==='margin' ? ($summary['recognized_margin'] ?? 'غير متاح؛ يلزم اكتمال التخصيص ومصالحة FIFO وGL') : $summary['accounting'][$key] }}</dd></div>
            @endforeach
        </dl>
        <p class="mt-4 text-sm text-slate-600">{{ $summary['estimate_unavailable_reason'] }}</p>
        <p class="mt-2 text-sm text-slate-600">المخزون المشترى أصل حتى صرفه. الاستلام والفاتورة والسداد مراحل مختلفة، ولا تجمع كتكاليف مستقلة.</p>
    </x-ui.panel>
    <x-ui.panel>
        <h2 class="mb-4 font-bold text-blue-900">المشتريات والذمم والتدفقات النقدية</h2>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach(['open_commitments'=>'التزامات شراء لم تستلم','received_value'=>'المستلم بتقييم GRNI'] as $key=>$label)<div><dt class="text-sm text-slate-600">{{ $label }}</dt><dd class="font-bold">{{ $summary['purchasing'][$key] }}</dd></div>@endforeach
            @foreach(['supplier_invoiced'=>'فواتير الموردين','customer_invoiced'=>'فواتير العملاء','supplier_credit'=>'إشعارات الموردين','customer_credit'=>'إشعارات العملاء','ap_due'=>'مستحق للموردين','ar_due'=>'مستحق على العملاء','paid'=>'مدفوعات مخصصة','collected'=>'متحصلات مخصصة'] as $key=>$label)<div><dt class="text-sm text-slate-600">{{ $label }}</dt><dd class="font-bold">{{ $summary['subledger'][$key] }}</dd></div>@endforeach
        </dl>
        @if($summary['subledger']['unallocated_invoices'])<p class="mt-4 text-amber-800">فواتير تاريخية بلا بُعد مشروع: {{ $summary['subledger']['unallocated_invoices'] }}. تعرض كارتباط تجاري ولا تضاف إلى GL المشروع.</p>@endif
        <div role="region" tabindex="0" aria-label="جدول تفصيلي؛ يمكن التمرير أفقياً لعرض جميع الأعمدة" class="mt-5 overflow-x-auto"><table class="w-full text-start text-sm"><thead class="bg-blue-50"><tr>@foreach(['النوع','المصدر','الإجمالي','المسدد','المتبقي','التخصيص'] as $h)<th class="p-3 text-start">{{ $h }}</th>@endforeach</tr></thead><tbody>
        @forelse($summary['subledger']['invoices'] as $i)<tr class="border-b"><td class="p-3">{{ $i['kind']==='supplier'?'مورد':'عميل' }}</td><td class="p-3"><a class="text-blue-700" href="{{ route('financial.invoices.show', [$i['kind'],$i['id']]) }}" wire:navigate>{{ $i['number'] }}</a></td><td class="p-3">{{ $i['total'] }}</td><td class="p-3">{{ $i['settled'] }}</td><td class="p-3">{{ $i['remaining'] }}</td><td class="p-3">{{ $i['attributed']?'مثبت عند الإنشاء':'غير مخصص تاريخياً' }}</td></tr>@empty<tr><td colspan="6" class="p-5 text-slate-500">لا توجد فواتير مرتبطة.</td></tr>@endforelse
        </tbody></table></div>
    </x-ui.panel>
    <x-ui.panel>
        <h2 class="mb-4 font-bold text-blue-900">مصادر التكلفة والمصالحة</h2>
        <p>المواد المصروفة: {{ $summary['inventory']['issued_quantity'] }} · FIFO: {{ $summary['inventory']['fifo_cost'] }} · FIFO تسليم المبيعات: {{ $summary['inventory']['sales_fifo_cost'] }} · فرق FIFO وGL: {{ $summary['fifo_gl_difference'] }}</p>
        <div role="region" tabindex="0" aria-label="جدول تفصيلي؛ يمكن التمرير أفقياً لعرض جميع الأعمدة" class="mt-4 overflow-x-auto"><table class="w-full text-start text-sm"><thead class="bg-blue-50"><tr><th class="p-3 text-start">القيد</th><th class="p-3 text-start">مرجع المصدر</th><th class="p-3 text-start">الحساب</th><th class="p-3 text-start">مدين</th><th class="p-3 text-start">دائن</th></tr></thead><tbody>@foreach($summary['accounting']['rows'] as $r)<tr class="border-b"><td class="p-3">{{ $r['official_number'] }}</td><td class="p-3">{{ $r['source_key'] }}</td><td class="p-3">{{ $r['account_id'] }}</td><td class="p-3">{{ $r['debit'] }}</td><td class="p-3">{{ $r['credit'] }}</td></tr>@endforeach</tbody></table></div>
    </x-ui.panel>
</div>
