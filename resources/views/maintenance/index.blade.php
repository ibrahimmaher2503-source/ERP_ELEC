<div class="space-y-5" data-erp-archetype="{{ $screen === 'dashboard' ? 'dashboard' : ($screen === 'reports' ? 'report' : 'list') }}" x-data x-on:maintenance-invalid.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())">
    <x-page.heading :title="['dashboard'=>'متابعة الصيانة','requests'=>'طلبات الصيانة','work-orders'=>'أوامر العمل','assignments'=>'تكليفات الفنيين','schedule'=>'جدول الزيارات','checklists'=>'قوالب الفحص','reports'=>'تقارير الخدمة الصادرة'][$screen]" :description="['dashboard'=>'الطلبات التي تنتظر المراجعة والزيارات المخططة وحالة التنفيذ','requests'=>'سجل البلاغ، ثم قدمه للمراجعة قبل إنشاء أمر العمل','work-orders'=>'متابعة التكليف والزيارات والتنفيذ حتى الإغلاق','assignments'=>'التكليفات النشطة ومواعيد الفنيين بتوقيت القاهرة','schedule'=>'مواعيد الزيارات ونتائجها بتوقيت القاهرة','checklists'=>'بنود الفحص التي تُحفظ مع كل زيارة عند جدولتها','reports'=>'تقارير PDF صدرت بالفعل عند اعتماد التحقق الفني'][$screen]" :breadcrumbs="['الرئيسية','الصيانة الميدانية']">
        <x-slot:actions>
            @if(in_array($screen, ['dashboard','requests','work-orders']) && $createBranches)
                <flux:button variant="primary" icon="plus" :href="route('maintenance.requests.create')" wire:navigate>طلب صيانة جديد</flux:button>
            @elseif($screen === 'checklists' && $templateBranches)
                <flux:button variant="primary" icon="plus" wire:click="cancelTemplate" href="#checklist-editor">قالب فحص جديد</flux:button>
            @endif
        </x-slot:actions>
        <x-slot:secondaryActions>
            @if($access->branches(auth()->id(), 'standalone_create'))
                <flux:button :href="route('maintenance.work-orders.create')" wire:navigate>إنشاء أمر عمل مباشر</flux:button>
            @endif
        </x-slot:secondaryActions>
    </x-page.heading>
    <nav class="flex flex-wrap gap-2" aria-label="الصيانة">
        @foreach(['dashboard'=>'المتابعة','requests'=>'الطلبات','work-orders'=>'أوامر العمل','assignments'=>'التكليفات','schedule'=>'الزيارات','checklists'=>'قوالب الفحص','reports'=>'التقارير الصادرة'] as $key=>$label)
            @if($key !== 'reports' || $access->branches(auth()->id(), 'reports_view'))
                <flux:button size="sm" :variant="$screen === $key ? 'primary' : 'outline'" :href="route('maintenance.'.$key)" :aria-current="$screen === $key ? 'page' : null" wire:navigate>{{ $label }}</flux:button>
            @endif
        @endforeach
    </nav>
    @if(session('maintenance_notice'))<p role="status" class="erp-feedback--success rounded border p-3">{{ session('maintenance_notice') }}</p>@endif
    <x-ui.validation-summary />

    @if($screen === 'dashboard')
        <x-ui.panel>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div><flux:heading size="lg">العمل الذي يحتاج متابعة</flux:heading><p class="mt-1 text-sm text-slate-500">أعداد فعلية ضمن نطاقك. الزيارة المخططة لا تثبت تنفيذ عمل.</p></div>
                <div class="flex flex-wrap gap-2">
                    <flux:button :href="route('maintenance.requests', ['status'=>'submitted'])" wire:navigate>طلبات للمراجعة: {{ $submittedRequests }}</flux:button>
                    <flux:button :href="route('maintenance.schedule')" wire:navigate>زيارات اليوم المخططة: {{ $todayVisits }}</flux:button>
                    <flux:button wire:click="$set('status', 'work_completed')">بانتظار التحقق: {{ $stats['work_completed'] ?? 0 }}</flux:button>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4" aria-label="تصفية أوامر العمل حسب الحالة">
                <flux:button size="sm" :variant="$status === '' ? 'primary' : 'outline'" wire:click="$set('status', '')">الكل {{ array_sum($stats) }}</flux:button>
                @foreach(['draft'=>'مسودة','scheduled'=>'فني مكلف','assigned'=>'زيارة مجدولة','in_progress'=>'قيد التنفيذ','work_completed'=>'تم التنفيذ','verified'=>'تم التحقق','on_hold'=>'متوقف','closed'=>'مغلق','cancelled'=>'ملغي'] as $key=>$label)
                    @if(($stats[$key] ?? 0) > 0)<flux:button size="sm" :variant="$status === $key ? 'primary' : 'outline'" wire:click="$set('status', '{{ $key }}')">{{ $label }} {{ $stats[$key] }}</flux:button>@endif
                @endforeach
            </div>
        </x-ui.panel>
    @endif

    @if(in_array($screen, ['dashboard','requests','work-orders','reports']))
        <x-ui.panel>
            <x-ui.filters id="maintenance-filters" target="search,status" :reset="['search' => '', 'status' => '']">
                <x-slot:search><flux:input label="البحث في السجلات" wire:model.live.debounce.300ms="search" placeholder="رقم السجل أو العميل أو وصف العطل"/></x-slot:search>
                <flux:select label="الحالة الحالية" wire:model.live="status"><option value="">جميع الحالات</option>
                    @foreach($screen === 'requests' ? ['draft'=>'مسودة','submitted'=>'بانتظار المراجعة','accepted'=>'مقبول لإنشاء أمر عمل','work_order_created'=>'تم إنشاء أمر عمل','rejected'=>'مرفوض','cancelled'=>'ملغي'] : ['draft'=>'مسودة','scheduled'=>'فني مكلف','assigned'=>'زيارة مجدولة','in_progress'=>'قيد التنفيذ','work_completed'=>'تم تنفيذ العمل','verified'=>'تم التحقق الفني','closed'=>'مغلق','on_hold'=>'متوقف مؤقتًا','cancelled'=>'ملغي'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </flux:select>
            </x-ui.filters>
            <p class="my-4 text-sm text-slate-500" role="status">{{ $rows->total() }} {{ $screen === 'reports' ? 'تقرير خدمة صادر' : 'سجل ضمن نطاقك' }}</p>
            @if($rows->isEmpty())
                <x-ui.empty-state :title="$screen === 'reports' ? 'لا توجد تقارير خدمة صادرة' : 'لا توجد سجلات مطابقة'" :description="$screen === 'reports' ? 'يصدر تقرير الخدمة بعد اعتماد التحقق الفني. افتح أمر العمل لمراجعة متطلبات الإتمام.' : 'غيّر البحث أو الحالة، أو سجل بلاغًا جديدًا ضمن الفروع المصرح لك بها.'" icon="wrench-screwdriver">
                    <flux:button :href="route('maintenance.work-orders')" wire:navigate>عرض أوامر العمل</flux:button>
                </x-ui.empty-state>
            @else
                <x-ui.responsive-table :label="$screen === 'reports' ? 'تقارير الخدمة الصادرة' : 'سجلات الصيانة'">
                    <table role="table" class="w-full text-start text-sm">
                        <thead role="rowgroup" class="bg-blue-50 text-blue-900"><tr role="row">
                            @foreach($screen === 'reports' ? ['أمر العمل','العميل','الحالة الحالية','إصدار التقرير','التقرير'] : ['الرقم والعميل','الموقع ووصف العطل', $screen === 'requests' ? 'المسؤول' : 'الفريق والزيارة التالية','الأولوية','الحالة','المتابعة'] as $h)<th role="columnheader" class="p-3 text-start">{{ $h }}</th>@endforeach
                        </tr></thead>
                        <tbody role="rowgroup">
                        @foreach($rows as $r)
                            <tr role="row" wire:key="maintenance-{{ $screen }}-{{ $r->id }}" class="border-b border-blue-50">
                                @if($screen === 'reports')
                                    <td role="cell" data-label="أمر العمل" class="p-3 font-semibold">{{ $r->number }}</td>
                                    <td role="cell" data-label="العميل" class="p-3">{{ $r->customer_name }}</td>
                                    <td role="cell" data-label="الحالة الحالية" class="p-3">@include('maintenance.status', ['status'=>$r->status])</td>
                                    <td role="cell" data-label="إصدار التقرير" class="p-3">الإصدار {{ $r->report_revision }}</td>
                                    <td role="cell" data-label="التقرير" class="p-3"><div class="flex flex-wrap gap-2"><flux:button size="sm" :href="route('maintenance.report', [$r->id, $r->completion_id])">تنزيل PDF</flux:button><flux:button size="sm" :href="route('maintenance.work-orders.show', ['workOrder'=>$r->id,'tab'=>'completion'])" wire:navigate>تفاصيل التحقق</flux:button></div></td>
                                @else
                                    <td role="cell" data-label="الرقم والعميل" class="p-3"><div class="font-semibold">{{ $r->number }}</div><div class="mt-1 text-slate-600">{{ $r->customer_name }}</div></td>
                                    <td role="cell" data-label="الموقع ووصف العطل" class="max-w-sm p-3"><div class="mb-1 text-xs text-slate-500">{{ $r->site_id ? ($siteNames[$r->site_id] ?? 'موقع العميل، مرجع '.$r->site_id) : 'الموقع غير محدد' }}</div><div>{{ Str::limit($r->description, 110) }}</div></td>
                                    <td role="cell" data-label="{{ $screen === 'requests' ? 'المسؤول' : 'الفريق والزيارة التالية' }}" class="p-3">
                                        @if($screen === 'requests')<div>{{ $people[$r->responsible_id] ?? 'مسؤول سابق، مرجع '.$r->responsible_id }}</div>
                                        @else
                                            @php($team = $orderAssignments->where('work_order_id', $r->id))
                                            <div>{{ $team->isEmpty() ? 'لم يُكلف فني بعد' : $team->map(fn($a) => $people[$a->technician_id] ?? 'فني سابق، مرجع '.$a->technician_id)->unique()->implode('، ') }}</div>
                                            <div class="mt-1 text-xs text-slate-500">@if($r->next_visit_at)زيارة مخططة: <span dir="ltr">{{ \Carbon\CarbonImmutable::parse($r->next_visit_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</span>@else لا توجد زيارة مخططة @endif</div>
                                        @endif
                                    </td>
                                    <td role="cell" data-label="الأولوية" class="p-3">{{ ['low'=>'منخفضة','normal'=>'عادية','high'=>'عالية','urgent'=>'عاجلة'][$r->priority] }}</td>
                                    <td role="cell" data-label="الحالة" class="p-3">@include('maintenance.status', ['status'=>$r->status])</td>
                                    <td role="cell" data-label="المتابعة" class="p-3"><flux:button size="sm" :href="$screen === 'requests' ? route('maintenance.requests.show', $r->id) : route('maintenance.work-orders.show', $r->id)" wire:navigate>متابعة</flux:button></td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </x-ui.responsive-table>
                <div class="mt-4">{{ $rows->links() }}</div>
            @endif
        </x-ui.panel>
    @elseif(in_array($screen, ['assignments','schedule']))
        <x-ui.panel>
            @php($scheduleRows = $screen === 'assignments' ? $assignments : $visits)
            @if($scheduleRows->isEmpty())
                <x-ui.empty-state :title="$screen === 'assignments' ? 'لا توجد تكليفات نشطة' : 'لا توجد زيارات مسجلة'" description="افتح أمر العمل، ثم حدد الفني وجدول الزيارة. تظهر المواعيد هنا بعد حفظها."><flux:button :href="route('maintenance.work-orders')" wire:navigate>اختيار أمر عمل</flux:button></x-ui.empty-state>
            @else
                <x-ui.responsive-table label="مواعيد الصيانة">
                    <table role="table" class="w-full text-start text-sm"><thead role="rowgroup" class="bg-blue-50"><tr role="row">@foreach(['أمر العمل والعميل','الفني','الموعد بتوقيت القاهرة','الحالة','المتابعة'] as $h)<th role="columnheader" class="p-3 text-start">{{ $h }}</th>@endforeach</tr></thead><tbody role="rowgroup">
                    @foreach($scheduleRows as $row)<tr role="row" wire:key="maintenance-schedule-{{ $row->id }}" class="border-b border-blue-50">
                        <td role="cell" data-label="أمر العمل والعميل" class="p-3"><div class="font-semibold">{{ $row->number }}</div><div class="mt-1 text-slate-500">{{ $row->customer_name }}</div></td>
                        <td role="cell" data-label="الفني" class="p-3">{{ $people[$row->technician_id] ?? 'فني سابق، مرجع '.$row->technician_id }}</td>
                        <td role="cell" data-label="الموعد بتوقيت القاهرة" class="p-3"><span dir="ltr">{{ \Carbon\CarbonImmutable::parse($row->starts_at ?? $row->scheduled_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }} @isset($row->ends_at) → {{ \Carbon\CarbonImmutable::parse($row->ends_at)->setTimezone('Africa/Cairo')->format('H:i') }} @endisset</span></td>
                        <td role="cell" data-label="الحالة" class="p-3">@include('maintenance.status', ['status'=>$screen === 'assignments' ? 'active_assignment' : $row->status])</td>
                        <td role="cell" data-label="المتابعة" class="p-3"><flux:button size="sm" :href="route('maintenance.work-orders.show', ['workOrder'=>$row->work_order_id,'tab'=>$screen === 'assignments' ? 'assignments' : 'visits'])" wire:navigate>{{ $screen === 'assignments' ? 'التكليف والجدولة' : 'متابعة الزيارة' }}</flux:button></td>
                    </tr>@endforeach
                    </tbody></table>
                </x-ui.responsive-table>
            @endif
        </x-ui.panel>
    @elseif($screen === 'checklists')
        <x-ui.panel>
            <flux:heading size="lg">قوالب الفحص المتاحة</flux:heading>
            @forelse($templates as $t)
                <div class="mt-4 border-t border-slate-100 pt-4" wire:key="checklist-{{ $t->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="font-semibold">{{ $t->title }}</h2><p class="mt-1 text-xs text-slate-500">{{ $t->code }} · الإصدار {{ $t->version }} · {{ $t->active ? 'نشط' : 'غير نشط' }}</p></div>@if($access->allows(auth()->id(), $t->branch_id, 'checklist_manage'))<flux:button size="sm" wire:click="editTemplate({{ $t->id }})">تعديل القالب</flux:button>@endif</div>
                    <ul class="mt-3 space-y-2 text-sm">@foreach(json_decode($t->items, true) as $item)<li>{{ $item['label'] }} <span class="text-slate-500">{{ $item['required'] ? '· إلزامي' : '· اختياري' }}</span></li>@endforeach</ul>
                </div>
            @empty<x-ui.empty-state title="لا توجد قوالب فحص" description="أضف بنود الفحص المعتمدة للمعدة قبل جدولة أول زيارة."/>@endforelse
        </x-ui.panel>
        @if($templateBranches)
            <x-ui.panel id="checklist-editor">
                <form wire:submit="saveTemplate" class="space-y-4">
                    <flux:heading size="lg">{{ $templateId ? 'تعديل قالب الفحص' : 'قالب فحص جديد' }}</flux:heading>
                    <p class="text-sm text-slate-500">الزيارات السابقة تحتفظ بنسخة الفحص المسجلة وقت جدولتها.</p>
                    <div class="grid gap-4 md:grid-cols-3">
                        <flux:select label="الفرع" wire:model="template.branch_id" :disabled="$templateId !== null"><option value="">اختر الفرع</option>@foreach($templateBranches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
                        <flux:input label="كود القالب" wire:model="template.code"/>
                        <flux:input label="عنوان القالب" wire:model="template.title"/>
                    </div>
                    @foreach($template['items'] as $i=>$item)
                        <div class="flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-end" wire:key="check-item-{{ $item['key'] }}">
                            <div class="flex-1"><flux:input :label="'بند الفحص '.($i + 1)" wire:model="template.items.{{ $i }}.label"/></div>
                            <flux:checkbox label="إلزامي" wire:model="template.items.{{ $i }}.required"/>
                            @if(count($template['items']) > 1)<flux:button type="button" size="sm" variant="ghost" wire:click="removeItem({{ $i }})" :aria-label="'إزالة بند الفحص '.($i + 1)">إزالة البند</flux:button>@endif
                        </div>
                    @endforeach
                    <flux:checkbox label="قالب نشط متاح للزيارات الجديدة" wire:model="template.active"/>
                    <div class="flex flex-wrap gap-2"><flux:button type="button" wire:click="addItem">إضافة بند فحص</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ القالب</flux:button>@if($templateId)<flux:button type="button" wire:click="cancelTemplate">إلغاء التعديل</flux:button>@endif</div>
                </form>
            </x-ui.panel>
        @endif
    @endif
</div>
