<div id="{{ $historyOnly ? 'permissions-history' : 'user-access' }}" class="scroll-mt-24 space-y-5" dir="rtl">
    @unless ($historyOnly)
    <div class="flex flex-wrap gap-2">
        <flux:button :href="route('organization.roles')" icon="shield-check" class="erp-action-positive" wire:navigate>إدارة الأدوار</flux:button>
        <flux:button :href="route('organization.permissions')" icon="key" class="erp-action-positive" wire:navigate>إدارة الصلاحيات</flux:button>
        <flux:button :href="route('organization.users.show', $user).'#permissions-history'" icon="clock" wire:navigate>سجل الأدوار ونطاقات الوصول</flux:button>
    </div>
    <x-ui.validation-summary /><flux:error name="assignment" />
    @if ($roles->isEmpty())
        <flux:callout variant="warning" heading="لا توجد أدوار مسندة لهذا الحساب">الحساب يستفيد فقط من المنح المباشرة الحالية. تعديل مصفوفة أي دور لن يؤثر عليه حتى يتم إسناد ذلك الدور إليه.</flux:callout>
    @endif
    @if ($self)
        <flux:callout>لا يمكن تعديل أدوار أو نطاقات وصول حسابك الحالي من هذه الصفحة.</flux:callout>
    @elseif (! $targetActive)
        <flux:callout>الحساب غير نشط؛ تعيين أدوار أو نطاقات جديدة معطل. يمكن مراجعة المنح الحالية أو سحبها.</flux:callout>
    @endif
    <x-erp.section-card title="نطاقات المستودعات">
        <form wire:submit="grantWarehouse" class="flex flex-wrap items-end gap-3">
            <flux:select wire:model="selectedWarehouse" label="المستودع" :disabled="$self || !$targetActive"><option value="">اختر المستودع بعد منح نطاق فرعه</option>@foreach($warehouses as $w)<option value="{{ $w['id'] }}" @disabled(!$w['active'])>{{ $w['name'] }}</option>@endforeach</flux:select>
            <flux:button type="submit" icon="plus" :disabled="$self || !$targetActive">منح النطاق</flux:button>
        </form><flux:error name="selectedWarehouse" />
        <div class="mt-3 flex flex-wrap gap-2">@foreach($warehouses->whereIn('id',$warehouseScopes) as $w)<flux:button size="sm" icon="x-mark" wire:click="revokeWarehouse({{ $w['id'] }})" :disabled="$self" wire:confirm="سحب نطاق المستودع؟">{{ $w['name'] }}</flux:button>@endforeach</div>
    </x-erp.section-card>
    @unless($targetVerified)<flux:callout>البريد الإلكتروني غير مؤكد؛ استخدام الصلاحيات المعروضة يتطلب تأكيد البريد وتسجيل الدخول.</flux:callout>@endunless

    <x-erp.section-card title="الأدوار ونطاقات الوصول">
        <div class="grid gap-5 lg:grid-cols-2">
            <x-ui.panel class="!p-4">
                <flux:heading size="lg">الأدوار المسندة</flux:heading>
                @if ($availableRoles->isNotEmpty())
                    <form wire:submit="assignRole" class="mt-4 flex flex-wrap items-end gap-3">
                        <div class="min-w-44 flex-1"><flux:select wire:model="selectedRole" label="إضافة دور" :disabled="$self || ! $targetActive"><option value="">اختر دورًا</option>@foreach ($availableRoles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</flux:select></div>
                        <flux:button type="submit" variant="primary" icon="user-plus" :disabled="$self || ! $targetActive" wire:loading.attr="disabled">إسناد الدور</flux:button>
                    </form>
                @else
                    <p class="mt-4 text-sm text-slate-500">لا توجد أدوار متاحة للإسناد.</p>
                @endif
                <flux:error name="selectedRole" />
                <div class="mt-4 divide-y divide-slate-100">
                    @forelse ($roles as $role)
                        <div wire:key="access-role-{{ $role->id }}" class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0"><p class="font-medium">{{ $role->name }}</p><p class="mt-1 text-xs text-slate-500">إسناد دور فقط · لا يحدد نطاق فرع أو مخزن</p></div>
                            <flux:button variant="danger" size="sm" icon="user-minus" :disabled="$self" wire:click="revokeRole({{ $role->id }})" wire:confirm="هل تريد إزالة هذا الدور من المستخدم؟">إزالة</flux:button>
                        </div>
                    @empty
                        <p class="py-3 text-sm text-slate-500">لا توجد أدوار مسندة لهذا المستخدم.</p>
                    @endforelse
                </div>
            </x-ui.panel>

            <x-ui.panel class="!p-4">
                <flux:heading size="lg">نطاقات الفروع</flux:heading>
                @if ($branches->isNotEmpty())
                    <form wire:submit="grantScope" class="mt-4 flex flex-wrap items-end gap-3">
                        <div class="min-w-44 flex-1"><flux:select wire:model="selectedBranch" label="إضافة فرع" :disabled="$self || ! $targetActive"><option value="">اختر فرعًا</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}" @disabled(! $branch->active)>{{ $branch->name }}{{ $branch->active ? '' : ' · غير نشط' }}</option>@endforeach</flux:select></div>
                        <flux:button type="submit" variant="primary" icon="building-office-2" :disabled="$self || ! $targetActive" wire:loading.attr="disabled">منح نطاق الفرع</flux:button>
                    </form>
                @else
                    <p class="mt-4 text-sm text-slate-500">لا توجد فروع متاحة.</p>
                @endif
                <flux:error name="selectedBranch" />
                <div class="mt-4 divide-y divide-slate-100">
                    @forelse ($scopes as $scope)
                        <div wire:key="branch-scope-{{ $scope->id }}" class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="flex min-w-0 flex-wrap items-center gap-2"><span class="font-medium">{{ $scope->name }}</span><x-erp.status-badge :active="$scope->active" /></div>
                            <flux:button variant="danger" size="sm" icon="x-mark" :disabled="$self" wire:click="revokeScope({{ $scope->id }})" wire:confirm="هل تريد سحب نطاق هذا الفرع؟">سحب النطاق</flux:button>
                        </div>
                    @empty
                        <p class="py-3 text-sm text-slate-500">لا توجد نطاقات فروع مسجلة.</p>
                    @endforelse
                </div>
            </x-ui.panel>
        </div>

        <div class="mt-5 grid gap-5 lg:grid-cols-2">
            <x-ui.panel class="!p-4">
                <flux:heading size="lg">المنح المباشرة</flux:heading>
                <p class="mt-1 text-xs text-slate-500">هذه المنح مستقلة عن الأدوار المسندة.</p>
                <div class="mt-3 divide-y divide-slate-100">
                    @forelse ($directGrants as $grant)
                        <div wire:key="direct-grant-{{ $grant->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0"><p class="font-medium">{{ $grant->label ?? $grant->permission_code }}</p><p class="mt-1 text-xs text-slate-500">{{ $grant->branch_name ?: 'على مستوى الشركة' }}</p></div>
                            <flux:button variant="danger" size="sm" icon="x-mark" :disabled="$self" wire:click="revokeDirect({{ $grant->id }})" wire:confirm="هل تريد سحب هذا المنح المباشر؟">سحب المنح</flux:button>
                        </div>
                    @empty
                        <p class="py-3 text-sm text-slate-500">لا توجد منح مباشرة مسجلة.</p>
                    @endforelse
                </div>
            </x-ui.panel>

            <x-ui.panel class="!p-4">
                <flux:heading size="lg">الوصول الفعلي</flux:heading>
                <p class="mt-1 text-xs text-slate-500">الأدوار تحدد الصلاحيات، ونطاق الفرع يتطلب تسجيلًا مستقلًا. المنح المباشرة معروضة بصورة منفصلة.</p>
                <div class="mt-3 divide-y divide-slate-100">
                    @forelse ($effective as $index => $permission)
                        <div wire:key="effective-access-{{ $index }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <p class="font-medium">{{ $permission['label'] }}</p>
                            <div class="flex flex-wrap justify-end gap-1.5">
                                @if ($permission['scope'] === 'company')
                                    <span class="rounded-md bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">الشركة</span>
                                @elseif ($permission['scope'] === 'branch')
                                    @forelse ($permission['branches'] as $branchName)<span class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800">{{ $branchName }}</span>@empty<span class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600">لا يوجد نطاق فرع</span>@endforelse
                                @else
                                    <span class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600">{{ $permission['scope'] === 'warehouse' ? 'المخزن' : 'نطاق آخر' }}</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="py-3 text-sm text-slate-500">لا توجد صلاحيات فعالة لهذا المستخدم.</p>
                    @endforelse
                </div>
            </x-ui.panel>
        </div>
    </x-erp.section-card>

    @endunless

    @if ($historyOnly)
    <x-erp.section-card title="سجل تغييرات الوصول">
        <p class="mb-3 text-xs text-slate-500">آخر 30 حدث وصول مطابقًا لهذا الحساب أو أدواره الحالية.</p>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600">تعديلات مصفوفة الدور تسجل تغييرات على الدور المشترك، ولا تثبت أن هذا المستخدم كان عضوًا فيه وقت التغيير.</p>
            <flux:button :href="route('organization.users.show', $user).'#user-access'" icon="arrow-right" wire:navigate>العودة إلى نطاقات الوصول</flux:button>
        </div>
        <x-erp.audit-timeline :events="$events" />
    </x-erp.section-card>
    @endif
</div>
