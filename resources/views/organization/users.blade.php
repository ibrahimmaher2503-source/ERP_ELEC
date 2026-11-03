<div data-erp-archetype="list" class="space-y-6" dir="rtl">
    <x-page.heading title="المستخدمون" description="إدارة حسابات المستخدمين في النظام" ><x-slot:actions>@if ($canCreate)<flux:button variant="primary" icon="plus" wire:click="create">إضافة مستخدم</flux:button>@endif</x-slot:actions></x-page.heading>
    @if (session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif

    <div class="erp-kpi-summary grid gap-3 sm:grid-cols-3">
        @foreach ([['إجمالي المستخدمين', 'total', 'user-group', 'blue'], ['المستخدمون النشطون', 'active', 'user-circle', 'emerald'], ['المستخدمون غير النشطين', 'inactive', 'user-minus', 'orange']] as [$label, $key, $icon, $tone])
            <x-erp.stat-card :label="$label" :value="$stats[$key]" :icon="$icon" :tone="$tone" />
        @endforeach
    </div>

    <x-ui.panel>
        <x-ui.filters id="user-filters" target="search,statusFilter,branchFilter" :reset="['search' => '', 'statusFilter' => '', 'branchFilter' => '']"><x-slot:search><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="البحث عن مستخدم" placeholder="الاسم أو البريد الإلكتروني..." /></x-slot:search><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">

            <flux:select wire:model.live="statusFilter" label="الحالة"><option value="">جميع الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option></flux:select>
            <flux:select wire:model.live="branchFilter" label="الفرع"><option value="">جميع الفروع</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</flux:select>

        </div></x-ui.filters>
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <x-ui.responsive-table label="السجلات المعروضة"><table role="table" class="w-full min-w-[760px] text-start text-sm">
                <thead role="rowgroup" class="bg-slate-50 text-slate-500"><tr role="row"><th role="columnheader" class="p-4 text-start">#</th><th role="columnheader" class="p-4 text-start">اسم المستخدم</th><th role="columnheader" class="p-4 text-start">البريد الإلكتروني</th><th role="columnheader" class="p-4 text-start">الفرع</th><th role="columnheader" class="p-4 text-start">الدور</th><th role="columnheader" class="p-4 text-start">الحالة</th><th role="columnheader" class="p-4 text-start">الإجراءات</th></tr></thead>
                <tbody role="rowgroup" class="divide-y divide-slate-100">
                    @forelse ($users as $user)
                        <tr role="row" wire:key="user-{{ $user->id }}">
                            <td role="cell" data-label="الصف" class="p-4"><div>{{ $users->firstItem() + $loop->index }}</div></td>
                            <td role="cell" data-label="اسم المستخدم" class="p-4"><div><a class="flex items-center gap-3 font-medium hover:text-blue-700" href="{{ route('organization.users.show', $user->id) }}" wire:navigate><x-erp.user-avatar :user-id="$user->id" :gender="$user->gender" :label="$user->name" /><span>{{ $user->name }}</span></a></div></td>
                            <td role="cell" data-label="البريد الإلكتروني" class="p-4"><div><bdi>{{ $user->email }}</bdi></div></td><td role="cell" data-label="الفرع" class="p-4"><div>{{ $user->branch_name ?: 'غير محدد' }}</div></td><td role="cell" data-label="الدور" class="p-4 text-xs text-slate-600"><div>{{ $user->role_names ?: '—' }}</div></td><td role="cell" data-label="الحالة" class="p-4"><div><x-erp.status-badge :active="$user->active" /></div></td>
                            <td role="cell" data-label="الإجراءات" class="p-4"><div><flux:dropdown><flux:button icon="ellipsis-horizontal" variant="ghost" size="sm" aria-label="إجراءات {{ $user->name }}" /><flux:menu>
                                <flux:menu.item :href="route('organization.users.show', $user->id)" icon="eye" wire:navigate>تفاصيل المستخدم</flux:menu.item>
                                @if ($access->can(auth()->user(), 'M01.user_update', $user))<flux:menu.item wire:click="edit({{ $user->id }})" icon="pencil-square">تحرير البيانات</flux:menu.item>@endif
                                @if ($access->can(auth()->user(), 'M01.user_status', $user))<flux:menu.item wire:click="confirmStatus({{ $user->id }})" icon="power">{{ $user->active ? 'تعطيل الحساب' : 'تفعيل الحساب' }}</flux:menu.item>@endif
                            </flux:menu></flux:dropdown></div></td>
                        </tr>
                    @empty
                        <tr role="row"><td colspan="7" class="p-8"><x-ui.empty-state title="لا يوجد مستخدمون للعرض" description="عدّل معايير البحث أو أضف مستخدمًا بصلاحية مناسبة." /></td></tr>
                    @endforelse
                </tbody>
            </table></x-ui.responsive-table>
        </div>
        <div class="mt-4 border-t border-slate-100 pt-4">{{ $users->links() }}</div>
    </x-ui.panel>

    <flux:modal wire:model="editing" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <x-ui.validation-summary />
            <flux:heading size="lg">{{ $editingId ? 'تحرير بيانات المستخدم' : 'إضافة مستخدم' }}</flux:heading>
            <x-erp.user-fields :branches="$branches" :creating="! $editingId" />
            <div class="rounded-lg border border-emerald-100 bg-emerald-50/50 p-3 text-sm">
                <p class="font-semibold">الأدوار ونطاقات الوصول</p>
                @if($editingId)
                    <flux:link :href="route('organization.users.show', $editingId).'#user-access'" wire:navigate>إدارة الأدوار والفروع من تفاصيل المستخدم</flux:link>
                @else
                    <p class="mt-1 text-xs text-slate-600">احفظ الحساب أولًا، ثم افتح تفاصيله لإسناد الأدوار ونطاقات الوصول بتفويض إدارة الأمان.</p>
                @endif
            </div>
            @unless ($editingId)<flux:text>يُنشأ الحساب نشطًا. يمكن تغيير حالته لاحقًا بصلاحية مستقلة.</flux:text>@endunless
            <div class="flex gap-3"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ المستخدم</flux:button><flux:button type="button" wire:click="cancel">إلغاء</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="confirmingStatus" class="w-full max-w-lg">
        <flux:heading>تأكيد {{ $nextActive ? 'تفعيل' : 'تعطيل' }} الحساب</flux:heading>
        <p class="my-3 text-sm text-slate-600">سيُحفظ تغيير حالة {{ $statusName }} في سجل التدقيق، مع الاحتفاظ بسجل الحساب.</p>
        <flux:error name="status" />
        <div class="mt-3 flex gap-3"><flux:button variant="primary" wire:click="changeStatus" wire:loading.attr="disabled">تأكيد التغيير</flux:button><flux:button wire:click="cancelStatus">إلغاء</flux:button></div>
    </flux:modal>
</div>
