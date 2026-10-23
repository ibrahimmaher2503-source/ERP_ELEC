<div class="space-y-6" dir="rtl">
    <x-page.heading title="المستخدمون" description="إدارة حسابات المستخدمين في النظام" />
    @if (session('status'))<flux:callout variant="success">{{ session('status') }}</flux:callout>@endif

    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ([['إجمالي المستخدمين', 'total', 'user-group', 'blue'], ['المستخدمون النشطون', 'active', 'user-circle', 'emerald'], ['المستخدمون غير النشطين', 'inactive', 'user-minus', 'orange']] as [$label, $key, $icon, $tone])
            <x-erp.stat-card :label="$label" :value="$stats[$key]" :icon="$icon" :tone="$tone" />
        @endforeach
    </div>

    <x-ui.panel>
        <div class="erp-filters mb-5 flex flex-wrap items-end gap-3 rounded-xl p-4">
            <div class="min-w-48 flex-1"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="البحث عن مستخدم" placeholder="الاسم أو البريد الإلكتروني..." /></div>
            <flux:select wire:model.live="statusFilter" label="الحالة" class="!w-36"><option value="">جميع الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option></flux:select>
            <flux:select wire:model.live="branchFilter" label="الفرع" class="!w-40"><option value="">جميع الفروع</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</flux:select>
            @if ($canCreate)<flux:button variant="primary" icon="plus" wire:click="create">إضافة مستخدم</flux:button>@endif
        </div>
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="w-full min-w-[760px] text-start text-sm">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="p-4 text-start">#</th><th class="p-4 text-start">اسم المستخدم</th><th class="p-4 text-start">البريد الإلكتروني</th><th class="p-4 text-start">الفرع</th><th class="p-4 text-start">الدور</th><th class="p-4 text-start">الحالة</th><th class="p-4 text-start">الإجراءات</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="p-4">{{ $users->firstItem() + $loop->index }}</td>
                            <td class="p-4"><a class="flex items-center gap-3 font-medium hover:text-blue-700" href="{{ route('organization.users.show', $user->id) }}" wire:navigate><x-erp.user-avatar :user-id="$user->id" :gender="$user->gender" :label="$user->name" /><span>{{ $user->name }}</span></a></td>
                            <td class="p-4"><bdi>{{ $user->email }}</bdi></td><td class="p-4">{{ $user->branch_name ?: 'غير محدد' }}</td><td class="p-4 text-xs text-slate-600">{{ $user->role_names ?: '—' }}</td><td class="p-4"><x-erp.status-badge :active="$user->active" /></td>
                            <td class="p-4"><flux:dropdown><flux:button icon="ellipsis-horizontal" variant="ghost" size="sm" aria-label="إجراءات {{ $user->name }}" /><flux:menu>
                                <flux:menu.item :href="route('organization.users.show', $user->id)" icon="eye" wire:navigate>تفاصيل المستخدم</flux:menu.item>
                                @if ($access->can(auth()->user(), 'M01.user_update', $user))<flux:menu.item wire:click="edit({{ $user->id }})" icon="pencil-square">تحرير البيانات</flux:menu.item>@endif
                                @if ($access->can(auth()->user(), 'M01.user_status', $user))<flux:menu.item wire:click="confirmStatus({{ $user->id }})" icon="power">{{ $user->active ? 'تعطيل الحساب' : 'تفعيل الحساب' }}</flux:menu.item>@endif
                            </flux:menu></flux:dropdown></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-8"><x-ui.empty-state title="لا يوجد مستخدمون للعرض" description="عدّل معايير البحث أو أضف مستخدمًا بصلاحية مناسبة." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 border-t border-slate-100 pt-4">{{ $users->links() }}</div>
    </x-ui.panel>

    <flux:modal wire:model="editing" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
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
