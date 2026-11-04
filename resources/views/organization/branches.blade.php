<div data-erp-archetype="list" class="space-y-6">
    <x-page.heading title="الفروع" description="إدارة فروع الشركة">
        <x-slot:actions>
            @if ($canCreate)<flux:button variant="primary" icon="plus" wire:click="create">إضافة فرع</flux:button>@endif
        </x-slot:actions>
        <x-slot:secondaryActions>
            @if ($access->allows(auth()->user(), 'M01.company_view') && \Illuminate\Support\Facades\Route::has('organization.company'))
                <flux:button :href="route('organization.company')" icon="building-office" wire:navigate>بيانات الشركة</flux:button>
            @endif
        </x-slot:secondaryActions>
    </x-page.heading>
    @if (session('status'))
        <flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>
    @endif
    <div class="erp-kpi-summary grid gap-3 sm:grid-cols-3">
        @foreach (['total' => ['إجمالي الفروع', 'building-office-2', 'blue'], 'active' => ['الفروع النشطة', 'home', 'emerald'], 'inactive' => ['الفروع غير النشطة', 'clock', 'orange']] as $key => [$label, $icon, $color])
            <x-erp.stat-card :label="$label" :value="$stats[$key]" :icon="$icon" :tone="$color" />
        @endforeach
    </div>
    <x-ui.panel>
        <x-ui.filters id="branch-filters" target="search,statusFilter" :reset="['search' => '', 'statusFilter' => '']"><x-slot:search><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="البحث عن فرع" placeholder="اسم الفرع أو الكود..." /></x-slot:search><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">

            <flux:select wire:model.live="statusFilter" label="الحالة"><option value="">كل الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option></flux:select>

        </div></x-ui.filters>
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <x-ui.responsive-table label="السجلات المعروضة"><table role="table" class="w-full min-w-[520px] text-start text-sm">
                <thead role="rowgroup" class="bg-slate-50 text-slate-500"><tr role="row"><th role="columnheader" class="p-4 text-start">#</th><th role="columnheader" class="p-4 text-start">اسم الفرع</th><th role="columnheader" class="p-4 text-start">الكود</th><th role="columnheader" class="p-4 text-start">الحالة</th><th role="columnheader" class="p-4 text-start">الإجراءات</th></tr></thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @forelse ($branches as $branch)
                        <tr role="row" wire:key="branch-{{ $branch->id }}"><td role="cell" data-label="الصف" class="p-4"><div>{{ $branches->firstItem() + $loop->index }}</div></td><td role="cell" data-label="اسم الفرع" class="p-4"><div><a class="font-medium hover:text-blue-700" href="{{ route('organization.branches.show', $branch->id) }}" wire:navigate>{{ $branch->name }}</a></div></td><td role="cell" data-label="الكود" class="p-4"><div><bdi>{{ $branch->code }}</bdi></div></td><td role="cell" data-label="الحالة" class="p-4"><div><x-erp.status-badge :active="$branch->active" /></div></td><td role="cell" data-label="الإجراءات" class="p-4"><div>
                            <flux:dropdown><flux:button icon="ellipsis-horizontal" variant="ghost" size="sm" aria-label="إجراءات {{ $branch->name }}" /><flux:menu>
                                <flux:menu.item :href="route('organization.branches.show', $branch->id)" icon="eye" wire:navigate>تفاصيل الفرع</flux:menu.item>
                                @if ($access->branchAllows(auth()->user(), 'M01.branch_update', $branch->id))<flux:menu.item wire:click="edit({{ $branch->id }})" icon="pencil-square">تعديل</flux:menu.item>@endif
                                @if ($access->branchAllows(auth()->user(), 'M01.branch_status', $branch->id))<flux:menu.item wire:click="confirmStatus({{ $branch->id }})" icon="power">{{ $branch->active ? 'تعطيل الفرع' : 'تفعيل الفرع' }}</flux:menu.item>@endif
                            </flux:menu></flux:dropdown>
                        </div></td></tr>
                    @empty
                        <tr role="row"><td colspan="5" class="p-8"><x-ui.empty-state title="لا توجد فروع للعرض" description="أضف فرعًا بصلاحية مناسبة أو عدّل معايير البحث." /></td></tr>
                    @endforelse
                </tbody>
            </table></x-ui.responsive-table>
        </div>
        <div class="mt-4 border-t border-slate-100 pt-4">{{ $branches->links() }}</div>
        <p class="mt-3 text-xs text-slate-500">الإحصاءات والنتائج تخص الفروع المسموح لك بعرضها.</p>
    </x-ui.panel>
    <flux:modal wire:model="editing" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <x-ui.validation-summary />
            <flux:heading size="lg">{{ $editingId ? 'تعديل الفرع' : 'إضافة فرع' }}</flux:heading>
            <flux:input wire:model="form.code" label="كود الفرع" description="كود فريد للفرع، بحد أقصى 50 حرفًا." required maxlength="50" />
            <flux:input wire:model="form.name" label="اسم الفرع" required maxlength="200" />
            <flux:textarea wire:model="form.address" label="العنوان" maxlength="2000" />
            <flux:input wire:model="form.manager_name" label="مدير الفرع" maxlength="200" />
            @if (! $editingId)<flux:text>يُنشأ الفرع نشطًا. يمكن تغيير الحالة لاحقًا بصلاحية مستقلة.</flux:text>@endif
            <div class="flex gap-3"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ الفرع</flux:button><flux:button type="button" wire:click="$set('editing', false)">إلغاء</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="confirmingStatus" class="w-full max-w-lg">
            <flux:heading>تأكيد {{ $nextActive ? 'تفعيل' : 'تعطيل' }} الفرع</flux:heading>
            <p class="my-3 text-sm text-slate-600">سيُحفظ تغيير الحالة في سجل التدقيق. التعطيل ممنوع عند وجود تكليفات مستخدمين نشطين، ولا يحذف التاريخ.</p>
            <flux:error name="status" />
            <div class="mt-3 flex gap-3"><flux:button variant="primary" wire:click="changeStatus" wire:loading.attr="disabled">تأكيد التغيير</flux:button><flux:button wire:click="cancelStatus">إلغاء</flux:button></div>
    </flux:modal>
</div>
