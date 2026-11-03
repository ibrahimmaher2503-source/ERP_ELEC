<div data-erp-archetype="details" class="workshop-ui space-y-5">
    <x-page.heading :title="'أمر إصلاح '.$w->number" :description="$intake->equipment_description" :breadcrumbs="['الورش','أوامر الإصلاح']">
        <x-slot:actions>
            <flux:button :href="route('workshops.jobs')" wire:navigate icon="arrow-right">أوامر الإصلاح</flux:button>
            <flux:button :href="route('workshops.intakes.show',$w->intake_id)" wire:navigate>ملف استلام المعدة</flux:button>
        </x-slot:actions>
    </x-page.heading>
    @include('workshops.feedback')
    <section class="workshop-summary" aria-label="ملخص أمر الإصلاح">
        <div><span>العميل / المالك</span><strong>{{ $w->customer_name }}</strong></div>
        <div><span>هوية المعدة</span><strong>{{ $intake->serial_number ?: 'رقم مسلسل غير مسجل' }}</strong><small>{{ $intake->model }}</small></div>
        <div><span>الورشة والفني</span><strong>{{ $workshops->firstWhere('id',$w->workshop_id)?->name ?? 'الورشة غير نشطة' }}</strong><small>{{ collect($users)->firstWhere('id',$w->technician_id)['name'] ?? 'لم يعيّن فني بعد' }}</small></div>
        <div><span>حالة الإصلاح</span>@include('workshops.status',['status'=>$w->status])<small>{{ ['billable'=>'إصلاح مدفوع','partial'=>'مدفوع جزئياً','warranty'=>'إصلاح ضمان','nonbillable'=>'داخلي / مجاملة','contract'=>'ضمن عقد خدمة'][$w->billing_mode] ?? $w->billing_mode }}</small></div>
    </section>
    @php
        $stage = match($w->status) {
            'inspection' => 2, 'waiting_approval', 'rejected' => 3, 'approved' => 4,
            'repair', 'waiting_parts', 'on_hold' => 5, 'testing' => 6, 'verified' => 7, default => 8,
        };
        $next = match($w->status) {
            'inspection' => !$w->technician_id ? ['details','عيّن الفني المسؤول','اختر فنيًا نشطًا من أعضاء الورشة، ثم يسجّل الفني التشخيص وخطة الفحص.'] : (!$w->diagnosis ? ['details','في انتظار تشخيص الفني','الفني المعيّن يسجّل سبب العطل والأعمال المطلوبة والاختبارات الإلزامية.'] : ['estimate','جهّز أساس الإصلاح','أنشئ العرض للاعتماد وقبول العميل، أو وثّق أساس الضمان أو العقد.']),
            'waiting_approval' => ['estimate','استكمل الموافقات','اعتماد السعر ثم إصدار العرض وتوثيق قرار العميل. الإصلاح التعاقدي وغير المدفوع لهما اعتماد مستقل.'],
            'approved' => ['work','الإصلاح معتمد وجاهز للبدء','الفني المعيّن يبدأ الإصلاح من سجل العمل. اطلب قطع الغيار عند الحاجة من مرحلة القطع.'],
            'repair' => ['work','سجّل التنفيذ الفعلي','ثبّت العمل والوقت، ثم سجّل القطع المستخدمة وأعد غير المستخدم قبل التحقق الفني.'],
            'waiting_parts' => ['parts','الإصلاح ينتظر قطع الغيار','استكمل الحجز والصرف من المستودع المسموح، ثم استأنف العمل.'],
            'on_hold' => ['work','الإصلاح موقوف مؤقتًا','راجع سبب الإيقاف في السجل ثم وثّق الاستئناف.'],
            'testing' => ['testing','الاختبار ثم تحقق مستقل','الفني يسجّل النتائج، والمشرف المستقل يتحقق بعد نجاح الاختبارات وتسوية القطع.'],
            'verified' => ['delivery','المعدة جاهزة للتسليم','استكمل إحالة الفوترة للإصلاح المدفوع، ثم سجّل المستلم والحالة والملحقات والإقرار.'],
            'rejected' => ['delivery','إعادة المعدة دون إصلاح','راجع قرار العميل والحالة الفنية، ثم وثّق حالة المعدة وسبب الإعادة في محضر التسليم.'],
            'returned_unrepaired' => ['history','أعيدت المعدة دون إصلاح','محضر التسليم وسجل العهدة محفوظان.'],
            default => ['billing','تم تسليم المعدة','راجع الفاتورة والتحصيل والمستندات المرتبطة بهذا الإصلاح.'],
        };
    @endphp
    <nav class="workshop-steps" aria-label="تفاصيل أمر الإصلاح">
        <a href="{{ route('workshops.intakes.show',$w->intake_id) }}" wire:navigate><span>01</span><strong>استلام المعدة</strong><small>العهدة والحالة</small></a>
        @foreach(['details'=>['02','الفحص والتشخيص','الفني وخطة الإصلاح'],'estimate'=>['03','العرض والموافقة','السعر وقرار العميل'],'parts'=>['04','قطع الغيار','صرف واستخدام وإرجاع'],'work'=>['05','تنفيذ الإصلاح','الأعمال وساعات الفني'],'testing'=>['06','الاختبار والجودة','النتائج والتحقق'],'delivery'=>['07','تسليم المعدة','المحضر والإقرار'],'billing'=>['08','الفاتورة والتحصيل','الذمة والمستندات']] as $key=>$step)
            <a href="{{ route('workshops.jobs.show',['job'=>$w->id,'tab'=>$key]) }}" wire:navigate @class(['is-active'=>$tab===$key]) @if($tab===$key) aria-current="page" @endif><span>{{ $step[0] }}</span><strong>{{ $step[1] }}</strong><small>{{ $step[2] }}</small></a>
        @endforeach
    </nav>
    <div class="workshop-next" role="region" aria-label="الخطوة التالية">
        <div><p class="workshop-eyebrow">الخطوة التالية · المرحلة {{ $stage }}</p><h2>{{ $next[1] }}</h2><p>{{ $w->pending_transfer_id ? 'المعدة قيد النقل. يلزم استلام الورشة الوجهة قبل مواصلة التنفيذ.' : $next[2] }}</p></div>
        @if($tab!==($w->pending_transfer_id?'details':$next[0]))<flux:button variant="primary" :href="route('workshops.jobs.show',['job'=>$w->id,'tab'=>$w->pending_transfer_id?'details':$next[0]])" wire:navigate>انتقل للإجراء</flux:button>@endif
    </div>
    <div class="flex flex-wrap items-center gap-2" aria-label="معلومات إضافية">
        @if($canCosts)<flux:button size="sm" :variant="$tab==='costs'?'primary':'outline'" :href="route('workshops.jobs.show',['job'=>$w->id,'tab'=>'costs'])" wire:navigate>التكلفة الفعلية</flux:button>@endif
        <flux:button size="sm" :variant="$tab==='history'?'primary':'outline'" :href="route('workshops.jobs.show',['job'=>$w->id,'tab'=>'history'])" wire:navigate>سجل العمليات</flux:button>
        <span class="ms-auto text-xs text-slate-500">الإصدار {{ $version }} · جميع الأوقات بتوقيت القاهرة</span>
    </div>
    <div class="space-y-5" wire:loading.class="opacity-60" wire:target="assign,diagnose,proposeEstimate,decideEstimate,issueQuote,acceptQuote,authorizeContract,authorizeNonbillable,state,recordWork,recordTest,verify,deliver,requestInvoice,requestPart,reservePart,issuePart,usePart,returnPart">
        @if($tab==='estimate')
            @include('workshops.offers')
        @elseif(in_array($tab,['delivery','billing']))
            @include('workshops.job-delivery')
        @else
            @include('workshops.job-'.$tab)
        @endif
    </div>
</div>
