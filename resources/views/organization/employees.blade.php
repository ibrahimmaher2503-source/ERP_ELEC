<div data-erp-archetype="list" class="space-y-6">
    <x-page.heading title="الموظفون" description="دليل الموظفين وفترات العمل حسب الفرع" :breadcrumbs="['الموظفون والإدارة', 'الموظفون']">
        <x-slot:actions>@if($manageBranches)<flux:button variant="primary" icon="plus" wire:click="create">إضافة موظف</flux:button>@endif</x-slot:actions>
    </x-page.heading>
    @if(session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif
    <div class="erp-kpi-summary grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-erp.stat-card label="إجمالي الموظفين" :value="$stats->total" icon="user-group" tone="blue" />
        <x-erp.stat-card label="الموظفون النشطون" :value="$stats->active" icon="user-circle" tone="emerald" />
        <x-erp.stat-card label="غير النشطين" :value="$stats->inactive" icon="user-minus" tone="orange" />
        <x-erp.stat-card label="ينتهي عملهم خلال 30 يومًا" :value="$stats->ending" icon="clock" tone="orange" />
        <x-erp.stat-card label="مرتبطون بحساب دخول" :value="$stats->linked" icon="key" />
    </div>

    @php
        $branchNames = array_column($branches, 'name', 'id');
        $managedBranchIds = array_column($manageBranches, 'id');
        $initials = static fn (string $name): string => mb_substr(trim(preg_replace('/^(أ\.|د\.|م\.|الأستاذ|الدكتور|المهندس)\s+/u', '', $name)), 0, 1);
        $tenure = static function (string $from, ?string $to) use ($today): string {
            $days = (int) \Carbon\CarbonImmutable::parse($from)->diffInDays(\Carbon\CarbonImmutable::parse($to ?: $today), true);
            if ($days < 31) {
                return 'أقل من شهر';
            }
            $months = intdiv($days, 30);

            return $months < 12 ? $months.' شهر' : intdiv($months, 12).' سنة'.($months % 12 ? ' و '.($months % 12).' شهر' : '');
        };
        $sortMark = fn (string $column): string => $sort === $column ? ($direction === 'asc' ? ' ↑' : ' ↓') : ' ↕';
    @endphp
    <x-ui.panel>
        <x-ui.filters id="employee-filters" target="search,branch,statusFilter,jobFilter" :reset="['search' => '', 'branch' => '', 'statusFilter' => '', 'jobFilter' => '']">
            <x-slot:search><flux:input type="search" label="البحث عن موظف" placeholder="الاسم أو الكود أو المسمى الوظيفي" wire:model.live.debounce.300ms="search" icon="magnifying-glass" /></x-slot:search>
            <flux:select label="الحالة" wire:model.live="statusFilter"><option value="">جميع الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option></flux:select>
            <flux:select label="الفرع" wire:model.live="branch"><option value="">جميع الفروع المتاحة</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
            <flux:select label="المسمى الوظيفي" wire:model.live="jobFilter"><option value="">جميع المسميات</option>@foreach($jobTitles as $title)<option value="{{ $title }}">{{ $title }}</option>@endforeach</flux:select>
        </x-ui.filters>
        <div wire:loading.class="opacity-60" wire:target="search,branch,statusFilter,jobFilter,sortBy,perPage,gotoPage,nextPage,previousPage">
            <x-ui.responsive-table label="دليل الموظفين">
                <table role="table" class="w-full min-w-[860px] text-start text-sm">
                    <thead role="rowgroup" class="bg-slate-50 text-slate-500">
                        <tr role="row">
                            <th role="columnheader" scope="col" class="p-4 text-start"><button type="button" wire:click="sortBy('name')" class="font-semibold">الموظف{{ $sortMark('name') }}</button></th>
                            <th role="columnheader" scope="col" class="p-4 text-start"><button type="button" wire:click="sortBy('code')" class="font-semibold">الكود{{ $sortMark('code') }}</button></th>
                            <th role="columnheader" scope="col" class="p-4 text-start">الفرع</th>
                            <th role="columnheader" scope="col" class="p-4 text-start"><button type="button" wire:click="sortBy('job_title')" class="font-semibold">المسمى الوظيفي{{ $sortMark('job_title') }}</button></th>
                            <th role="columnheader" scope="col" class="p-4 text-start"><button type="button" wire:click="sortBy('starts_on')" class="font-semibold">فترة العمل{{ $sortMark('starts_on') }}</button></th>
                            <th role="columnheader" scope="col" class="p-4 text-start">الحالة</th>
                            <th role="columnheader" scope="col" class="p-4 text-start">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="divide-y divide-slate-100">
                        @forelse($rows as $employee)
                            @php($canManage = in_array($employee->branch_id, $managedBranchIds))
                            @php($endingSoon = $employee->ends_on && $employee->ends_on >= $today && $employee->ends_on <= \Carbon\CarbonImmutable::parse($today)->addDays(30)->toDateString())
                            <tr role="row" wire:key="employee-{{ $employee->id }}">
                                <td role="cell" data-label="الموظف" class="p-4">
                                    <div class="flex items-center gap-3">
                                        <span aria-hidden="true" @class(['grid size-10 shrink-0 place-items-center rounded-full text-sm font-bold', 'bg-blue-50 text-blue-700' => $employee->active, 'bg-slate-100 text-slate-500' => ! $employee->active])>{{ $initials($employee->name) }}</span>
                                        <div class="min-w-0">
                                            <a href="{{ route('organization.employees.show', $employee->id) }}" wire:navigate class="font-semibold hover:underline">{{ $employee->name }}</a>
                                            @if($employee->user_id)<p class="inline-flex items-center gap-1 text-xs text-emerald-700"><flux:icon.key class="size-3" /> لديه حساب دخول</p>@else<p class="text-xs text-slate-500">بدون حساب دخول</p>@endif
                                        </div>
                                    </div>
                                </td>
                                <td role="cell" data-label="الكود" class="p-4"><div><bdi class="text-xs">{{ $employee->code }}</bdi></div></td>
                                <td role="cell" data-label="الفرع" class="p-4"><div>{{ $branchNames[$employee->branch_id] ?? 'غير محدد' }}</div></td>
                                <td role="cell" data-label="المسمى الوظيفي" class="p-4"><div>{{ $employee->job_title ?: '—' }}</div></td>
                                <td role="cell" data-label="فترة العمل" class="p-4"><div><bdi>{{ $employee->starts_on }}</bdi><p @class(['text-xs', 'text-slate-500' => ! $endingSoon, 'font-semibold text-orange-700' => $endingSoon])>{{ $employee->ends_on ? 'حتى '.$employee->ends_on : 'مستمرة' }} · {{ $tenure($employee->starts_on, $employee->ends_on) }}</p></div></td>
                                <td role="cell" data-label="الحالة" class="p-4"><div><x-erp.status-badge :active="$employee->active" />@if($endingSoon)<p class="mt-1 text-xs text-orange-700">ينتهي قريبًا</p>@endif</div></td>
                                <td role="cell" data-label="الإجراءات" class="p-4"><div>
                                    @if($canManage)
                                        <div class="flex gap-1">
                                            <flux:button icon="eye" variant="ghost" size="sm" :href="route('organization.employees.show', $employee->id)" wire:navigate aria-label="ملف {{ $employee->name }}" />
                                            <flux:button icon="pencil-square" variant="ghost" size="sm" wire:click="edit({{ $employee->id }})" aria-label="تحرير {{ $employee->name }}" />
                                            <flux:dropdown>
                                                <flux:button icon="ellipsis-horizontal" variant="ghost" size="sm" aria-label="إجراءات {{ $employee->name }}" />
                                                <flux:menu>
                                                    <flux:menu.item :href="route('organization.employees.show', $employee->id)" wire:navigate icon="eye">عرض بيانات الموظف</flux:menu.item>
                                                    <flux:menu.item wire:click="edit({{ $employee->id }})" icon="pencil-square">تحرير بيانات الموظف</flux:menu.item>
                                                    <flux:menu.item wire:click="toggleActive({{ $employee->id }})" wire:confirm="{{ $employee->active ? 'إيقاف هذا الموظف؟' : 'إعادة تفعيل هذا الموظف؟' }}" icon="{{ $employee->active ? 'pause-circle' : 'play-circle' }}">{{ $employee->active ? 'إيقاف الموظف' : 'تفعيل الموظف' }}</flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </div>
                                    @else
                                        <flux:button icon="eye" variant="ghost" size="sm" :href="route('organization.employees.show', $employee->id)" wire:navigate aria-label="عرض {{ $employee->name }}" />
                                    @endif
                                </div></td>
                            </tr>
                        @empty
                            <tr role="row"><td colspan="7" class="p-8"><x-ui.empty-state title="لا يوجد موظفون مطابقون" description="غيّر معايير البحث والتصفية، أو أضف موظفًا إذا كانت لديك الصلاحية." icon="user-group" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-ui.responsive-table>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
            <span class="text-xs text-slate-500">{{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} من {{ $rows->total() }} موظف</span>
            <flux:select wire:model.live="perPage" label="عدد الصفوف" class="!w-24"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></flux:select>
            <div class="flex-1">{{ $rows->links() }}</div>
        </div>
    </x-ui.panel>

    <flux:modal wire:model="editing" class="md:w-[34rem]">
        <form wire:submit="save" class="space-y-5">
            <x-ui.validation-summary />
            <div>
                <flux:heading size="lg">{{ $employeeId ? 'تحرير بيانات الموظف' : 'إضافة موظف' }}</flux:heading>
                <flux:text class="mt-1">بيانات الموظف والفرع وفترة العمل.</flux:text>
            </div>
            <flux:input label="الاسم الكامل" wire:model="form.name" required maxlength="200" minlength="2" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select label="الفرع" wire:model="form.branch_id" :disabled="(bool)$employeeId" required>
                    <option value="">اختر الفرع</option>
                    @foreach($manageBranches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach
                </flux:select>
                <flux:input label="كود الموظف" wire:model="form.code" :disabled="(bool)$employeeId" required maxlength="50" />
            </div>
            <flux:input label="المسمى الوظيفي (اختياري)" wire:model="form.job_title" maxlength="200" list="employee-job-titles" />
            <datalist id="employee-job-titles">@foreach($jobTitles as $title)<option value="{{ $title }}"></option>@endforeach</datalist>
            <div class="grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2">
                <flux:input type="date" label="بداية التوظيف" wire:model="form.starts_on" required />
                <flux:input type="date" label="نهاية التوظيف (اختياري)" wire:model="form.ends_on" />
            </div>
            <flux:checkbox label="موظف نشط" wire:model="form.active" />
            <div class="flex flex-wrap gap-3">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">حفظ الموظف</flux:button>
                <flux:button type="button" wire:click="cancel">إلغاء</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
