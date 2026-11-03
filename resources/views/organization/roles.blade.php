<div data-erp-archetype="list" class="rbac-workspace space-y-5" dir="rtl">
    <x-page.heading :title="$catalogOnly ? 'كتالوج الصلاحيات' : 'الأدوار والصلاحيات'" :description="$catalogOnly ? 'عرض الصلاحيات ونطاقاتها المسجلة بالنظام' : 'إدارة الأدوار ومصفوفة الصلاحيات'" :breadcrumbs="['التنظيم', 'الأدوار والصلاحيات']">
        <x-slot:actions>
            @if ($catalogOnly)
                <flux:button :href="route('organization.roles')" icon="shield-check" wire:navigate>الأدوار ومصفوفة الصلاحيات</flux:button>
            @else
                <flux:button variant="primary" icon="plus" wire:click="startCreatingRole">إضافة دور جديد</flux:button>
                <flux:button :href="route('organization.permissions')" icon="key" wire:navigate>كتالوج الصلاحيات</flux:button>
            @endif
        </x-slot:actions>
    </x-page.heading>
    @if (session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif

    @if ($catalogOnly)
        <x-erp.section-card title="كتالوج الصلاحيات">
            <p class="mb-4 text-sm text-slate-500">لإدارة مصفوفة دور، انتقل إلى الأدوار وافتح الدور المطلوب.</p>
            @if ($catalog->isNotEmpty())
                <div class="space-y-5">
                    @foreach ($catalog->groupBy('module') as $module => $permissions)
                        <section wire:key="catalog-{{ \Illuminate\Support\Str::slug($module) }}">
                            <h2 class="mb-3 text-sm font-bold text-slate-700">{{ $module }}</h2>
                            <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($permissions as $permission)
                                    <div wire:key="catalog-permission-{{ $permission->code }}" class="flex min-w-0 items-start justify-between gap-3 rounded-lg border border-slate-100 p-3">
                                        <div class="min-w-0"><p class="text-sm font-medium">{{ $permission->label }}</p><p class="mt-1 break-all text-xs text-slate-500"><bdi>{{ $permission->code }}</bdi></p></div>
                                        <span @class(['shrink-0 rounded-md px-2 py-1 text-[11px] font-semibold', 'bg-blue-50 text-blue-700' => $permission->scope === 'company', 'bg-emerald-50 text-emerald-800' => $permission->scope === 'branch', 'bg-violet-50 text-violet-800' => $permission->scope === 'warehouse'])>{{ ['company' => 'الشركة', 'branch' => 'الفرع', 'warehouse' => 'المخزن'][$permission->scope] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-slate-500">كتالوج الصلاحيات غير متاح.</p>
            @endif
        </x-erp.section-card>
    @else
        @if ($creatingRole)
            <x-erp.section-card title="إضافة دور جديد" icon="user-group" wire:key="create-role-form">
                <form wire:submit="createRole" class="max-w-2xl space-y-4">
                    <flux:input wire:model="roleName" label="اسم الدور" placeholder="مثال: مسؤول متابعة التوريدات" required maxlength="200" autofocus />
                    <p class="text-sm leading-7 text-slate-600">يبدأ الدور بدون صلاحيات أو مستخدمين. بعد الإنشاء، حدد صلاحياته من المصفوفة. إسناد الدور للمستخدمين من تفاصيل المستخدم.</p>
                    <div class="flex flex-wrap gap-3">
                        <flux:button type="submit" variant="primary" icon="plus" wire:loading.attr="disabled" wire:target="createRole">إنشاء الدور وتحديد صلاحياته</flux:button>
                        <flux:button type="button" wire:click="$set('creatingRole', false)" wire:loading.attr="disabled" wire:target="createRole">إلغاء</flux:button>
                    </div>
                </form>
            </x-erp.section-card>
        @endif
        <div class="erp-kpi-summary grid gap-3 sm:grid-cols-3">
            <x-erp.stat-card label="إجمالي الصلاحيات" :value="$stats['permissions']" icon="shield-check" tone="emerald" />
            <x-erp.stat-card label="إجمالي الأدوار" :value="$stats['roles']" icon="user-group" tone="blue" />
            <x-erp.stat-card label="إجمالي المستخدمين" :value="$stats['users']" icon="users" tone="blue" />
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,0.42fr)_minmax(0,0.58fr)]">
            <section class="order-2 min-w-0 xl:order-1">
                @if ($selectedRole)
                    @livewire(\App\Modules\Organization\Presentation\RoleDetailsPage::class, ['role' => $selectedRole, 'embedded' => true], key('inline-role-'.$selectedRole))
                @else
                    <x-ui.panel class="min-h-48">
                        <x-ui.empty-state title="اختر دورًا" description="ستظهر مصفوفة الصلاحيات وأعضاء الدور هنا." />
                    </x-ui.panel>
                @endif
            </section>

            <section class="order-1 min-w-0 xl:order-2">
                <x-ui.panel class="!p-0 overflow-hidden">
                    <div class="border-b border-slate-100 p-4">
                        <flux:heading size="lg" class="!mb-4 !font-bold">الأدوار</flux:heading>
                        <x-ui.filters id="role-filters" target="search,scopeFilter" :reset="['search' => '', 'scopeFilter' => '']">
                            <x-slot:search><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="البحث عن دور" placeholder="اسم الدور..." /></x-slot:search>
                            <flux:select wire:model.live="scopeFilter" label="نطاق الصلاحية">
                                <option value="">كل النطاقات</option><option value="company">الشركة</option><option value="branch">الفرع</option><option value="warehouse">المخزن</option>
                            </flux:select>
                        </x-ui.filters>
                    </div>
                    <div class="overflow-x-auto" wire:loading.class="opacity-60">
                        <x-ui.responsive-table label="السجلات المعروضة"><table role="table" class="w-full min-w-[440px] text-start text-xs sm:text-sm">
                            <thead role="rowgroup" class="bg-slate-50 text-slate-500"><tr role="row"><th role="columnheader" class="px-3 py-3 text-start">#</th><th role="columnheader" class="px-3 py-3 text-start">الدور</th><th role="columnheader" class="px-3 py-3 text-start">الصلاحيات</th><th role="columnheader" class="px-3 py-3 text-start">المستخدمون</th><th role="columnheader" class="px-3 py-3 text-start">النوع</th><th role="columnheader" class="px-3 py-3 text-start">الإجراء</th></tr></thead>
                            <tbody role="rowgroup" class="divide-y divide-slate-100">
                                @forelse ($roles as $role)
                                    @php($isSelected = (int) ($selectedRole ?? 0) === (int) $role->id)
                                    <tr role="row" wire:key="role-{{ $role->id }}" @class(['bg-blue-50' => $isSelected, 'hover:bg-slate-50' => ! $isSelected])>
                                        <td role="cell" data-label="الصف" class="px-3 py-3 text-slate-500"><div>{{ $roles->firstItem() + $loop->index }}</div></td>
                                        <td role="cell" data-label="الدور" class="px-3 py-3"><div><div class="font-semibold">{{ $role->name }}</div><div class="mt-1 break-all text-[11px] text-slate-500"><bdi>{{ $role->code }}</bdi></div></div></td>
                                        <td role="cell" data-label="الصلاحيات" class="px-3 py-3 tabular-nums"><div>{{ $role->permission_count }}</div></td>
                                        <td role="cell" data-label="المستخدمون" class="px-3 py-3 tabular-nums"><div>{{ $role->user_count }}</div></td>
                                        <td role="cell" data-label="النوع" class="px-3 py-3"><div>@if ($role->protected)<span class="whitespace-nowrap rounded-md bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-800">محمي</span>@elseif(str_starts_with($role->code, 'Custom_'))<span class="whitespace-nowrap rounded-md bg-blue-50 px-2 py-1 text-[10px] text-blue-700">مخصص</span>@else<span class="whitespace-nowrap rounded-md bg-slate-100 px-2 py-1 text-[10px] text-slate-600">أساسي</span>@endif</div></td>
                                        <td role="cell" data-label="الإجراء" class="px-3 py-3"><div><div class="flex items-center gap-1.5">
                                            <flux:button :variant="$isSelected ? 'primary' : 'ghost'" size="sm" icon="adjustments-horizontal" wire:click="selectRole({{ $role->id }})" aria-label="اختيار {{ $role->name }}" />
                                            <flux:button :href="route('organization.roles.show', $role->id)" variant="ghost" size="sm" icon="arrow-top-right-on-square" aria-label="تفاصيل {{ $role->name }}" wire:navigate />
                                        </div></div></td>
                                    </tr>
                                @empty
                                    <tr role="row"><td colspan="6" class="p-6"><x-ui.empty-state title="لا توجد أدوار للعرض" description="تحقق من البحث أو نطاق الصلاحية." /></td></tr>
                                @endforelse
                            </tbody>
                        </table></x-ui.responsive-table>
                    </div>
                    <div class="border-t border-slate-100 px-4 py-3">{{ $roles->links() }}</div>
                </x-ui.panel>
                <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50/60 p-4 text-xs leading-6 text-blue-700">
                    <p class="font-semibold">ملاحظة</p>
                    <p>يمكن إنشاء أدوار مخصصة وإسناد أكثر من دور للمستخدم. تُجمع صلاحيات الأدوار، وتُحدد نطاقات الفروع والمخازن بصورة مستقلة. الدور الجديد لا يمنح أي صلاحية تلقائيًا.</p>
                </div>
            </section>
        </div>
    @endif
</div>
