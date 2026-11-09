<div data-erp-archetype="details" class="space-y-6">
    @php
        $fmtMin = static fn (int $m): string => $m <= 0 ? '0' : (intdiv($m, 60) > 0 ? intdiv($m, 60).' س' : '').($m % 60 ? ' '.($m % 60).' د' : '');
        $cairo = static fn (?string $iso): string => $iso ? \Carbon\CarbonImmutable::parse($iso)->setTimezone('Africa/Cairo')->format('H:i') : '—';
        $initial = mb_substr(trim(preg_replace('/^(أ\.|د\.|م\.|الأستاذ|الدكتور|المهندس)\s+/u', '', $e->name)), 0, 1);
        $days = (int) \Carbon\CarbonImmutable::parse($e->starts_on)->diffInDays(\Carbon\CarbonImmutable::parse($e->ends_on ?: $today), true);
        $months = intdiv($days, 30);
        $tenure = $days < 31 ? 'أقل من شهر' : ($months < 12 ? $months.' شهر' : intdiv($months, 12).' سنة'.($months % 12 ? ' و '.($months % 12).' شهر' : ''));
        $monthLabel = \Carbon\CarbonImmutable::parse($month.'-01')->locale('ar')->translatedFormat('F Y');
        $dayLabels = ['Sat' => 'السبت', 'Sun' => 'الأحد', 'Mon' => 'الاثنين', 'Tue' => 'الثلاثاء', 'Wed' => 'الأربعاء', 'Thu' => 'الخميس', 'Fri' => 'الجمعة'];
        $dayStatus = static function (array $d, string $today): array {
            return match (true) {
                $d['leave'] => ['إجازة', 'bg-blue-50 text-blue-700'],
                $d['absent'] => ['غياب', 'bg-red-50 text-red-700'],
                $d['missing_punch'] => ['بصمة ناقصة', 'bg-orange-50 text-orange-700'],
                (bool) $d['check_in'] && $d['late_minutes'] > 0 => ['حضور متأخر', 'bg-amber-50 text-amber-700'],
                (bool) $d['check_in'] => ['حاضر', 'bg-emerald-50 text-emerald-700'],
                $d['work_date'] >= $today => ['قادم', 'bg-slate-100 text-slate-600'],
                default => ['لم يُسجل', 'bg-slate-100 text-slate-600'],
            };
        };
    @endphp

    <x-page.heading :title="$e->name" :description="($e->job_title ?: 'بدون مسمى وظيفي').' · '.$branchName" :breadcrumbs="['الموظفون والإدارة', 'الموظفون', $e->name]">
        <x-slot:actions>
            <flux:button icon="arrow-right" :href="route('organization.employees')" wire:navigate>كل الموظفين</flux:button>
            <flux:button icon="clock" :href="route('attendance.index', ['branch' => $e->branch_id, 'employee' => $e->id, 'month' => $month])" wire:navigate>إدارة الحضور</flux:button>
            @if($canManage)<flux:button variant="primary" icon="pencil-square" :href="route('organization.employees')" wire:navigate>تحرير من القائمة</flux:button>@endif
        </x-slot:actions>
    </x-page.heading>

    <x-ui.panel>
        <div class="flex flex-wrap items-center gap-4">
            <span aria-hidden="true" @class(['grid size-16 shrink-0 place-items-center rounded-full text-2xl font-bold', 'bg-blue-50 text-blue-700' => $e->active, 'bg-slate-100 text-slate-500' => ! $e->active])>{{ $initial }}</span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2"><x-erp.status-badge :active="$e->active" /><span class="text-sm font-semibold"><bdi>{{ $e->code }}</bdi></span></div>
                <p class="mt-1 text-sm text-slate-500">{{ $e->job_title ?: 'بدون مسمى وظيفي' }} · {{ $branchName }}</p>
                <p class="mt-1 text-xs text-slate-500">منذ <bdi>{{ $e->starts_on }}</bdi> · {{ $tenure }} @if($e->ends_on) · ينتهي <bdi>{{ $e->ends_on }}</bdi>@endif</p>
            </div>
            @if($todayDay)
                @php([$label, $tone] = $dayStatus($todayDay, $today))
                <div class="rounded-lg border border-slate-200 px-4 py-3 text-sm">
                    <p class="text-xs text-slate-500">اليوم</p>
                    <p class="mt-1"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone }}">{{ $label }}</span></p>
                    <p class="mt-1 text-xs text-slate-500">الوردية <bdi>{{ $cairo($todayDay['scheduled_start']) }}–{{ $cairo($todayDay['scheduled_end']) }}</bdi> · دخول <bdi>{{ $cairo($todayDay['check_in']) }}</bdi></p>
                </div>
            @elseif($sheet)
                <div class="rounded-lg border border-slate-200 px-4 py-3 text-sm"><p class="text-xs text-slate-500">اليوم</p><p class="mt-1 text-slate-600">لا توجد وردية مجدولة</p></div>
            @endif
        </div>
    </x-ui.panel>

    <div role="tablist" aria-label="أقسام ملف الموظف" class="erp-tabs flex gap-1 border-b border-slate-200">
        @foreach(['overview' => 'نظرة عامة', 'attendance' => 'الحضور والانصراف', 'job' => 'البيانات الوظيفية'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" @class(['border-b-2 px-4 py-2 text-sm font-semibold', 'border-blue-600 text-blue-700' => $tab === $key, 'border-transparent text-slate-500 hover:text-slate-800' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if($tab !== 'job')
        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="sm" icon="chevron-right" wire:click="shiftMonth(-1)" aria-label="الشهر السابق" />
            <span class="min-w-32 text-center text-sm font-semibold" wire:loading.class="opacity-50">{{ $monthLabel }}</span>
            <flux:button size="sm" icon="chevron-left" wire:click="shiftMonth(1)" aria-label="الشهر التالي" />
            <flux:button size="sm" variant="ghost" wire:click="goToCurrentMonth">الشهر الحالي</flux:button>
            @if($sheet && ($sheet['locked'] ?? false))<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">كشف مقفل</span>@endif
            @if($sheet && $canExport)<flux:button size="sm" icon="arrow-down-tray" :href="route('attendance.export', [$e->id, $month])">تصدير الكشف</flux:button>@endif
        </div>
    @endif

    @if($tab !== 'job' && $attendanceError)
        <flux:callout variant="warning" role="status">{{ $attendanceError }}</flux:callout>
    @endif

    @if($tab === 'overview' || $tab === 'attendance')
        @if($summary)
            <div class="erp-kpi-summary grid grid-cols-2 gap-3 lg:grid-cols-6">
                <x-erp.stat-card label="أيام مجدولة" :value="$summary['scheduled']" icon="calendar-days" />
                <x-erp.stat-card label="أيام حضور" :value="$summary['present']" icon="check-circle" tone="emerald" />
                <x-erp.stat-card label="أيام غياب" :value="$summary['absent']" icon="x-circle" tone="orange" />
                <x-erp.stat-card label="أيام إجازة" :value="$summary['leave']" icon="sun" tone="blue" />
                <x-erp.stat-card label="إجمالي التأخير" :value="$fmtMin($summary['late_minutes'])" icon="clock" tone="orange" />
                <x-erp.stat-card label="ساعات العمل" :value="$fmtMin($summary['worked_minutes'])" icon="briefcase" />
            </div>
            <div class="grid gap-3 text-sm sm:grid-cols-3">
                <x-ui.panel><p class="text-xs text-slate-500">نسبة الانضباط في الأيام المنقضية</p><p class="mt-1 text-lg font-bold">{{ $summary['attendance_rate'] === null ? '—' : $summary['attendance_rate'].'%' }}</p></x-ui.panel>
                <x-ui.panel><p class="text-xs text-slate-500">بصمات ناقصة تحتاج تصحيحًا</p><p class="mt-1 text-lg font-bold {{ $summary['missing'] ? 'text-orange-700' : '' }}">{{ $summary['missing'] }}</p></x-ui.panel>
                <x-ui.panel><p class="text-xs text-slate-500">أوفرتايم معتمد</p><p class="mt-1 text-lg font-bold">{{ $fmtMin($summary['overtime_minutes']) }}</p></x-ui.panel>
            </div>
        @endif

        @if($sheet)
            <x-ui.panel>
                <flux:heading size="lg" class="mb-3">{{ $tab === 'overview' ? 'آخر أيام الشهر' : 'سجل الأيام' }}</flux:heading>
                @php($shown = collect($sheet['days'])->sortByDesc('work_date')->when($tab === 'overview', fn ($c) => $c->take(7))->values())
                @if($shown->isEmpty())
                    <x-ui.empty-state title="لا توجد ورديات مجدولة في هذا الشهر" description="تُضاف الجداول من صفحة إدارة الحضور بصلاحية جدولة الورديات." icon="calendar-days" />
                @else
                    <x-ui.responsive-table label="سجل حضور {{ $e->name }}">
                        <table role="table" class="w-full min-w-[760px] text-start text-sm">
                            <thead role="rowgroup" class="bg-slate-50 text-slate-500"><tr role="row">@foreach(['التاريخ', 'الوردية', 'دخول', 'خروج', 'ساعات العمل', 'تأخير', 'انصراف مبكر', 'الحالة'] as $h)<th role="columnheader" scope="col" class="p-3 text-start">{{ $h }}</th>@endforeach</tr></thead>
                            <tbody role="rowgroup" class="divide-y divide-slate-100">
                                @foreach($shown as $d)
                                    @php([$label, $tone] = $dayStatus($d, $today))
                                    <tr role="row" wire:key="day-{{ $d['schedule_id'] }}">
                                        <td role="cell" data-label="التاريخ" class="p-3"><div><bdi>{{ $d['work_date'] }}</bdi><p class="text-xs text-slate-500">{{ $dayLabels[\Carbon\CarbonImmutable::parse($d['work_date'])->format('D')] ?? '' }}</p></div></td>
                                        <td role="cell" data-label="الوردية" class="p-3"><div><bdi>{{ $cairo($d['scheduled_start']) }}–{{ $cairo($d['scheduled_end']) }}</bdi></div></td>
                                        <td role="cell" data-label="دخول" class="p-3"><div><bdi>{{ $cairo($d['check_in']) }}</bdi></div></td>
                                        <td role="cell" data-label="خروج" class="p-3"><div><bdi>{{ $cairo($d['check_out']) }}</bdi></div></td>
                                        <td role="cell" data-label="ساعات العمل" class="p-3"><div>{{ $d['worked_minutes'] ? $fmtMin($d['worked_minutes']) : '—' }}</div></td>
                                        <td role="cell" data-label="تأخير" class="p-3"><div @class(['text-amber-700' => $d['late_minutes'] > 0])>{{ $d['late_minutes'] ? $fmtMin($d['late_minutes']) : '—' }}</div></td>
                                        <td role="cell" data-label="انصراف مبكر" class="p-3"><div>{{ ($d['check_out'] && $d['early_minutes']) ? $fmtMin($d['early_minutes']) : '—' }}</div></td>
                                        <td role="cell" data-label="الحالة" class="p-3"><div><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone }}">{{ $label }}</span></div></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.responsive-table>
                    @if($tab === 'overview' && count($sheet['days']) > 7)<p class="mt-3 text-sm"><button type="button" wire:click="setTab('attendance')" class="font-semibold text-blue-700 hover:underline">عرض كل أيام الشهر ({{ count($sheet['days']) }})</button></p>@endif
                @endif
            </x-ui.panel>
        @endif

        @if($tab === 'attendance' && $sheet)
            <x-erp.section-card title="إجراءات المتابعة" icon="bolt">
                <p class="mb-3 text-sm text-slate-600">تسجيل البصمة وطلب التصحيح أو الإجازة أو الأوفرتايم واعتمادها وقفل الكشف تتم من صفحة الحضور بصلاحياتها الخاصة (الطالب لا يعتمد طلبه).</p>
                <flux:button icon="clock" variant="primary" :href="route('attendance.index', ['branch' => $e->branch_id, 'employee' => $e->id, 'month' => $month])" wire:navigate>فتح كشف {{ $monthLabel }} في إدارة الحضور</flux:button>
            </x-erp.section-card>
        @endif
    @endif

    @if($tab === 'job' || $tab === 'overview')
        <div class="grid gap-4 lg:grid-cols-2">
            <x-erp.section-card title="البيانات الوظيفية" icon="briefcase">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                    <div><dt class="text-xs text-slate-500">كود الموظف</dt><dd class="font-medium"><bdi>{{ $e->code }}</bdi></dd></div>
                    <div><dt class="text-xs text-slate-500">الفرع</dt><dd class="font-medium">{{ $branchName }}</dd></div>
                    <div><dt class="text-xs text-slate-500">المسمى الوظيفي</dt><dd class="font-medium">{{ $e->job_title ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">الحالة</dt><dd><x-erp.status-badge :active="$e->active" /></dd></div>
                    <div><dt class="text-xs text-slate-500">بداية التوظيف</dt><dd class="font-medium"><bdi>{{ $e->starts_on }}</bdi></dd></div>
                    <div><dt class="text-xs text-slate-500">نهاية التوظيف</dt><dd class="font-medium">{{ $e->ends_on ?: 'مستمرة' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">مدة العمل</dt><dd class="font-medium">{{ $tenure }}</dd></div>
                    <div><dt class="text-xs text-slate-500">حساب الدخول</dt><dd class="font-medium">{{ $e->user_id ? 'مرتبط بحساب دخول' : 'بدون حساب دخول' }}</dd></div>
                </dl>
            </x-erp.section-card>
            <x-erp.section-card title="ما غير متاح بعد" icon="information-circle">
                <p class="text-sm text-slate-600">الرواتب والسلف والتقييم والمستندات الوظيفية لم يُنشأ لها سجل في النظام بعد، فلا تُعرض هنا أرقام أو حالات لها. الحضور والانصراف والإجازات والأوفرتايم تُقرأ من وحدة الحضور مباشرة.</p>
            </x-erp.section-card>
        </div>
    @endif
</div>
