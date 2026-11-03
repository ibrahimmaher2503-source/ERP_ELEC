<div class="workshop-stage-counts" aria-label="توزيع أوامر الإصلاح حسب المرحلة">
    @foreach(['inspection'=>'الفحص والتشخيص','waiting_approval'=>'العرض والموافقة','approved'=>'جاهز للبدء','repair'=>'قيد الإصلاح','waiting_parts'=>'انتظار قطع','testing'=>'الاختبار والجودة','verified'=>'جاهز للتسليم'] as $state=>$label)
        <a href="{{ route('workshops.jobs',['statusFilter'=>$state]) }}" wire:navigate><span>{{ $label }}</span><b>{{ $stats[$state]??0 }}</b></a>
    @endforeach
</div>
