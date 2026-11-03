<x-ui.panel>
    <h2 class="mb-4 font-bold text-blue-900">المعالم ودليل القبول</h2>
    @if($canManage)
        <form wire:submit="addMilestone" class="mb-5 grid gap-3 md:grid-cols-4">
            <flux:input label="المعلم" wire:model="milestone.title"/>
            <flux:input label="التاريخ المستهدف" type="date" wire:model="milestone.target_date"/>
            <flux:select label="المسؤول" wire:model="milestone.responsible_id"><option value="">اختر المسؤول</option>@foreach($users as $u)@if($u['id']==$project->manager_id||in_array($u['id'],$form['members']))<option value="{{ $u['id'] }}">{{ $u['name'] }}</option>@endif @endforeach</flux:select>
            <div class="space-y-3"><flux:checkbox label="مطلوب للإكمال" wire:model="milestone.required"/><flux:button type="submit" wire:loading.attr="disabled">إضافة معلم</flux:button></div>
        </form>
        <flux:textarea label="دليل إكمال المعلم أو سبب الإعفاء" wire:model="evidence"/>
    @endif
    <div class="mt-4 divide-y">@forelse($milestones as $m)<div class="flex flex-wrap items-center justify-between gap-3 py-3"><div><p class="font-semibold">{{ $m->title }} @if($m->required)<span class="text-xs text-amber-700">مطلوب</span>@endif</p><p class="text-sm text-slate-500">{{ $m->target_date }} · {{ ['pending'=>'بانتظار التنفيذ','completed'=>'مكتمل','waived'=>'معفى بسبب موثق'][$m->status] }}</p>@if($m->evidence)<p class="mt-1 text-sm">{{ $m->evidence }}</p>@endif</div>@if($canManage&&$m->status==='pending')<div class="flex gap-2"><flux:button wire:click="completeMilestone({{ $m->id }},{{ $m->version }},'completed')">إكمال المعلم</flux:button>@if(app(\App\Modules\Projects\Application\ProjectAccess::class)->allows(auth()->id(),$project,'project_close'))<flux:button wire:click="completeMilestone({{ $m->id }},{{ $m->version }},'waived')">إعفاء مسبب</flux:button>@endif</div>@endif</div>@empty<p class="py-4 text-slate-500">لم تسجل معالم بعد.</p>@endforelse</div>
</x-ui.panel>
@if($canManage)
<x-ui.panel><h2 class="mb-4 font-bold text-blue-900">تبعيات المهام</h2><form wire:submit="addDependency" class="grid gap-3 md:grid-cols-3"><flux:select label="المهمة التابعة" wire:model="dependencyTask"><option value="">اختر المهمة</option>@foreach($tasks as $t)<option value="{{ $t->id }}">{{ $t->title }}</option>@endforeach</flux:select><flux:select label="تعتمد على اكتمال" wire:model="dependencyParent"><option value="">اختر المهمة السابقة</option>@foreach($tasks as $t)<option value="{{ $t->id }}">{{ $t->title }}</option>@endforeach</flux:select><flux:button type="submit">حفظ التبعية</flux:button></form></x-ui.panel>
@endif
<x-ui.panel><h2 class="mb-4 font-bold text-blue-900">تعليقات التنفيذ</h2>@if(!in_array($project->status,['closed','cancelled','completed']))<flux:textarea label="تعليق على المهمة" wire:model="taskComment"/><div class="mt-3 flex flex-wrap gap-2">@foreach($tasks as $t)<flux:button size="sm" wire:click="comment({{ $t->id }})">تعليق: {{ $t->title }}</flux:button>@endforeach</div>@endif @foreach($comments as $c)<p class="mt-3 border-t pt-3 text-sm">مهمة #{{ $c->task_id }} · {{ $c->body }} · {{ \Carbon\CarbonImmutable::parse($c->created_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }} · توقيت القاهرة</p>@endforeach</x-ui.panel>
