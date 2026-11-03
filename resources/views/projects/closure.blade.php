<div data-erp-archetype="details" class="space-y-5">
    <x-page.heading title="التسليم وإغلاق المشروع" :description="$project->name" :breadcrumbs="['المشروعات']"><x-slot:actions><flux:button :href="route('projects.show',$project->id)" wire:navigate>عرض المشروع</flux:button></x-slot:actions></x-page.heading>
    <x-ui.validation-summary />
    <x-ui.panel><h2 class="mb-4 font-bold text-blue-900">شروط الإكمال</h2><dl class="divide-y">@foreach(['tasks'=>'المهام المطلوبة','milestones'=>'المعالم المطلوبة','documents'=>'اعتماد المستندات المطلوبة','handover'=>'سجل تسليم تشغيلي','changes'=>'حسم طلبات التغيير'] as $key=>$label)<div class="flex justify-between gap-4 py-3"><dt>{{ $label }}</dt><dd class="{{ $checks[$key]?'text-teal-700':'text-amber-700' }}">{{ $checks[$key]?'مستوفى':'غير مستوفى' }}</dd></div>@endforeach</dl></x-ui.panel>
    <x-ui.panel><h2 class="mb-4 font-bold text-blue-900">المصالحة والالتزامات</h2>
        @if($summary)
            <dl class="divide-y">@foreach($summary['closure_blockers'] as $label=>$blocked)<div class="flex justify-between gap-4 py-3"><dt>{{ $label }}</dt><dd class="{{ $blocked?'text-amber-700':'text-teal-700' }}">{{ $blocked?'يمنع الإغلاق':'مستوفى' }}</dd></div>@endforeach</dl>
            <p class="mt-4">المتبقي للموردين: {{ $summary['subledger']['ap_due'] }} EGP · المستحق على العملاء: {{ $summary['subledger']['ar_due'] }} EGP</p>
            <p class="mt-2 text-sm text-slate-600">تظل الذمم المفتوحة لدى M12 بعد الإغلاق، ولا ينشئ الإغلاق تحصيلاً أو سداداً.</p>
        @else<p class="text-amber-800">يلزم تصريح عرض الملخص المالي وعقود المصادر للتحقق من المصالحة.</p>@endif
    </x-ui.panel>
    <x-ui.panel><h2 class="mb-4 font-bold text-blue-900">سجلات التسليم</h2>@foreach($handovers as $h)<p class="border-b py-3">{{ $h->evidence }} · {{ \Carbon\CarbonImmutable::parse($h->recorded_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }} · توقيت القاهرة · #{{ $h->recorded_by }}</p>@endforeach
        @if($canClose && in_array($project->status,['active','in_progress','on_hold']))<form wire:submit="handover" class="mt-4 space-y-3"><flux:textarea label="مرجع ودليل التسليم التشغيلي" wire:model="evidence"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">تسجيل التسليم</flux:button></form>@endif
        @if($canClose && $project->status==='completed')<form wire:submit="close" class="mt-4 space-y-3"><flux:textarea label="سبب الإغلاق وإقرار مراجعة الالتزامات الظاهرة" wire:model="reason"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">إغلاق المشروع</flux:button></form>@endif
        @if($project->status==='closed')<p class="mt-4 font-bold text-teal-700">المشروع مغلق للقراءة. إعادة الفتح من دورة الحياة بتصريح مستقل وسبب.</p>@endif
    </x-ui.panel>
</div>
