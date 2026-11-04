<div data-erp-archetype="report" class="space-y-5">
    <x-page.heading :title="$nav[$screen]" description="بيانات مالية مرحّلة فعلياً بالجنيه المصري" :breadcrumbs="['المحاسبة والمالية', $nav[$screen]]" />
    <div class="erp-section-nav flex flex-wrap gap-2">
        @foreach($nav as $destination=>$label)
            <flux:button :href="route('accounting.'.$destination)" :variant="$screen===$destination?'primary':'outline'" size="sm" wire:navigate>{{ $label }}</flux:button>
        @endforeach
    </div>
    @if(session('accounting_message'))<div role="status" class="rounded border border-emerald-200 bg-emerald-50 p-3 text-emerald-800">{{ session('accounting_message') }}</div>@endif
    <x-ui.validation-summary />

    @if($screen!=='closing')
        <x-ui.panel>
            <x-ui.filters id="accounting-report-filters" target="branch,from,to" :reset="['branch' => '', 'from' => now('Africa/Cairo')->startOfYear()->toDateString(), 'to' => now('Africa/Cairo')->toDateString()]"><div class="grid gap-3 md:grid-cols-3">
                <flux:select wire:model.live="branch" label="نطاق التقرير"><option value="">إجمالي الشركة</option>@foreach($branches as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }}</option>@endforeach</flux:select>
                @if(!in_array($screen,['balance-sheet','grni','reconciliation'],true))<flux:input wire:model.live="from" type="date" label="من تاريخ"/>@endif
                <flux:input wire:model.live="to" type="date" label="إلى تاريخ"/>
            </div></x-ui.filters>
        </x-ui.panel>
    @endif

    @if($filtersValid)
    <x-ui.panel>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2>{{ $nav[$screen] }}</h2><div class="flex gap-2"><span class="self-center text-xs text-slate-500">ج.م · بيانات مرحّلة</span>@if(in_array($screen,['ledger','trial-balance','income-statement','balance-sheet','ppv','reconciliation'],true))<flux:button :href="route('accounting.reports.export',['report'=>$screen,'format'=>'xlsx','branch'=>$branch?:null,'from'=>$from?:null,'to'=>$to?:null])" size="sm" icon="arrow-down-tray">Excel</flux:button><flux:button :href="route('accounting.reports.export',['report'=>$screen,'format'=>'pdf','branch'=>$branch?:null,'from'=>$from?:null,'to'=>$to?:null])" size="sm" icon="document-arrow-down">PDF</flux:button>@endif</div></div>
        <div class="overflow-x-auto">
            <x-ui.responsive-table :label="$nav[$screen]"><table role="table">
                @if($screen==='ledger')
                    <thead role="rowgroup"><tr role="row"><th role="columnheader">التاريخ</th><th role="columnheader">رقم القيد</th><th role="columnheader">الحساب</th><th role="columnheader">البيان</th><th role="columnheader">مدين</th><th role="columnheader">دائن</th></tr></thead>
                    <tbody role="rowgroup">@forelse($data['rows'] as $row)<tr role="row"><td role="cell" data-label="التاريخ"><div>{{ $row->journal_date }}</div></td><td role="cell" data-label="رقم القيد" dir="ltr"><div>{{ $row->official_number }}</div></td><td role="cell" data-label="الحساب" dir="ltr"><div>{{ $row->account_code }} <span dir="rtl">{{ $row->account_name }}</span></div></td><td role="cell" data-label="البيان"><div>{{ $row->description }}</div></td><td role="cell" data-label="مدين"><div>{{ $row->debit }}</div></td><td role="cell" data-label="دائن"><div>{{ $row->credit }}</div></td></tr>@empty<tr role="row"><td colspan="6"><x-ui.empty-state title="لا توجد حركات مرحّلة" description="غيّر النطاق أو الفترة لعرض حركات الأستاذ العام."/></td></tr>@endforelse</tbody>
                @elseif(in_array($screen,['trial-balance','income-statement','balance-sheet'],true))
                    <thead role="rowgroup"><tr role="row"><th role="columnheader">الكود</th><th role="columnheader">الحساب</th>@if($screen!=='trial-balance')<th role="columnheader">التصنيف</th>@endif<th role="columnheader">مدين</th><th role="columnheader">دائن</th><th role="columnheader">الرصيد</th></tr></thead>
                    <tbody role="rowgroup">@forelse($data['rows'] as $row)<tr role="row"><td role="cell" data-label="الكود" dir="ltr"><div>{{ $row->account_code }}</div></td><td role="cell" data-label="الحساب"><div>{{ $row->account_name }}</div></td>@if($screen!=='trial-balance')<td role="cell" data-label="التصنيف"><div>{{ $row->kind }}</div></td>@endif<td role="cell" data-label="مدين"><div>{{ $row->debit }}</div></td><td role="cell" data-label="دائن"><div>{{ $row->credit }}</div></td><td role="cell" data-label="الرصيد"><div>{{ $row->balance }}</div></td></tr>@empty<tr role="row"><td colspan="6"><x-ui.empty-state title="لا توجد أرصدة" description="لا توجد قيود مرحّلة ضمن النطاق المحدد."/></td></tr>@endforelse</tbody>
                @elseif($screen==='ppv')
                    <thead role="rowgroup"><tr role="row"><th role="columnheader">الفاتورة</th><th role="columnheader">المورد</th><th role="columnheader">التاريخ</th><th role="columnheader">GRNI</th><th role="columnheader">PPV</th><th role="columnheader">الإجمالي</th></tr></thead>
                    <tbody role="rowgroup">@forelse($data['rows'] as $row)<tr role="row"><td role="cell" data-label="الفاتورة" dir="ltr"><div>{{ $row['official_number'] }}</div></td><td role="cell" data-label="المورد"><div>{{ $row['supplier_name'] }}</div></td><td role="cell" data-label="التاريخ"><div>{{ $row['invoice_date'] }}</div></td><td role="cell" data-label="GRNI"><div>{{ $row['grni_amount'] }}</div></td><td role="cell" data-label="PPV"><div>{{ $row['ppv_amount'] }}</div></td><td role="cell" data-label="الإجمالي"><div>{{ $row['total_amount'] }}</div></td></tr>@empty<tr role="row"><td colspan="6"><x-ui.empty-state title="لا توجد فروق أسعار" description="تظهر الفواتير المرحّلة هنا عند وجودها."/></td></tr>@endforelse</tbody>
                @elseif(in_array($screen,['grni','reconciliation'],true))
                    <thead role="rowgroup"><tr role="row"><th role="columnheader">المصالحة</th><th role="columnheader">رصيد الأستاذ</th><th role="columnheader">رصيد المصدر</th><th role="columnheader">الفرق</th><th role="columnheader">النتيجة</th></tr></thead>
                    <tbody role="rowgroup">@foreach($data['rows'] as $row)@if($screen==='reconciliation'||$row['key']==='grni')<tr role="row"><td role="cell" data-label="المصالحة"><div>{{ $row['label'] }}</div></td><td role="cell" data-label="رصيد الأستاذ"><div>{{ $row['gl'] }}</div></td><td role="cell" data-label="رصيد المصدر"><div>{{ $row['source'] }}</div></td><td role="cell" data-label="الفرق"><div>{{ $row['difference'] }}</div></td><td role="cell" data-label="النتيجة"><div><span class="rounded px-2 py-1 text-xs {{ $row['ok']?'bg-emerald-50 text-emerald-700':'bg-red-50 text-red-700' }}">{{ $row['ok']?'متطابق':'فرق قائم' }}</span></div></td></tr>@endif @endforeach</tbody>
                @else
                    <thead role="rowgroup"><tr role="row"><th role="columnheader">الفترة</th><th role="columnheader">البداية</th><th role="columnheader">النهاية</th><th role="columnheader">الحالة</th><th role="columnheader">الإجراء</th></tr></thead>
                    <tbody role="rowgroup">@foreach($data['rows'] as $row)<tr role="row"><td role="cell" data-label="الفترة"><div>{{ $row->name }}</div></td><td role="cell" data-label="البداية"><div>{{ $row->starts_on }}</div></td><td role="cell" data-label="النهاية"><div>{{ $row->ends_on }}</div></td><td role="cell" data-label="الحالة"><div>{{ $row->status==='open'?'مفتوحة':'مغلقة' }}</div></td><td role="cell" data-label="الإجراء"><div>@if($row->status==='open'&&$access->allows(auth()->id(),'period_close'))<flux:button wire:click="close({{ $row->id }})" wire:confirm="لن يتم الإقفال إلا بعد نجاح المصالحات. متابعة؟" size="sm">إقفال بعد المصالحة</flux:button>@endif</div></td></tr>@endforeach</tbody>
                @endif
            </table></x-ui.responsive-table>
        </div>
    </x-ui.panel>
    @endif
</div>
