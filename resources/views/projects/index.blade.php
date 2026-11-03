<div data-erp-archetype="list" class="projects-ui space-y-5">
    <x-page.heading title="المشروعات" description="المواعيد والإنجاز وسجل المشروعات في مكان واحد" :breadcrumbs="['الرئيسية','المشروعات']">
        <x-slot:actions>
            @if($canReport)<flux:button icon="document-chart-bar" :href="route('projects.reports')" wire:navigate>التقارير</flux:button>@endif
            @if($canCreate)<flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>مشروع جديد</flux:button>@endif
        </x-slot:actions>
    </x-page.heading>
    @php
        $states=['draft'=>'مسودة','planned'=>'مخطط','in_progress'=>'قيد التنفيذ','active'=>'نشط','on_hold'=>'متوقف مؤقتًا','completed'=>'مكتمل','closed'=>'مغلق','cancelled'=>'ملغي'];
        $tones=['draft'=>'neutral','planned'=>'info','in_progress'=>'info','active'=>'success','on_hold'=>'warning','completed'=>'success','closed'=>'neutral','cancelled'=>'neutral'];
    @endphp
    <section class="projects-overview" aria-label="ملخص المشروعات المتاحة">
        <div><p>إجمالي المشروعات</p><strong>{{ $stats->sum() }}</strong><span>ضمن نطاقك المسموح</span></div>
        <div><p>التنفيذ الجاري</p><strong>{{ ($stats['in_progress']??0)+($stats['active']??0) }}</strong><span>نشطة أو قيد التنفيذ</span></div>
        <div><p>تجاوزت موعدها المخطط</p><strong @class(['text-amber-700'=>$overdue>0])>{{ $overdue }}</strong><span>مشروعات مفتوحة تحتاج متابعة</span></div>
        <div><p>متوقفة مؤقتًا</p><strong>{{ $stats['on_hold']??0 }}</strong><span>راجع العوائق قبل الاستئناف</span></div>
    </section>
    @if($deadlines->isNotEmpty())
    <section class="projects-deadlines" aria-labelledby="project-deadlines-title">
        <div class="projects-section-heading"><div><h2 id="project-deadlines-title">المواعيد الأقرب للمتابعة</h2><p>أقرب أربعة مواعيد نهاية للمشروعات المفتوحة، وتشمل المتأخر منها.</p></div><span class="text-xs text-slate-500">{{ $today }}</span></div>
        <div class="projects-deadline-list">
            @foreach($deadlines as $deadline)
                <a href="{{ route('projects.show',$deadline->id) }}" wire:navigate class="projects-deadline" wire:key="project-deadline-{{ $deadline->id }}">
                    <span @class(['projects-deadline-date','is-overdue'=>$deadline->end_date<$today])><bdi>{{ $deadline->end_date }}</bdi><small>{{ $deadline->end_date<$today?'تجاوز الموعد المخطط':($deadline->end_date===$today?'موعده اليوم':'موعد مخطط') }}</small></span>
                    <span class="min-w-0"><strong>{{ $deadline->name }}</strong><small>{{ $deadline->code }} · {{ $deadline->customer_name }}</small></span>
                    <flux:icon.chevron-left class="size-4 shrink-0 text-slate-400 rtl:rotate-0 ltr:rotate-180"/>
                </a>
            @endforeach
        </div>
    </section>
    @endif
    <x-ui.panel>
        <div class="projects-section-heading"><div><h2>سجل المشروعات</h2><p>ابحث عن المشروع، راجع تقدمه، ثم افتح تفاصيله لمتابعة المراحل والمهام.</p></div><span class="projects-result-count" role="status">{{ $rows->total() }} نتيجة</span></div>
        <x-ui.filters id="project-filters" target="search,status,clearFilters" :reset="['search' => '', 'status' => '']" clear="clearFilters">
            <x-slot:search><flux:input label="البحث عن مشروع" icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="اسم المشروع أو الكود"/></x-slot:search>
            <flux:select label="حالة المشروع" wire:model.live="status"><option value="">جميع الحالات</option>@foreach($states as $state=>$label)<option value="{{ $state }}">{{ $label }} ({{ $stats[$state]??0 }})</option>@endforeach</flux:select>
            </x-ui.filters>
        @if($search!==''||$status!=='')<p class="mt-3 text-xs text-slate-500">السجل يعرض النتائج المطابقة. الملخص والمواعيد أعلاه يشملان جميع المشروعات المتاحة لك.</p>@endif
        <div class="mt-5" wire:loading.class="opacity-50" wire:target="search,status,clearFilters,gotoPage,nextPage,previousPage">
            <x-ui.responsive-table label="سجل المشروعات">
                <table class="w-full text-start text-sm"><thead><tr><th>المشروع</th><th>العميل</th><th>الفترة المخططة</th><th>إنجاز العمل</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody>
                    @forelse($rows as $row)
                        @php
                            $projectProgress=$progress[$row->id];
                            $isLate=$row->end_date && $row->end_date<$today && !in_array($row->status,['draft','completed','closed','cancelled']);
                        @endphp
                        <tr wire:key="project-{{ $row->id }}">
                            <td data-label="المشروع"><div><a class="projects-project-name" href="{{ route('projects.show',$row->id) }}" wire:navigate>{{ $row->name }}</a><p class="mt-1 text-xs text-slate-500"><bdi>{{ $row->code }}</bdi></p></div></td>
                            <td data-label="العميل"><div>{{ $row->customer_name }}</div></td>
                            <td data-label="الفترة المخططة"><div class="projects-period"><span><small>من</small> <bdi>{{ $row->start_date }}</bdi></span><span @class(['text-amber-700'=>$isLate])><small>إلى</small> <bdi>{{ $row->end_date }}</bdi></span>@if($isLate)<span class="text-xs text-amber-700">تجاوز الموعد المخطط</span>@endif</div></td>
                            <td data-label="إنجاز العمل"><div>
                                @if($projectProgress['eligible'])<div class="projects-progress"><strong><bdi>{{ $projectProgress['percent'] }}%</bdi></strong><progress value="{{ $projectProgress['percent'] }}" max="100" aria-label="إنجاز {{ $row->name }}">{{ $projectProgress['percent'] }}%</progress><small>{{ $projectProgress['eligible'] }} مراحل محسوبة</small></div>@else<span class="text-xs text-slate-500">لم تحدد مراحل العمل</span>@endif
                            </div></td>
                            <td data-label="الحالة"><div><span class="projects-status" data-tone="{{ $tones[$row->status]??'neutral' }}">{{ $states[$row->status]??$row->status }}</span></div></td>
                            <td data-label="الإجراء"><div><flux:button size="sm" :href="route('projects.show',$row->id)" wire:navigate>فتح المشروع</flux:button></div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state :title="$search!==''||$status!==''?'لا توجد مشروعات مطابقة':'لا توجد مشروعات متاحة'" :description="$search!==''||$status!==''?'جرّب اسمًا أو كودًا آخر، أو امسح التصفية لعرض السجل المتاح.':'تظهر المشروعات بحسب صلاحياتك ونطاق الفرع وعضوية فريق المشروع.'"/></td></tr>
                    @endforelse
                </tbody></table>
            </x-ui.responsive-table>
        </div>
        <div class="projects-table-footer"><p>نسبة الإنجاز محسوبة من المراحل والمهام، ومستقلة عن الإنفاق.</p><div>{{ $rows->links() }}</div></div>
    </x-ui.panel>
</div>
