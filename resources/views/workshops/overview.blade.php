@php
    $activeWorkshops=$workshops->where('active',true);
    $openCount=array_sum(array_diff_key($stats,array_flip(['delivered','returned_unrepaired'])));
    $hasActivity=$undelivered>0||array_sum($stats)>0;
    $displayedWorkshops=($activeWorkshops->isNotEmpty()?$activeWorkshops:$workshops)->take(4);
@endphp
@if($hasActivity)
<section class="workshop-overview-totals" aria-label="ملخص التشغيل">
    <div><span>إصلاحات مفتوحة</span><strong>{{ $openCount }}</strong></div>
    <div><span>معدات في العهدة</span><strong>{{ $undelivered }}</strong></div>
    <div><span>جاهزة للتسليم</span><strong>{{ $stats['verified']??0 }}</strong></div>
    @if($turnaround!==null)<div><span>متوسط إنجاز الإصلاح</span><strong>{{ $turnaround }} <small>ساعة</small></strong></div>@endif
</section>
@endif
<div class="workshop-overview-layout">
    <section class="workshop-workbench" aria-labelledby="workshop-workbench-title">
        <div class="workshop-workbench-heading">
            <div><h2 id="workshop-workbench-title">متابعة الإصلاحات</h2><p>الأوامر المفتوحة، والخطوة التالية لكل معدة.</p></div>
            <span class="workshop-total-label">{{ $openCount }} أوامر مفتوحة</span>
        </div>
        @if($hasActivity)
            <nav class="workshop-queue-tabs" aria-label="حالات الإصلاح">
                @foreach(['inspection'=>'الفحص','waiting_approval'=>'الموافقة','repair'=>'التنفيذ','waiting_parts'=>'انتظار قطع','testing'=>'الاختبار','verified'=>'التسليم'] as $state=>$label)
                    <a href="{{ route('workshops.jobs',['statusFilter'=>$state]) }}" wire:navigate>{{ $label }}<span>{{ $stats[$state]??0 }}</span></a>
                @endforeach
            </nav>
        @endif
        @if($rows->total()>0||$search!=='')
            <div class="workshop-workbench-records">@include('workshops.queue',['embedded'=>true])</div>
        @else
            <div class="workshop-start-state">
                <div class="workshop-start-icon"><flux:icon.wrench-screwdriver class="size-7" aria-hidden="true"/></div>
                <h3>{{ $activeWorkshops->isEmpty()?'ابدأ بتجهيز ورشتك':'لا توجد إصلاحات مفتوحة' }}</h3>
                <p>{{ $activeWorkshops->isEmpty()?'فعّل الورشة وحدد فريقها أولًا، ثم ابدأ باستلام المعدات. ستظهر هنا أوامر الإصلاح ومتابعتها.':'استلم معدة وأنشئ أمر إصلاح لبدء المتابعة. الأوامر المسلّمة محفوظة في سجل أوامر الإصلاح.' }}</p>
                @if($activeWorkshops->isNotEmpty()&&$access->branches(auth()->id(),'intake_manage'))
                    <flux:button icon="plus" :href="route('workshops.intakes.create')" wire:navigate>استلام أول معدة</flux:button>
                @elseif($activeWorkshops->isEmpty())
                    <ol class="workshop-start-checklist" aria-label="خطوات تجهيز الورشة">
                        <li><span>1</span><div><strong>بيانات الورشة</strong><small>الفرع والتخصص والمشرف ومواعيد العمل</small></div></li>
                        <li><span>2</span><div><strong>فريق الإصلاح</strong><small>أعضاء نشطون وصلاحيات تنفيذ محددة</small></div></li>
                        <li><span>3</span><div><strong>استلام المعدة</strong><small>العميل والعطل والحالة والملحقات</small></div></li>
                    </ol>
                    @if(!$access->branches(auth()->id(),'manage'))<p class="workshop-start-permission">تجهيز الورشة يتطلب مسؤولًا مفوضًا بإعداد الورش في هذا الفرع.</p>@endif
                @else
                    <a class="workshop-text-link" href="{{ route('workshops.jobs') }}" wire:navigate>عرض سجل الإصلاحات<flux:icon.arrow-left class="size-4 rtl:rotate-0 ltr:rotate-180"/></a>
                @endif
            </div>
        @endif
        <div class="workshop-workbench-footer"><flux:icon.shield-check class="size-4 shrink-0" aria-hidden="true"/><p>الاختبار والتسليم والفاتورة مرتبطة بأمر الإصلاح نفسه، مع حفظ سجل كل إجراء.</p></div>
    </section>
    <aside class="workshop-overview-side" aria-label="جاهزية الورش">
        <section class="workshop-readiness">
            <div class="workshop-side-heading"><h2>الورش</h2><span>{{ $workshops->count() }} مسجلة</span></div>
            <div @class(['workshop-readiness-state','is-ready'=>$activeWorkshops->isNotEmpty()])><span class="workshop-state-dot" aria-hidden="true"></span>{{ $activeWorkshops->isEmpty()?'لا توجد ورشة نشطة':$activeWorkshops->count().' ورش نشطة' }}</div>
            <div class="workshop-site-list">
                @forelse($displayedWorkshops as $workshop)
                    <div class="workshop-site" wire:key="overview-workshop-{{ $workshop->id }}">
                        <div><strong>{{ $workshop->name }}</strong><span title="{{ $workshop->specialty }}">{{ \Illuminate\Support\Str::limit($workshop->specialty,85) }}</span></div>
                        @if($workshop->active)
                            <span class="workshop-site-load"><b>{{ $workshop->open_jobs }}</b> / {{ $workshop->capacity }}<small>إصلاحات / طاقة</small></span>
                        @else<span class="workshop-inactive-label">غير نشطة</span>@endif
                    </div>
                @empty<p class="workshop-side-empty">لم تسجّل ورش ضمن نطاقك بعد.</p>@endforelse
            </div>
            @if($access->branches(auth()->id(),'manage'))<a class="workshop-text-link workshop-settings-link" href="{{ route('workshops.settings') }}" wire:navigate>كل الورش والفنيين<flux:icon.arrow-left class="size-4 rtl:rotate-0 ltr:rotate-180"/></a>@endif
        </section>
        <p class="workshop-scope-note"><flux:icon.lock-closed class="size-3.5 shrink-0" aria-hidden="true"/>تعرض البيانات حسب صلاحياتك ونطاق فرعك.</p>
    </aside>
</div>
