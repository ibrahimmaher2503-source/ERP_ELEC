<div data-erp-archetype="{{ request()->routeIs('*.dashboard') ? 'dashboard' : (request()->routeIs('*.reports', '*.aging', '*.statements') ? 'report' : (request()->routeIs('*.create', '*.edit') ? 'form' : 'list')) }}" class="workshop-ui space-y-5">
<x-page.heading :title="['dashboard'=>'لوحة الورشة','settings'=>'إعداد الورش','intakes'=>'استلام المعدات','jobs'=>'أوامر الإصلاح','reports'=>'تقارير الورش'][$screen]" :description="$screen==='dashboard'?'متابعة الإصلاحات وحركة المعدات داخل الورشة':'عهدة المعدات والإصلاح والفحص والتسليم'" :breadcrumbs="['الورش']">
    <x-slot:actions>
        @if($screen==='dashboard'&&$workshops->where('active',true)->isEmpty()&&$access->branches(auth()->id(),'manage'))
            <flux:button variant="primary" icon="cog-6-tooth" :href="route('workshops.settings')" wire:navigate>تجهيز الورشة</flux:button>
        @elseif($access->branches(auth()->id(),'intake_manage'))
            <flux:button variant="primary" icon="plus" :href="route('workshops.intakes.create')" wire:navigate>استلام معدة</flux:button>
        @endif
    </x-slot:actions>
</x-page.heading>
@include('workshops.feedback')
<nav class="workshop-navigation" data-workshop-nav aria-label="أقسام الورش">
    @foreach(['dashboard'=>'نظرة عامة','jobs'=>'أوامر الإصلاح','intakes'=>'استلام المعدات','settings'=>'إعداد الورش','reports'=>'التقارير'] as $key=>$label)
        @if($key!=='reports'||$access->branches(auth()->id(),'reports_view'))
            <a href="{{ route('workshops.'.$key) }}" wire:navigate @if($screen===$key) aria-current="page" @endif>{{ $label }}</a>
        @endif
    @endforeach
</nav>
@if($screen==='dashboard')
    @include('workshops.overview')
@else
@if($screen==='reports')<div class="flex gap-2"><flux:button :href="route('workshops.exports',['format'=>'xlsx','workshop_id'=>$workshopFilter?:null,'status'=>$statusFilter?:null,'billing_mode'=>$billingFilter?:null,'overdue'=>$overdueOnly?1:0,'search'=>$search])">تصدير Excel</flux:button><flux:button :href="route('workshops.exports',['format'=>'pdf','workshop_id'=>$workshopFilter?:null,'status'=>$statusFilter?:null,'billing_mode'=>$billingFilter?:null,'overdue'=>$overdueOnly?1:0,'search'=>$search])">تصدير PDF</flux:button></div>@endif
@if(in_array($screen,['jobs','reports']))
<x-ui.panel><x-ui.filters id="workshop-filters" target="search,workshopFilter,statusFilter,billingFilter,overdueOnly" :reset="['search' => '', 'workshopFilter' => '', 'statusFilter' => '', 'billingFilter' => '', 'overdueOnly' => false]"><x-slot:search><flux:input label="البحث بالرقم أو العميل" wire:model.live.debounce.300ms="search" placeholder="رقم الأمر أو اسم العميل" icon="magnifying-glass"/></x-slot:search><div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
<flux:select label="الورشة" wire:model.live="workshopFilter"><option value="">كل الورش المصرح بها</option>@foreach($workshops as $ws)<option value="{{ $ws->id }}">{{ $ws->name }}</option>@endforeach</flux:select>
<flux:select label="الحالة" wire:model.live="statusFilter"><option value="">كل الحالات</option>@foreach(['inspection'=>'الفحص','waiting_approval'=>'انتظار الموافقة','approved'=>'معتمد','repair'=>'الإصلاح','waiting_parts'=>'انتظار القطع','testing'=>'الاختبار','verified'=>'متحقق','delivered'=>'مسلم','returned_unrepaired'=>'معاد دون إصلاح'] as $st=>$label)<option value="{{ $st }}">{{ $label }}</option>@endforeach</flux:select>
<flux:select label="الفوترة" wire:model.live="billingFilter"><option value="">كل الأنواع</option><option value="billable">مدفوع</option><option value="partial">مدفوع جزئياً</option><option value="warranty">ضمان</option><option value="nonbillable">غير مدفوع</option></flux:select>
<flux:checkbox label="الإصلاحات المتأخرة فقط" wire:model.live="overdueOnly" />
</div></x-ui.filters></x-ui.panel>
@endif
@if($screen==='reports')
@include('workshops.stage-counts')
<div class="grid gap-3 sm:grid-cols-4">@foreach(['عهدة لم تسلم'=>$undelivered,'انتظار موافقة'=>$stats['waiting_approval']??0,'انتظار قطع'=>$stats['waiting_parts']??0,'متوسط ساعات الإنجاز'=>$turnaround??'غير متاح'] as $label=>$value)<x-ui.panel><p class="text-sm text-slate-500">{{ $label }}</p><strong class="text-2xl text-blue-900">{{ $value }}</strong></x-ui.panel>@endforeach</div>
@endif
@if(in_array($screen,['settings','reports']))
<x-ui.panel><div class="overflow-x-auto"><x-ui.responsive-table label="السجلات المعروضة"><table role="table" class="w-full text-start text-sm"><thead role="rowgroup" class="bg-blue-50"><tr role="row">@foreach(['الكود','الورشة','التخصص','الطاقة','الإصلاحات المفتوحة','الحالة',''] as $h)<th role="columnheader" class="p-3 text-start">{{ $h }}</th>@endforeach</tr></thead><tbody role="rowgroup">@forelse($workshops as $w)<tr role="row" class="border-b" wire:key="workshop-{{ $w->id }}"><td role="cell" data-label="الكود" class="p-3"><div>{{ $w->code }}</div></td><td role="cell" data-label="الورشة" class="p-3"><div>{{ $w->name }}</div></td><td role="cell" data-label="التخصص" class="p-3"><div>{{ $w->specialty }}</div></td><td role="cell" data-label="الطاقة" class="p-3"><div>{{ $w->capacity }}</div></td><td role="cell" data-label="الإصلاحات المفتوحة" class="p-3"><div>{{ $w->open_jobs }}</div></td><td role="cell" data-label="الحالة" class="p-3"><div>{{ $w->active?'نشطة':'غير نشطة' }}</div></td><td role="cell" data-label="الإجراءات" class="p-3"><div>@if($screen==='settings'&&$access->allows(auth()->id(),$w->branch_id,'manage'))<flux:button size="sm" wire:click="editWorkshop({{ $w->id }})">تعديل</flux:button>@endif</div></td></tr>@empty<tr role="row"><td colspan="7" class="p-10 text-center">سجل أسماء الورش الفعلية وتخصصاتها من إعداد الورش.</td></tr>@endforelse</tbody></table></x-ui.responsive-table></div></x-ui.panel>
@endif

@if($screen==='settings'&&$branches)
<flux:button wire:click="newWorkshop">إضافة ورشة أخرى</flux:button>
<x-ui.panel><form wire:submit="saveWorkshop" class="space-y-4"><flux:heading>{{ $workshopId?'تعديل الورشة':'ورشة جديدة' }}</flux:heading><div class="grid gap-3 md:grid-cols-3"><flux:input label="كود الورشة" wire:model="form.code"/><flux:input label="اسم الورشة" wire:model="form.name"/><flux:input label="التخصص" wire:model="form.specialty"/><flux:select label="الفرع" wire:model.live="form.branch_id"><option value="">اختر الفرع</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select><flux:select label="المشرف" wire:model="form.supervisor_id"><option value="">اختر المشرف</option>@foreach($users as $u)<option value="{{ $u['id'] }}">{{ $u['name'] }}</option>@endforeach</flux:select><flux:select label="المستودع المرتبط" wire:model="form.warehouse_id"><option value="">بدون</option>@foreach($warehouses as $wh)<option value="{{ $wh['id'] }}">{{ $wh['name'] }}</option>@endforeach</flux:select><flux:input label="طاقة العمل المتزامنة" type="number" min="1" wire:model="form.capacity"/><flux:checkbox label="ورشة نشطة" wire:model="form.active"/></div>
@foreach($form['schedule'] as $n=>$day)<div class="grid gap-3 sm:grid-cols-3" wire:key="workshop-day-{{ $n }}"><flux:select label="يوم العمل" wire:model="form.schedule.{{ $n }}.day">@foreach(['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'] as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</flux:select><flux:input type="time" label="بداية العمل بالقاهرة" wire:model="form.schedule.{{ $n }}.start"/><flux:input type="time" label="نهاية العمل بالقاهرة" wire:model="form.schedule.{{ $n }}.end"/></div>@endforeach
<div class="flex gap-2"><flux:button type="button" wire:click="addDay">إضافة يوم</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ الورشة</flux:button></div></form></x-ui.panel>
@if($workshopId)<x-ui.panel><form wire:submit="saveMember" class="space-y-3"><flux:heading>الفنيون المعينون للورشة</flux:heading>@foreach($members as $m)<p>{{ collect($users)->firstWhere('id',$m->technician_id)['name']??'#'.$m->technician_id }} · {{ $m->active?'نشط':'غير نشط' }}</p>@endforeach<flux:select label="الفني" wire:model="technicianId"><option value="">اختر الفني</option>@foreach($users as $u)<option value="{{ $u['id'] }}">{{ $u['name'] }}</option>@endforeach</flux:select><flux:checkbox label="عضوية نشطة" wire:model="memberActive"/><flux:button type="submit" variant="primary">حفظ عضوية الفني</flux:button></form></x-ui.panel>@endif
@elseif(in_array($screen,['intakes','jobs','reports']))
@include('workshops.queue')
@endif
@if($screen==='reports')@include('workshops.report-execution')@endif
@if($screen==='reports')
<x-ui.panel><flux:heading>الأحمال الحالية</flux:heading>@forelse($workshops as $workshop)<p class="border-b py-3">{{ $workshop->name }} · {{ $workshop->open_jobs }} / {{ $workshop->capacity }}@if($workshop->open_jobs>$workshop->capacity)<span class="ms-2 text-amber-700">الحمل يتجاوز الطاقة المحددة</span>@endif</p>@empty<p class="py-4">لا توجد ورش مسجلة ضمن نطاقك.</p>@endforelse
<flux:heading class="mt-4">حمل الفنيين</flux:heading>@forelse($workload as $load)<p class="border-b py-3">الفني #{{ $load->technician_id }} · {{ $workshops->firstWhere('id',$load->workshop_id)?->name }} · {{ $load->total }} إصلاحات · {{ $load->planned_minutes }} دقيقة مخططة</p>@empty<p class="py-3">لا توجد إصلاحات مفتوحة معينة لفني.</p>@endforelse</x-ui.panel>
@endif
@endif
</div>
