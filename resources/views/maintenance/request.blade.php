<div class="space-y-5" data-erp-archetype="{{ $editing ? 'form' : 'details' }}" x-data x-on:maintenance-invalid.window="$nextTick(() => { $el.querySelectorAll('[aria-invalid=true]').forEach(input => { if (input.closest('details')) input.closest('details').open = true }); $el.querySelector('[aria-invalid=true]')?.focus() })">
    <x-page.heading :title="$r ? 'طلب صيانة '.$r->number : ($standalone ? 'أمر عمل مستقل' : 'طلب صيانة جديد')" :description="$standalone ? 'إنشاء أمر عمل مباشر ضمن صلاحيتك، ثم تجهيز التكليف والزيارة' : 'ابدأ بالبلاغ والعميل، ثم قدم الطلب للمراجعة'" :breadcrumbs="['الصيانة الميدانية', $standalone ? 'أوامر العمل' : 'الطلبات']">
        <x-slot:actions><flux:button :href="route($standalone ? 'maintenance.work-orders' : 'maintenance.requests')" wire:navigate>{{ $standalone ? 'أوامر العمل' : 'طلبات الصيانة' }}</flux:button></x-slot:actions>
    </x-page.heading>
    @if($r)<div class="flex flex-wrap items-center gap-3">@include('maintenance.status', ['status'=>$r->status])<span class="text-sm text-slate-500">الإصدار {{ $version }}</span></div>@endif
    @if(session('maintenance_notice'))<p role="status" class="erp-feedback--success rounded border p-3">{{ session('maintenance_notice') }}</p>@endif
    <x-ui.validation-summary />
    @if($linkedWorkOrder)
        <x-ui.panel><div class="flex flex-wrap items-center justify-between gap-3"><p>تم إنشاء أمر العمل {{ $linkedWorkOrder->number }} لهذا الطلب. التنفيذ يُتابع من أمر العمل.</p><flux:button variant="primary" :href="route('maintenance.work-orders.show', $linkedWorkOrder->id)" wire:navigate>متابعة أمر العمل</flux:button></div></x-ui.panel>
    @endif
    @if($editing)
        <form wire:submit="save" class="space-y-5">
            <x-erp.section-card title="البلاغ والعميل" icon="wrench-screwdriver">
                <p class="mb-4 text-sm text-slate-500">الفرع والعميل والمسؤول ووصف العطل بيانات أساسية. يُحفظ طلب الصيانة كمسودة قبل تقديمه.</p>
                <div class="grid gap-4 md:grid-cols-3">
                    <flux:select label="الفرع" wire:model.live="form.branch_id" :disabled="$r !== null"><option value="">اختر الفرع</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
                    <flux:select label="الأولوية" wire:model="form.priority">@foreach(['low'=>'منخفضة','normal'=>'عادية','high'=>'عالية','urgent'=>'عاجلة'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</flux:select>
                    <flux:select label="المسؤول عن متابعة البلاغ" wire:model="form.responsible_id" :disabled="!$form['branch_id']"><option value="">{{ $form['branch_id'] ? 'اختر المسؤول' : 'اختر الفرع أولًا' }}</option>@foreach($users as $u)<option value="{{ $u['id'] }}">{{ $u['name'] }}</option>@endforeach</flux:select>
                </div>
                <div class="mt-4 grid gap-4 md:grid-cols-2"><flux:select label="العميل" data-testid="maintenance-request-customer" wire:model.live="form.customer_id" search-model="customerSearch"><option value="">اختر العميل</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->code }} · {{ $c->name }}</option>@endforeach</flux:select></div>
                <flux:textarea class="mt-4" label="وصف العطل" wire:model="form.description" rows="3" placeholder="ما المشكلة؟ وأين تظهر؟"/>
            </x-erp.section-card>
            <x-erp.section-card title="الموقع والتواصل" icon="map-pin">
                <p class="mb-4 text-sm text-slate-500">اختر العميل لعرض مواقعه وجهات الاتصال. حدد موقع الخدمة إن كان معروفًا.</p>
                <div class="grid gap-4 md:grid-cols-3">
                    <flux:select label="موقع العميل" wire:model="form.site_id" :disabled="!$form['customer_id']"><option value="">غير محدد</option>@foreach($context['sites'] as $site)<option value="{{ $site['id'] }}">{{ $site['label'] }}</option>@endforeach</flux:select>
                    <flux:select label="جهة الاتصال" wire:model="form.contact_id" :disabled="!$form['customer_id']"><option value="">غير محددة</option>@foreach($context['contacts'] as $contact)<option value="{{ $contact['id'] }}">{{ $contact['name'] }}</option>@endforeach</flux:select>
                    <flux:input type="date" label="تاريخ الزيارة المفضل (اختياري)" wire:model="form.preferred_date"/>
                </div>
            </x-erp.section-card>
            <x-ui.panel>
                <details @if($form['product_id'] || $form['project_id'] || $errors->hasAny(['form.product_id','form.project_id','form.project_task_id','form.project_milestone_id','form.reported_at'])) open @endif>
                    <summary class="cursor-pointer font-semibold">تفاصيل إضافية: المعدة والمشروع ووقت البلاغ</summary>
                    <p class="mt-2 text-sm text-slate-500">اربط المعدة أو المشروع عند الحاجة. الحقول الاختيارية يمكن تركها فارغة.</p>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">

                        <flux:select label="المعدة أو الصنف (اختياري)" wire:model="form.product_id" search-model="productSearch"><option value="">غير محدد</option>@foreach($products as $p)<option value="{{ $p['id'] }}">{{ $p['sku'] }} · {{ $p['name'] }}</option>@endforeach</flux:select>
                        <flux:select label="المشروع المرتبط (اختياري)" wire:model.live="form.project_id" :disabled="!$form['branch_id'] || !$form['customer_id']"><option value="">بدون مشروع</option>@foreach($projects as $project)<option value="{{ $project['id'] }}">{{ $project['code'] }} · {{ $project['name'] }}</option>@endforeach</flux:select>
                        <flux:select label="مهمة المشروع (اختياري)" wire:model="form.project_task_id" :disabled="!$form['project_id']"><option value="">بدون مهمة</option>@foreach($planning['tasks'] as $task)<option value="{{ $task['id'] }}">{{ $task['title'] }}</option>@endforeach</flux:select>
                        <flux:select label="معلم المشروع (اختياري)" wire:model="form.project_milestone_id" :disabled="!$form['project_id']"><option value="">بدون معلم</option>@foreach($planning['milestones'] as $milestone)<option value="{{ $milestone['id'] }}">{{ $milestone['title'] }}</option>@endforeach</flux:select>
                        <flux:input type="datetime-local" label="وقت البلاغ بتوقيت القاهرة" wire:model="form.reported_at"/>
                    </div>
                </details>
            </x-ui.panel>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" :variant="$r ? 'outline' : 'primary'" wire:loading.attr="disabled">{{ $standalone ? 'إنشاء أمر العمل' : ($r ? 'حفظ المسودة' : 'حفظ الطلب') }}</flux:button>
                @if($r && $r->status === 'draft' && $access->allows(auth()->id(), $r->branch_id, 'request_edit'))<flux:button type="button" variant="primary" wire:click="save(true)" wire:loading.attr="disabled">حفظ وتقديم للمراجعة</flux:button>@endif
                <p wire:dirty role="status" class="text-sm text-amber-800">توجد تغييرات غير محفوظة.</p>
            </div>
        </form>
    @else
        <x-ui.panel>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-sm text-slate-500">العميل</dt><dd class="mt-1">{{ $r->customer_name }}</dd></div>
                <div><dt class="text-sm text-slate-500">الأولوية</dt><dd class="mt-1">{{ ['low'=>'منخفضة','normal'=>'عادية','high'=>'عالية','urgent'=>'عاجلة'][$r->priority] }}</dd></div>
                <div><dt class="text-sm text-slate-500">المسؤول</dt><dd class="mt-1">{{ $people[$r->responsible_id] ?? 'مسؤول سابق، مرجع '.$r->responsible_id }}</dd></div>
                <div><dt class="text-sm text-slate-500">الموقع</dt><dd class="mt-1">{{ collect($context['sites'])->firstWhere('id', $r->site_id)['label'] ?? 'غير محدد أو غير متاح' }}</dd></div>
                <div><dt class="text-sm text-slate-500">جهة الاتصال</dt><dd class="mt-1">{{ collect($context['contacts'])->firstWhere('id', $r->contact_id)['name'] ?? 'غير محددة أو غير متاحة' }}</dd></div>
                <div><dt class="text-sm text-slate-500">المعدة أو الصنف</dt><dd class="mt-1">{{ collect($products)->firstWhere('id', $r->product_id)['name'] ?? 'غير محدد أو غير متاح' }}</dd></div>
                <div><dt class="text-sm text-slate-500">المشروع</dt><dd class="mt-1">{{ collect($projects)->firstWhere('id', $r->project_id)['name'] ?? ($r->project_id ? 'مرجع مشروع '.$r->project_id : 'بدون مشروع') }}</dd></div>
                <div><dt class="text-sm text-slate-500">وقت البلاغ بتوقيت القاهرة</dt><dd class="mt-1" dir="ltr">{{ \Carbon\CarbonImmutable::parse($r->reported_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</dd></div>
                <div><dt class="text-sm text-slate-500">الزيارة المفضلة</dt><dd class="mt-1">{{ $r->preferred_date ?? 'غير محددة' }}</dd></div>
            </dl>
            <flux:heading class="mt-5">وصف العطل</flux:heading><p class="mt-2 whitespace-pre-wrap">{{ $r->description }}</p>
        </x-ui.panel>
    @endif
    @if($r)
        <x-ui.panel>
            <flux:heading size="lg">متابعة الطلب</flux:heading>
            <p class="mt-2 text-sm text-slate-500">{{ ['draft'=>'احفظ بيانات البلاغ وقدمه للمراجعة. قبول الطلب وإنشاء أمر العمل خطوتان مستقلتان.','submitted'=>'الطلب لدى مسؤول الموافقة، وبعد قبوله يمكن إنشاء أمر العمل.','accepted'=>'الطلب مقبول. أنشئ أمر العمل لتجهيز التنفيذ.','work_order_created'=>'انتقل لأمر العمل لمتابعة التنفيذ.','rejected'=>'يمكن للمخول إعادة فتح الطلب مع تسجيل السبب.','cancelled'=>'الطلب ملغي مع الاحتفاظ ببياناته.'][$r->status] }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                @if($r->status === 'submitted' && $access->allows(auth()->id(), $r->branch_id, 'request_approve'))<flux:button variant="primary" wire:key="maintenance-state-accept" wire:click="changeState('accept')" wire:loading.attr="disabled">قبول الطلب</flux:button>@endif
                @if($r->status === 'accepted' && $access->allows(auth()->id(), $r->branch_id, 'workorder_manage'))<flux:button variant="primary" wire:key="maintenance-create-work-order" wire:click="createWorkOrder" wire:loading.attr="disabled">إنشاء أمر العمل</flux:button>@endif
            </div>
            @if((in_array($r->status, ['draft','submitted','accepted']) && $access->allows(auth()->id(), $r->branch_id, 'request_edit')) || ($r->status === 'submitted' && $access->allows(auth()->id(), $r->branch_id, 'request_approve')) || (in_array($r->status, ['rejected','cancelled']) && $access->allows(auth()->id(), $r->branch_id, 'reopen')))
                <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold">الرفض أو الإلغاء أو إعادة الفتح</summary><div class="mt-3 space-y-3"><flux:input label="سبب القرار (مطلوب لهذه الإجراءات)" wire:model="reason"/><div class="flex flex-wrap gap-2">
                    @if($r->status === 'submitted' && $access->allows(auth()->id(), $r->branch_id, 'request_approve'))<flux:button wire:key="maintenance-state-reject" wire:click="changeState('reject')" wire:loading.attr="disabled">رفض الطلب</flux:button>@endif
                    @if(in_array($r->status, ['draft','submitted','accepted']) && $access->allows(auth()->id(), $r->branch_id, 'request_edit'))<flux:button variant="danger" wire:key="maintenance-state-cancel" wire:click="changeState('cancel')" wire:confirm="هل تريد إلغاء طلب الصيانة مع الاحتفاظ بسجله؟" wire:loading.attr="disabled">إلغاء الطلب</flux:button>@endif
                    @if(in_array($r->status, ['rejected','cancelled']) && $access->allows(auth()->id(), $r->branch_id, 'reopen'))<flux:button wire:key="maintenance-state-reopen" wire:click="changeState('reopen')" wire:loading.attr="disabled">إعادة فتح الطلب</flux:button>@endif
                </div></div></details>
            @endif
        </x-ui.panel>
        <x-ui.panel>
            <flux:heading size="lg">مرفقات البلاغ</flux:heading>
            @if($access->allows(auth()->id(), $r->branch_id, 'document_view'))
                @forelse($documents as $doc)<p class="mt-3"><a class="text-blue-700 underline" href="{{ route('maintenance.documents', $doc->id) }}">{{ $doc->original_name }}</a></p>@empty<p class="mt-2 text-sm text-slate-500">لم تُرفق صور أو مستندات لهذا البلاغ.</p>@endforelse
            @else<p class="mt-2 text-sm text-slate-500">عرض ملفات البلاغ يحتاج صلاحية المرفقات.</p>@endif
            @if($access->allows(auth()->id(), $r->branch_id, 'document_manage') && in_array($r->status, ['draft','submitted','accepted']))<form wire:submit="saveAttachment" class="mt-4 flex flex-wrap items-end gap-3"><flux:input type="file" label="صورة أو PDF حتى 10 ميجابايت" wire:model="attachment"/><flux:button type="submit" wire:loading.attr="disabled">إرفاق الملف</flux:button></form>@endif
        </x-ui.panel>
    @endif
</div>
