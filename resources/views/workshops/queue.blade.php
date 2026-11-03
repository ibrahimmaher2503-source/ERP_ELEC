@php
    $isJobs=in_array($screen,['dashboard','jobs','reports']);
@endphp
<section @class(['erp-panel rounded-xl border border-[#E5E8EE] bg-white p-5 sm:p-6'=>!($embedded??false)])>
    @unless($embedded??false)<div class="workshop-section-heading"><div><h2>{{ $isJobs?'أوامر الإصلاح':'المعدات المستلمة' }}</h2><p>افتح الملف لمتابعة المعدة من الاستلام حتى التسليم والفاتورة.</p></div></div>@endunless
    @unless(in_array($screen,['jobs','reports']))<x-ui.filters id="workshop-queue-filters" target="search" :reset="['search' => '']"><x-slot:search><flux:input label="البحث بالرقم أو العميل" wire:model.live.debounce.300ms="search" placeholder="رقم الأمر أو اسم العميل" icon="magnifying-glass"/></x-slot:search></x-ui.filters>@endunless
    <div class="mt-5" wire:loading.class="opacity-60">
    <x-ui.responsive-table label="{{ $isJobs?'أوامر الإصلاح':'عهدة المعدات' }}"><table class="w-full text-start text-sm"><thead><tr><th>الرقم والعميل</th><th>المعدة</th><th>الورشة</th><th>الحالة</th><th>المتابعة</th></tr></thead><tbody>
    @forelse($rows as $row)
        @php
            $equipmentRow=$equipment->get($isJobs?$row->intake_id:$row->id);
            $nextTab=match($row->status){'inspection'=>'details','waiting_approval'=>'estimate','approved','repair','on_hold'=>'work','waiting_parts'=>'parts','testing'=>'testing','verified','rejected'=>'delivery',default=>'billing'};
            $nextLabel=match($row->status){'draft'=>'استكمال الاستلام','received'=>'إنشاء أمر إصلاح','inspection'=>'الفحص والإسناد','waiting_approval'=>'مراجعة العرض','approved'=>'بدء الإصلاح','repair','on_hold'=>'متابعة التنفيذ','waiting_parts'=>'إدارة القطع','testing'=>'الاختبار والجودة','verified'=>'إعداد التسليم','rejected'=>'مراجعة قرار الإصلاح',default=>'فتح الملف'};
        @endphp
        <tr wire:key="workshop-row-{{ $row->id }}">
            <td data-label="الرقم والعميل"><strong>{{ $row->number }}</strong><p class="mt-1 text-xs text-slate-500" title="{{ $row->customer_name }}">{{ \Illuminate\Support\Str::limit($row->customer_name,60) }}</p></td>
            <td data-label="المعدة"><p title="{{ $equipmentRow?->equipment_description }}">{{ \Illuminate\Support\Str::limit($equipmentRow?->equipment_description,55) }}</p><p class="mt-1 text-xs text-slate-500">{{ $equipmentRow?->serial_number ?: 'مسلسل غير مسجل' }}</p></td>
            <td data-label="الورشة">{{ $workshops->firstWhere('id',$row->workshop_id)?->name ?? 'لم تسند بعد' }}</td>
            <td data-label="الحالة">@include('workshops.status',['status'=>$row->status])</td>
            <td data-label="المتابعة"><flux:button size="sm" :href="$isJobs?route('workshops.jobs.show',['job'=>$row->id,'tab'=>$nextTab]):route('workshops.intakes.show',$row->id)" wire:navigate>{{ $nextLabel }}</flux:button></td>
        </tr>
    @empty
        <tr><td colspan="5"><x-ui.empty-state :title="$screen==='dashboard'?'لا توجد أوامر إصلاح مفتوحة':'لا توجد سجلات مطابقة'" description="ابدأ باستلام المعدة ثم تأكيد عهدتها وإنشاء أمر الإصلاح، أو عدّل معايير البحث. السجلات تظهر بحسب نطاق الفرع المسموح." /></td></tr>
    @endforelse
    </tbody></table></x-ui.responsive-table>
    </div><div class="mt-4">{{ $rows->links() }}</div>
</section>
