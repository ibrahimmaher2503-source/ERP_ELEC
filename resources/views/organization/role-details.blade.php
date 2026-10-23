@php
    $permissionByCode = $catalog->flatten(1)->keyBy('code');
    $selectedPermissionRecords = collect($selectedPermissions)->map(fn ($code) => $permissionByCode->get($code))->filter();
    $scopeLabels = ['company' => 'الشركة', 'branch' => 'الفرع', 'warehouse' => 'المخزن'];
@endphp

<div @class(['rbac-role-details', 'space-y-3 rounded-xl border border-[#E5E8EE] bg-white p-4' => $embedded, 'space-y-5' => ! $embedded]) dir="rtl">
    @if($record->code==='Administrator')<flux:callout variant="info" heading="جميع الصلاحيات مفعلة تلقائيًا">يحصل مدير النظام على جميع الصلاحيات المسجلة حاليًا وأي صلاحيات تضاف لاحقًا. لا تحتاج إلى تفعيل صلاحيات كل وحدة يدويًا.</flux:callout>@endif
    @if ($members->isEmpty())
        <flux:callout variant="warning" heading="هذا الدور غير مسند لأي مستخدم">
            حفظ الصلاحيات هنا يحدّث الدور فقط. لتصبح فعالة، أسند الدور للمستخدم من تفاصيل المستخدم ← صلاحيات الوصول. اسم الحساب لا يعني أنه يحمل دورًا بنفس الاسم.
            <a href="{{ route('organization.users') }}" wire:navigate class="font-semibold underline">إدارة المستخدمين</a>
        </flux:callout>
    @else
        <p class="text-xs text-slate-500">تسري صلاحيات هذا الدور على {{ $members->count() }} مستخدم مرتبط به، مع مراعاة نطاقات الوصول.</p>
    @endif
    @if ($embedded)
        <h2 class="font-bold text-[#202B3D]">صلاحيات الدور</h2>
        <x-ui.panel class="!p-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-bold text-[#202B3D]">{{ $record->name }}</h2>
                        @if ($record->protected)<span class="rounded-full bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800">دور ثابت</span>@endif
                    </div>
                    <p class="mt-1 text-xs text-slate-500"><bdi>{{ $record->code }}</bdi></p>
                </div>
                <flux:button :href="route('organization.roles.show', $record->id)" size="sm" icon="arrow-top-right-on-square" wire:navigate>تفاصيل الدور</flux:button>
            </div>
        </x-ui.panel>
    @else
        <x-page.heading :title="$record->name" :breadcrumbs="['التنظيم', 'الأدوار والصلاحيات', $record->name]">
            <x-slot:actions><flux:button :href="route('organization.roles')" wire:navigate>العودة للأدوار</flux:button></x-slot:actions>
        </x-page.heading>

        <x-ui.panel>
            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-5">
                <div><p class="text-xs font-medium text-slate-500">اسم الدور</p><p class="mt-1 font-semibold text-[#202B3D]">{{ $record->name }}</p></div>
                <div><p class="text-xs font-medium text-slate-500">رمز الدور</p><p class="mt-1 break-all text-sm"><bdi>{{ $record->code }}</bdi></p></div>
                <div><p class="text-xs font-medium text-slate-500">النوع</p><p class="mt-1">@if ($record->protected)<span class="rounded-full bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800">دور ثابت</span>@else<span class="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-medium text-slate-600">دور مسجل</span>@endif</p></div>
                <div><p class="text-xs font-medium text-slate-500">إجمالي الصلاحيات المختارة</p><p class="mt-1 text-2xl font-bold tabular-nums">{{ $selectedPermissionRecords->count() }}</p></div>
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($scopeLabels as $scope => $label)
                        <span @class(['rounded-full px-2 py-1 text-[11px] font-semibold tabular-nums', 'bg-blue-50 text-blue-700' => $scope === 'company', 'bg-emerald-50 text-emerald-800' => $scope === 'branch', 'bg-violet-50 text-violet-800' => $scope === 'warehouse'])>{{ $label }}: {{ $selectedPermissionRecords->where('scope', $scope)->count() }}</span>
                    @endforeach
                </div>
            </div>
        </x-ui.panel>
    @endif

    @if (session('status'))<flux:callout variant="success">{{ session('status') }}</flux:callout>@endif

    <div @class(['grid items-start gap-5', 'lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]' => ! $embedded])>
        <x-ui.panel x-data="{ search: '' }" class="min-w-0 !p-3">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="font-bold text-[#202B3D]">{{ $embedded ? 'تعيين الصلاحيات' : 'صلاحيات الدور' }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $selectedPermissionRecords->count() }} صلاحية مختارة @unless($embedded)· تحفظ التغييرات عند الضغط على زر الحفظ.@endunless</p>
                </div>
                <div class="w-full sm:w-52"><flux:input id="permission-search" x-model.debounce.150ms="search" icon="magnifying-glass" aria-label="البحث في الصلاحيات" placeholder="ابحث عن صلاحية..." /></div>
            </div>

            <div @class(['space-y-3', 'max-h-[400px] overflow-y-auto pe-1' => $embedded]) tabindex="0" role="region" aria-label="قائمة صلاحيات الدور">
                @forelse ($catalog as $module => $permissions)
                    <section wire:key="role-module-{{ \Illuminate\Support\Str::slug($module) }}" class="rounded-lg border border-slate-200 p-3 sm:p-4">
                        <h3 class="mb-2 text-sm font-bold text-slate-800">{{ $module }}</h3>
                        <div @class(['grid gap-x-4', 'md:grid-cols-2' => ! $embedded])>
                            @foreach ($permissions as $permission)
                                <div wire:key="role-permission-{{ $permission->code }}" data-searchable="{{ mb_strtolower($permission->label.' '.$permission->code) }}" x-show="! search.trim() || $el.dataset.searchable.includes(search.trim().toLowerCase())" class="flex min-h-[34px] min-w-0 items-center justify-between gap-2 border-b border-slate-100 py-1 last:border-0">
                                    <div class="min-w-0 flex-1">
                                        <flux:switch :checked="in_array($permission->code, $selectedPermissions, true)" wire:click="togglePermission('{{ $permission->code }}')" :label="$permission->label" :disabled="$permission->locked || ! $canEdit || ($record->code === 'ManagerReadOnly' && ! str_ends_with($permission->code, '_view') && $permission->code !== 'M04.quotation_history')" />
                                        @unless ($embedded)<p class="ms-1 mt-0.5 break-all text-[10px] text-slate-500"><bdi>{{ $permission->code }}</bdi>@if ($permission->locked)<span class="ms-1 text-amber-700">· مقفلة</span>@endif</p>@endunless
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1">
                                        <span @class(['rounded-full px-2 py-0.5 text-[10px] font-semibold', 'bg-blue-50 text-blue-700' => $permission->scope === 'company', 'bg-emerald-50 text-emerald-800' => $permission->scope === 'branch', 'bg-violet-50 text-violet-800' => $permission->scope === 'warehouse'])>{{ $scopeLabels[$permission->scope] }}</span>
                                        @if ($permission->locked)<span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-800">مقفلة</span>@endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @empty
                    <p class="py-3 text-sm text-slate-500">لا توجد صلاحيات في الكتالوج الحالي.</p>
                @endforelse
            </div>

            @if (! $canEdit && $record->code!=='Administrator')<p class="mt-4 text-sm text-slate-500">لا يمكنك تعديل صلاحيات هذا الدور حاليًا.</p>@endif
            <flux:error name="permissions" />
            @if ($canEdit)<div class="mt-4 flex justify-start"><flux:button variant="primary" icon="check" wire:click="savePermissions" wire:loading.attr="disabled">حفظ الصلاحيات</flux:button></div>@endif
        </x-ui.panel>

        @unless ($embedded)
            <div class="space-y-5">
                <x-ui.panel>
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div><h2 class="font-bold text-[#202B3D]">أعضاء الدور</h2><p class="mt-1 text-xs text-slate-500">{{ $members->count() }} مستخدم مسند</p></div>
                    </div>
                    <p class="mb-4 text-xs text-slate-500">تُدار إسنادات الأدوار من صفحات المستخدمين وتفاصيلهم.</p>
                    <div class="divide-y divide-slate-100">
                        @forelse ($members as $member)
                            <div wire:key="role-member-{{ $member->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    @if (app(\App\Modules\Organization\Application\UserAccess::class)->can(auth()->user(), 'M01.user_view', $member))
                                        <a class="font-medium hover:text-blue-700" href="{{ route('organization.users.show', $member->id) }}" wire:navigate>{{ $member->name }}</a>
                                    @else
                                        <span class="font-medium">{{ $member->name }}</span>
                                    @endif
                                    <div class="mt-1 text-sm text-slate-500"><bdi>{{ $member->email }}</bdi></div>
                                </div>
                                <x-erp.status-badge :active="$member->active" />
                            </div>
                        @empty
                            <p class="py-3 text-sm text-slate-500">لا يوجد مستخدمون مسندون إلى هذا الدور.</p>
                        @endforelse
                    </div>
                </x-ui.panel>

                <x-ui.panel>
                    <div class="mb-4"><h2 class="font-bold text-[#202B3D]">سجل تغييرات الصلاحيات</h2><p class="mt-1 text-xs text-slate-500">آخر {{ $audits->count() }} تغيير مسجل</p></div>
                    @forelse ($audits as $audit)
                        @php
                            $beforeSnapshot = is_array($audit->before_snapshot) ? $audit->before_snapshot : (json_decode((string) $audit->before_snapshot, true) ?: []);
                            $afterSnapshot = is_array($audit->after_snapshot) ? $audit->after_snapshot : (json_decode((string) $audit->after_snapshot, true) ?: []);
                            $beforeCodes = collect(data_get($beforeSnapshot, 'permissions', []))->values();
                            $afterCodes = collect(data_get($afterSnapshot, 'permissions', []))->values();
                            $addedCodes = $afterCodes->diff($beforeCodes);
                            $removedCodes = $beforeCodes->diff($afterCodes);
                            $permissionCatalog = $catalog->flatten(1)->keyBy('code');
                            $auditAction = match ($audit->action) {
                                'role.administrator_defaults_synced' => 'تفعيل الصلاحيات الافتراضية لمدير النظام',
                                'role.permissions_changed' => 'تعديل صلاحيات الدور',
                                'role.assigned' => 'إسناد الدور لمستخدم',
                                'role.revoked' => 'إزالة الدور من مستخدم',
                                default => 'تغيير في الدور أو الصلاحيات',
                            };
                        @endphp
                        <article wire:key="role-audit-{{ $loop->index }}" class="border-b border-slate-100 py-4 first:pt-0 last:border-0 last:pb-0">
                            <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-medium">{{ $auditAction }}</p><time class="text-xs text-slate-500" datetime="{{ \Carbon\CarbonImmutable::parse($audit->occurred_at)->utc()->toIso8601String() }}">{{ \Carbon\CarbonImmutable::parse($audit->occurred_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }} · توقيت القاهرة</time></div>
                            @if ($addedCodes->isNotEmpty() || $removedCodes->isNotEmpty())
                                <ul class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($addedCodes as $code)@php($permissionLabel = $permissionCatalog->get($code)?->label ?? $code)<li class="rounded-md bg-emerald-50 px-2.5 py-1 text-xs text-emerald-800">+ {{ $permissionLabel }} <bdi>({{ $code }})</bdi></li>@endforeach
                                    @foreach ($removedCodes as $code)@php($permissionLabel = $permissionCatalog->get($code)?->label ?? $code)<li class="rounded-md bg-red-50 px-2.5 py-1 text-xs text-red-700">− {{ $permissionLabel }} <bdi>({{ $code }})</bdi></li>@endforeach
                                </ul>
                            @else
                                <p class="mt-2 text-sm text-slate-500">لا توجد تغييرات على مفاتيح الصلاحيات في هذا السجل.</p>
                            @endif
                        </article>
                    @empty
                        <p class="text-sm text-slate-500">لا يوجد سجل تغييرات لهذا الدور.</p>
                    @endforelse
                </x-ui.panel>
            </div>
        @endunless
    </div>
    @livewire(\App\Modules\Organization\Presentation\QuotationSelfApprovalSection::class, ['targetType'=>'role','targetId'=>$role], key('quote-self-role-'.$role))
</div>
