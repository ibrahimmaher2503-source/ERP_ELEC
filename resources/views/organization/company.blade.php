<div class="space-y-6" dir="rtl">
    <x-page.heading title="بيانات الشركة" description="معلومات الشركة الرئيسية وإعداداتها.">
        @php
            $actor = auth()->user();
            $organizationAccess = app(\App\Modules\Organization\Application\OrganizationAccess::class);
            $canOpenBranches = $actor && (
                $organizationAccess->allows($actor, 'M01.branch_admin')
                || $organizationAccess->allows($actor, 'M01.branch_create')
                || app(\App\Modules\Organization\Application\PermissionAccess::class)->branchIds($actor, 'M01.branch_view') !== []
            );
        @endphp
        @if ($canOpenBranches && \Illuminate\Support\Facades\Route::has('organization.branches'))
            <x-slot:actions><flux:button :href="route('organization.branches')" icon="building-office-2" wire:navigate>الفروع</flux:button></x-slot:actions>
        @endif
    </x-page.heading>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle">{{ session('status') }}</flux:callout>
    @endif

    <form wire:submit="save" class="space-y-5">
        <x-erp.section-card title="معلومات الشركة">

            <div class="grid items-stretch gap-6 sm:grid-cols-[11rem_minmax(0,1fr)]">
                <div class="space-y-3">
                    <div class="relative flex size-32 max-w-full items-center justify-center overflow-hidden rounded-xl border border-slate-100 bg-slate-50 sm:min-h-56 sm:w-full">
                        @if ($logoUrl)
                            <img src="{{ $logoUrl }}" alt="شعار الشركة" class="size-full object-contain p-3">
                        @else
                            <div class="flex flex-col items-center gap-3 px-3 pb-6 text-slate-400">
                                <span class="flex size-14 items-center justify-center rounded-full bg-white"><flux:icon name="building-office-2" class="size-7" /></span>
                                <span class="text-center text-xs">لا يوجد شعار مسجل</span>
                            </div>
                        @endif
                        @if ($canEdit && ! $editing)
                            <button type="button" wire:click="edit" aria-label="تعديل شعار الشركة" class="absolute bottom-2 end-2 rounded-lg border border-slate-100 bg-white p-2 text-slate-600 shadow-sm focus-visible:outline-2 focus-visible:outline-blue-700"><flux:icon.pencil class="size-4" /></button>
                        @endif
                    </div>

                    @if ($editing && $canEdit)
                        <flux:field>
                            <flux:label>شعار الشركة</flux:label>
                            <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm text-zinc-600 file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-700">
                            <flux:error name="logo" />
                            @if ($logo instanceof \Illuminate\Http\UploadedFile)
                                <flux:text size="sm" class="mt-2">{{ $logo->getClientOriginalName() }}</flux:text>
                            @endif
                            <flux:text size="sm" wire:loading wire:target="logo">جارٍ رفع الملف...</flux:text>
                        </flux:field>
                    @endif
                </div>

                <div class="min-w-0">
                    @if ($editing && $canEdit)
                        <div class="space-y-3">
                            <flux:input wire:model="form.name" label="الاسم التجاري" required />
                            <flux:error name="form.name" />
                            <flux:input wire:model="form.legal_name" label="الاسم القانوني" />
                            <flux:error name="form.legal_name" />
                            <flux:input wire:model="form.commercial_registration" label="السجل التجاري" />
                            <flux:error name="form.commercial_registration" />
                            <flux:input wire:model="form.tax_registration" label="رقم التسجيل الضريبي" />
                            <flux:error name="form.tax_registration" />
                            <flux:input wire:model="form.code" label="كود الشركة" required />
                            <flux:error name="form.code" />
                        </div>
                    @else
                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div class="min-w-0 border-b border-slate-100 pb-4 sm:col-span-2">
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <dt class="text-xs text-slate-500">الاسم التجاري</dt>
                                    <dd class="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-500">كود الشركة: <bdi>{{ $company?->code ?: 'غير مسجل' }}</bdi></dd>
                                </div>
                                <dd class="break-words text-xl font-semibold leading-8 text-[#202B3D]"><bdi>{{ $company?->name ?: 'غير مسجل' }}</bdi></dd>
                            </div>
                            <div class="min-w-0 sm:col-span-2">
                                <dt class="text-xs text-slate-500">الاسم القانوني</dt>
                                <dd @class(['mt-2 break-words text-sm', 'font-medium text-slate-800' => filled($company?->legal_name), 'text-slate-400' => blank($company?->legal_name)])><bdi>{{ $company?->legal_name ?: 'غير مسجل' }}</bdi></dd>
                            </div>
                            @foreach (['commercial_registration' => 'السجل التجاري', 'tax_registration' => 'رقم التسجيل الضريبي'] as $field => $label)
                                <div class="min-w-0 rounded-lg border border-slate-100 bg-slate-50/50 px-4 py-3">
                                    <dt class="text-xs text-slate-500">{{ $label }}</dt>
                                    <dd @class(['mt-2 break-words text-sm', 'font-medium text-slate-800' => filled($company?->$field), 'text-slate-400' => blank($company?->$field)])><bdi>{{ $company?->$field ?: 'غير مسجل' }}</bdi></dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </div>
            </div>
        </x-erp.section-card>

        <x-erp.section-card title="معلومات التواصل">
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([['phone', 'رقم الهاتف', 'phone'], ['email', 'البريد الإلكتروني', 'envelope']] as [$field, $label, $icon])
                    <div class="flex min-w-0 items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                            <flux:icon :name="$icon" class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            @if ($editing && $canEdit)
                                <flux:input wire:model="form.{{ $field }}" :label="$label" :type="$field === 'email' ? 'email' : 'tel'" />
                                <flux:error :name="'form.'.$field" />
                            @else
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                                <div class="mt-1 break-words text-sm font-medium">{{ $company?->$field ?: 'غير مسجل' }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach

                <div class="sm:col-span-2">
                    @if ($editing && $canEdit)
                        <flux:textarea wire:model="form.address" label="العنوان" rows="3" />
                        <flux:error name="form.address" />
                    @else
                        <div class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                <flux:icon name="map-pin" class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">العنوان</div>
                                <div class="mt-1 whitespace-pre-line break-words text-sm font-medium">{{ $company?->address ?: 'غير مسجل' }}</div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </x-erp.section-card>

        <x-erp.section-card title="إعدادات الشركة">
            <dl class="space-y-3">
                <x-erp.information-row label="العملة الأساسية" class="rounded-lg border border-zinc-200 px-3 dark:border-zinc-700">
                    <span class="flex items-center gap-3"><flux:icon name="banknotes" class="size-5 text-blue-600" /><bdi>EGP</bdi> · جنيه مصري</span>
                </x-erp.information-row>
                <x-erp.information-row label="لغة الواجهة الحالية" class="rounded-lg border border-zinc-200 px-3 dark:border-zinc-700">
                    <span class="flex items-center gap-3"><flux:icon name="globe-alt" class="size-5 text-blue-600" />العربية</span>
                </x-erp.information-row>
            </dl>
        </x-erp.section-card>

        @if ($editing && $canEdit)
            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save,logo">
                    <span wire:loading.remove wire:target="save">حفظ البيانات</span>
                    <span wire:loading wire:target="save">جارٍ الحفظ...</span>
                </flux:button>
                <flux:button type="button" variant="ghost" wire:click="cancel" wire:loading.attr="disabled" wire:target="save,logo">إلغاء</flux:button>
            </div>
        @elseif ($canEdit)
            <div class="flex justify-start">
                <flux:button type="button" variant="primary" wire:click="edit" icon="pencil-square">تعديل البيانات</flux:button>
            </div>
        @endif
    </form>
</div>
