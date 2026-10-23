<div class="space-y-6">
    <x-page.heading title="الفروع" description="إدارة فروع الشركة">
        @if ($access->allows(auth()->user(), 'M01.company_view') && \Illuminate\Support\Facades\Route::has('organization.company'))
            <x-slot:actions><flux:button :href="route('organization.company')" icon="building-office" wire:navigate>بيانات الشركة</flux:button></x-slot:actions>
        @endif
    </x-page.heading>
    @if (session('status'))
        <flux:callout variant="success">{{ session('status') }}</flux:callout>
    @endif
    <div class="grid gap-3 sm:grid-cols-3">
        @foreach (['total' => ['إجمالي الفروع', 'building-office-2', 'blue'], 'active' => ['الفروع النشطة', 'home', 'emerald'], 'inactive' => ['الفروع غير النشطة', 'clock', 'orange']] as $key => [$label, $icon, $color])
            <x-erp.stat-card :label="$label" :value="$stats[$key]" :icon="$icon" :tone="$color" />
        @endforeach
    </div>
    <x-ui.panel>
        <div class="erp-filters mb-5 flex flex-wrap items-end gap-3 rounded-xl p-4">
            <div class="min-w-40 flex-1"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="البحث عن فرع" placeholder="اسم الفرع أو الكود..." /></div>
            <flux:select wire:model.live="statusFilter" label="الحالة" class="!w-36"><option value="">كل الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option></flux:select>
            @if ($canCreate)<flux:button variant="primary" icon="plus" wire:click="create">إضافة فرع</flux:button>@endif
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="w-full min-w-[520px] text-start text-sm">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="p-4 text-start">#</th><th class="p-4 text-start">اسم الفرع</th><th class="p-4 text-start">الكود</th><th class="p-4 text-start">الحالة</th><th class="p-4 text-start">الإجراءات</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($branches as $branch)
                        <tr wire:key="branch-{{ $branch->id }}"><td class="p-4">{{ $branches->firstItem() + $loop->index }}</td><td class="p-4"><a class="font-medium hover:text-blue-700" href="{{ route('organization.branches.show', $branch->id) }}" wire:navigate>{{ $branch->name }}</a></td><td class="p-4"><bdi>{{ $branch->code }}</bdi></td><td class="p-4"><x-erp.status-badge :active="$branch->active" /></td><td class="p-4">
                            <flux:dropdown><flux:button icon="ellipsis-horizontal" variant="ghost" size="sm" aria-label="إجراءات {{ $branch->name }}" /><flux:menu>
                                <flux:menu.item :href="route('organization.branches.show', $branch->id)" icon="eye" wire:navigate>تفاصيل الفرع</flux:menu.item>
                                @if ($access->branchAllows(auth()->user(), 'M01.branch_update', $branch->id))<flux:menu.item wire:click="edit({{ $branch->id }})" icon="pencil-square">تعديل</flux:menu.item>@endif
                                @if ($access->branchAllows(auth()->user(), 'M01.branch_status', $branch->id))<flux:menu.item wire:click="confirmStatus({{ $branch->id }})" icon="power">{{ $branch->active ? 'تعطيل الفرع' : 'تفعيل الفرع' }}</flux:menu.item>@endif
                            </flux:menu></flux:dropdown>
                        </td></tr>
                    @empty
                        <tr><td colspan="5" class="p-8"><x-ui.empty-state title="لا توجد فروع للعرض" description="أضف فرعًا بصلاحية مناسبة أو عدّل معايير البحث." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 border-t border-slate-100 pt-4">{{ $branches->links() }}</div>
        <p class="mt-3 text-xs text-slate-500">الإحصاءات والنتائج تخص الفروع المسموح لك بعرضها.</p>
    </x-ui.panel>
    <flux:modal wire:model="editing" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'تعديل الفرع' : 'إضافة فرع' }}</flux:heading>
            <flux:input wire:model="form.code" label="كود الفرع" description="كود فريد للفرع، بحد أقصى 50 حرفًا." required maxlength="50" />
            <flux:input wire:model="form.name" label="اسم الفرع" required maxlength="200" />
            <flux:textarea wire:model="form.address" label="العنوان" maxlength="2000" />
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
