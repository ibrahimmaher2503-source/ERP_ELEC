<div data-erp-archetype="list" class="space-y-6">
    <x-page.heading title="الموظفون" description="إدارة بيانات الموظفين وفترات العمل حسب الفرع">
        <x-slot:actions>@if($manageBranches)<flux:button variant="primary" icon="plus" wire:click="create">إضافة موظف</flux:button>@endif</x-slot:actions>
    </x-page.heading>
    @if(session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif
    <div class="erp-kpi-summary grid gap-3 sm:grid-cols-3">
        <x-erp.stat-card label="إجمالي الموظفين" :value="$stats->total" icon="user-group" tone="blue" />
        <x-erp.stat-card label="الموظفون النشطون" :value="$stats->active" icon="user-circle" tone="emerald" />
        <x-erp.stat-card label="الموظفون غير النشطين" :value="$stats->inactive" icon="user-minus" tone="orange" />
    </div>

    @php
        $branchNames = array_column($branches, 'name', 'id');
        $managedBranchIds = array_column($manageBranches, 'id');
    @endphp
    <x-ui.panel>
        <x-ui.filters id="employee-filters" target="search,branch,statusFilter" :reset="['search' => '', 'branch' => '', 'statusFilter' => '']">
            <x-slot:search><flux:input type="search" label="البحث عن موظف" placeholder="الاسم أو كود الموظف" wire:model.live.debounce.300ms="search" icon="magnifying-glass" /></x-slot:search>
            <flux:select label="الحالة" wire:model.live="statusFilter"><option value="">جميع الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option></flux:select>
            <flux:select label="الفرع" wire:model.live="branch"><option value="">جميع الفروع المتاحة</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
        </x-ui.filters>
        <div wire:loading.class="opacity-60" wire:target="search,branch,statusFilter,gotoPage,nextPage,previousPage">
            <x-ui.responsive-table label="دليل الموظفين">
                <table role="table" class="w-full min-w-[760px] text-start text-sm">
                    <thead role="rowgroup" class="bg-slate-50 text-slate-500">
                        <tr role="row">
                            @foreach(['#', 'الموظف', 'الفرع', 'المسمى الوظيفي', 'فترة التوظيف', 'الحالة', 'الإجراءات'] as $label)
                                <th role="columnheader" scope="col" class="p-4 text-start">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="divide-y divide-slate-100">
                        @forelse($rows as $employee)
                            <tr role="row" wire:key="employee-{{ $employee->id }}">
                                <td role="cell" data-label="الصف" class="p-4"><div>{{ $rows->firstItem() + $loop->index }}</div></td>
                                <td role="cell" data-label="الموظف" class="p-4">
                                    <div class="flex items-center gap-3">
                                        <span aria-hidden="true" class="grid size-9 shrink-0 place-items-center rounded-full bg-blue-50 text-blue-700"><flux:icon.user class="size-4" /></span>
                                        <div class="min-w-0"><p class="font-medium">{{ $employee->name }}</p><p class="text-xs text-slate-500"><bdi>{{ $employee->code }}</bdi></p></div>
                                    </div>
                                </td>
                                <td role="cell" data-label="الفرع" class="p-4"><div>{{ $branchNames[$employee->branch_id] ?? 'غير محدد' }}</div></td>
                                <td role="cell" data-label="المسمى الوظيفي" class="p-4"><div>{{ $employee->job_title ?: '—' }}</div></td>
                                <td role="cell" data-label="فترة التوظيف" class="p-4"><div><bdi>{{ $employee->starts_on }}</bdi><p class="text-xs text-slate-500">{{ $employee->ends_on ? 'حتى '.$employee->ends_on : 'مستمرة' }}</p></div></td>
                                <td role="cell" data-label="الحالة" class="p-4"><div><x-erp.status-badge :active="$employee->active" /></div></td>
                                <td role="cell" data-label="الإجراءات" class="p-4"><div>
                                    @if(in_array($employee->branch_id, $managedBranchIds))
                                        <flux:dropdown>
                                            <flux:button icon="ellipsis-horizontal" variant="ghost" size="sm" aria-label="إجراءات {{ $employee->name }}" />
                                            <flux:menu><flux:menu.item wire:click="edit({{ $employee->id }})" icon="pencil-square">تحرير بيانات الموظف</flux:menu.item></flux:menu>
                                        </flux:dropdown>
                                    @else
                                        <span class="text-xs text-slate-500">عرض فقط</span>
                                    @endif
                                </div></td>
                            </tr>
                        @empty
                            <tr role="row"><td colspan="7" class="p-8"><x-ui.empty-state title="لا يوجد موظفون للعرض" description="عدّل معايير البحث أو أضف موظفًا بصلاحية مناسبة." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-ui.responsive-table>
        </div>
        <div class="mt-4 border-t border-slate-100 pt-4">{{ $rows->links() }}</div>
    </x-ui.panel>

    <flux:modal wire:model="editing" class="w-full max-w-lg">
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
            <flux:input label="المسمى الوظيفي (اختياري)" wire:model="form.job_title" maxlength="200" />
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
