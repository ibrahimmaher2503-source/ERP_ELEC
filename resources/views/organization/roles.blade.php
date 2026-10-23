<div class="rbac-workspace space-y-5" dir="rtl">
    <x-page.heading :title="$catalogOnly ? 'كتالوج الصلاحيات' : 'الأدوار والصلاحيات'" :description="$catalogOnly ? 'عرض الصلاحيات ونطاقاتها المسجلة بالنظام' : 'إدارة الأدوار ومصفوفة الصلاحيات'" :breadcrumbs="['التنظيم', 'الأدوار والصلاحيات']">
        <x-slot:actions>
            @if ($catalogOnly)
                <flux:button :href="route('organization.roles')" icon="shield-check" wire:navigate>الأدوار ومصفوفة الصلاحيات</flux:button>
            @else
                <flux:button :href="route('organization.permissions')" icon="key" wire:navigate>كتالوج الصلاحيات</flux:button>
            @endif
        </x-slot:actions>
    </x-page.heading>
    @if (session('status'))<flux:callout variant="success">{{ session('status') }}</flux:callout>@endif

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
        <div class="grid gap-3 sm:grid-cols-3">
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
                        <div class="flex flex-wrap items-center gap-3">
                            <flux:heading size="lg" class="!font-bold">الأدوار</flux:heading>
                            <div class="min-w-40 flex-1"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" aria-label="البحث عن دور" placeholder="ابحث عن دور..." /></div>
                            <flux:select wire:model.live="scopeFilter" aria-label="نطاق الصلاحية" class="!w-32">
                                <option value="">كل النطاقات</option><option value="company">الشركة</option><option value="branch">الفرع</option><option value="warehouse">المخزن</option>
                            </flux:select>
                        </div>
                    </div>
                    <div class="overflow-x-auto" wire:loading.class="opacity-60">
                        <table class="w-full min-w-[440px] text-start text-xs sm:text-sm">
                            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-3 text-start">#</th><th class="px-3 py-3 text-start">الدور</th><th class="px-3 py-3 text-start">الصلاحيات</th><th class="px-3 py-3 text-start">المستخدمون</th><th class="px-3 py-3 text-start">النوع</th><th class="px-3 py-3 text-start">الإجراء</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($roles as $role)
                                    @php($isSelected = (int) ($selectedRole ?? 0) === (int) $role->id)
                                    <tr wire:key="role-{{ $role->id }}" @class(['bg-emerald-50/70' => $isSelected, 'hover:bg-slate-50' => ! $isSelected])>
                                        <td class="px-3 py-3 text-slate-500">{{ $roles->firstItem() + $loop->index }}</td>
                                        <td class="px-3 py-3"><div class="font-semibold">{{ $role->name }}</div><div class="mt-1 break-all text-[11px] text-slate-500"><bdi>{{ $role->code }}</bdi></div></td>
                                        <td class="px-3 py-3 tabular-nums">{{ $role->permission_count }}</td>
                                        <td class="px-3 py-3 tabular-nums">{{ $role->user_count }}</td>
                                        <td class="px-3 py-3">@if ($role->protected)<span class="whitespace-nowrap rounded-md bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-800">ثابت</span>@else<span class="whitespace-nowrap rounded-md bg-slate-100 px-2 py-1 text-[10px] text-slate-600">معتمد</span>@endif</td>
                                        <td class="px-3 py-3"><div class="flex items-center gap-1.5">
                                            <flux:button :variant="$isSelected ? 'primary' : 'ghost'" size="sm" icon="adjustments-horizontal" wire:click="selectRole({{ $role->id }})" aria-label="اختيار {{ $role->name }}" />
                                            <flux:button :href="route('organization.roles.show', $role->id)" variant="ghost" size="sm" icon="arrow-top-right-on-square" aria-label="تفاصيل {{ $role->name }}" wire:navigate />
                                        </div></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="p-6"><x-ui.empty-state title="لا توجد أدوار للعرض" description="تحقق من البحث أو نطاق الصلاحية." /></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-slate-100 px-4 py-3">{{ $roles->links() }}</div>
                </x-ui.panel>
                <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50/60 p-4 text-xs leading-6 text-blue-700">
                    <p class="font-semibold">ملاحظة</p>
                    <p>يمكن إسناد أكثر من دور للمستخدم. تُجمع صلاحيات الأدوار، وتُحدد نطاقات الفروع بصورة مستقلة. الأدوار الـ14 ثابتة حسب القائمة المعتمدة.</p>
                </div>
            </section>
        </div>
    @endif
</div>
