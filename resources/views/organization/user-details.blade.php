<div data-erp-archetype="details" class="user-details-tabs space-y-6" dir="rtl"
    x-data="{
        activeTab: 'basic',
        tabs: ['basic', 'access', 'history', 'security'],
        tabForHash() {
            return ({ '#user-access': 'access', '#permissions-history': 'history', '#security-activity': 'security' })[window.location.hash] ?? 'basic';
        },
        hashForTab(tab) {
            return ({ basic: '', access: '#user-access', history: '#permissions-history', security: '#security-activity' })[tab] ?? '';
        },
        init() {
            this.activeTab = this.tabForHash();
            window.addEventListener('hashchange', () => this.activeTab = this.tabForHash());
        },
        activate(tab, focus = false) {
            this.activeTab = tab;
            window.history.replaceState(null, '', window.location.pathname + window.location.search + this.hashForTab(tab));
            if (focus) this.$refs.tablist.querySelector('[data-tab=' + tab + ']').focus();
        },
        moveTab(event) {
            const rtl = getComputedStyle(this.$refs.tablist).direction === 'rtl';
            const delta = event.key === 'ArrowRight' ? (rtl ? -1 : 1) : (rtl ? 1 : -1);
            const index = this.tabs.indexOf(this.activeTab);
            this.activate(this.tabs[(index + delta + this.tabs.length) % this.tabs.length], true);
        }
    }">

    <x-page.heading title="تفاصيل المستخدم" description="معلومات حساب المستخدم وإعداداته">
        <x-slot:actions><flux:button :href="route('organization.users')" wire:navigate>العودة للمستخدمين</flux:button></x-slot:actions>
    </x-page.heading>
    <x-ui.validation-summary />
    @if (session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif

    <x-erp.section-card title="ملف المستخدم">
        <div class="flex flex-col gap-4 sm:flex-row-reverse sm:items-center">
            <x-erp.user-avatar :user-id="$record->id" :gender="$record->gender" :label="$record->name" size="lg" />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="text-xl font-semibold text-slate-900">{{ $record->name }}</h2>
                    <x-erp.status-badge :active="$record->active" />
                </div>
                <p class="mt-1 break-all text-sm text-slate-600"><bdi>{{ $record->email }}</bdi></p>
            </div>
        </div>
    </x-erp.section-card>

    <div x-ref="tablist" role="tablist" aria-label="أقسام تفاصيل المستخدم" aria-orientation="horizontal" class="erp-tabs flex flex-wrap gap-2 border-b border-slate-200 rounded-xl px-2" @keydown.arrow-right.prevent="moveTab($event)" @keydown.arrow-left.prevent="moveTab($event)" @keydown.home.prevent="activate(tabs[0], true)" @keydown.end.prevent="activate(tabs[tabs.length - 1], true)">
        <button type="button" role="tab" id="user-details-tab-basic" data-tab="basic" aria-controls="user-details-panel-basic" :aria-selected="activeTab === 'basic'" :tabindex="activeTab === 'basic' ? 0 : -1" @click="activate('basic')" class="rounded-t-lg border-b-2 px-4 py-3 text-sm font-medium transition" :class="activeTab === 'basic' ? 'border-emerald-600 text-emerald-800' : 'border-transparent text-slate-600 hover:text-slate-900'"><flux:icon name="user-circle" class="size-4 shrink-0" />المعلومات الأساسية</button>
        <button type="button" role="tab" id="user-details-tab-access" data-tab="access" aria-controls="user-details-panel-access" :aria-selected="activeTab === 'access'" :tabindex="activeTab === 'access' ? 0 : -1" @click="activate('access')" class="rounded-t-lg border-b-2 px-4 py-3 text-sm font-medium transition" :class="activeTab === 'access' ? 'border-emerald-600 text-emerald-800' : 'border-transparent text-slate-600 hover:text-slate-900'"><flux:icon name="key" class="size-4 shrink-0" />الأدوار ونطاقات الوصول</button>
        <button type="button" role="tab" id="user-details-tab-history" data-tab="history" aria-controls="user-details-panel-history" :aria-selected="activeTab === 'history'" :tabindex="activeTab === 'history' ? 0 : -1" @click="activate('history')" class="rounded-t-lg border-b-2 px-4 py-3 text-sm font-medium transition" :class="activeTab === 'history' ? 'border-emerald-600 text-emerald-800' : 'border-transparent text-slate-600 hover:text-slate-900'"><flux:icon name="clock" class="size-4 shrink-0" />سجل الصلاحيات</button>
        <button type="button" role="tab" id="user-details-tab-security" data-tab="security" aria-controls="user-details-panel-security" :aria-selected="activeTab === 'security'" :tabindex="activeTab === 'security' ? 0 : -1" @click="activate('security')" class="rounded-t-lg border-b-2 px-4 py-3 text-sm font-medium transition" :class="activeTab === 'security' ? 'border-emerald-600 text-emerald-800' : 'border-transparent text-slate-600 hover:text-slate-900'"><flux:icon name="shield-check" class="size-4 shrink-0" />الأمان والنشاط</button>
    </div>

    <section role="tabpanel" id="user-details-panel-basic" aria-labelledby="user-details-tab-basic" tabindex="0" x-show="activeTab === 'basic'" class="space-y-5">
        <x-erp.section-card title="معلومات الحساب">
            <dl class="grid gap-5 sm:grid-cols-2">
                <x-erp.information-row label="الاسم الكامل">{{ $record->name }}</x-erp.information-row>
                <x-erp.information-row label="البريد الإلكتروني"><bdi>{{ $record->email }}</bdi></x-erp.information-row>
                <x-erp.information-row label="الفرع">{{ $record->branch_name ?: 'غير محدد' }}</x-erp.information-row>
                <x-erp.information-row label="تاريخ الإنشاء · توقيت القاهرة"><bdi>{{ \Carbon\CarbonImmutable::parse($record->created_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</bdi></x-erp.information-row>
                <x-erp.information-row label="آخر تسجيل دخول · توقيت القاهرة">@if ($record->last_login_at)<bdi>{{ \Carbon\CarbonImmutable::parse($record->last_login_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</bdi>@else لا توجد بيانات دخول مسجلة @endif</x-erp.information-row>
            </dl>
        </x-erp.section-card>

        <x-erp.section-card title="معلومات إضافية">
            <dl class="grid gap-5 sm:grid-cols-3">
                <x-erp.information-row label="لغة الواجهة الحالية">العربية</x-erp.information-row>
                <x-erp.information-row label="المنطقة الزمنية">القاهرة (UTC{{ now('Africa/Cairo')->format('P') }})</x-erp.information-row>
                <x-erp.information-row label="النوع">{{ match ($record->gender) { 'male' => 'ذكر', 'female' => 'أنثى', default => 'غير محدد' } }}</x-erp.information-row>
            </dl>
        </x-erp.section-card>

        @if ($canEdit)
            <div class="flex justify-end"><flux:button class="erp-action-positive" icon="pencil-square" wire:click="edit">تحرير البيانات</flux:button></div>
        @endif
    </section>

    <section role="tabpanel" id="user-details-panel-access" aria-labelledby="user-details-tab-access" tabindex="0" x-show="activeTab === 'access'" x-cloak class="space-y-5">
        <div class="scroll-mt-24">
            @if ($canManageSecurity)
                @livewire(\App\Modules\Organization\Presentation\UserAccessSection::class, ['user' => $record->id, 'lazy' => true], key('user-access-'.$record->id))
                @livewire(\App\Modules\Organization\Presentation\QuotationSelfApprovalSection::class, ['targetType'=>'user','targetId'=>$record->id, 'lazy' => true], key('quote-self-user-'.$record->id))
            @else
                <x-erp.section-card title="الأدوار ونطاقات الوصول">
                    @if ($allowedBranches->isNotEmpty())
                        <div class="erp-section-nav flex flex-wrap gap-2">@foreach ($allowedBranches as $branch)<span wire:key="allowed-branch-{{ $branch->id }}" class="inline-flex items-center gap-2 rounded-md bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800"><flux:icon.building-office-2 class="size-4" />{{ $branch->name }}</span>@endforeach</div>
                    @else
                        <p class="text-sm text-slate-500">لا توجد تعيينات فروع مسجلة لهذا المستخدم.</p>
                    @endif
                    @if ($roles->isNotEmpty())
                        <div class="mt-4 flex flex-wrap gap-2">@foreach ($roles as $role)<span wire:key="role-{{ $role->code }}" class="rounded-md bg-slate-100 px-3 py-2 text-sm">{{ $role->name }}</span>@endforeach</div>
                    @else
                        <p class="mt-4 text-sm text-slate-500">لم يُسند دور إلى هذا الحساب.</p>
                    @endif
                    <p class="mt-4 text-xs text-slate-500">عرض الأدوار والنطاقات مسموح وفق صلاحيات الحساب. تعديلها يحتاج تفويض إدارة الأمان.</p>
                </x-erp.section-card>
            @endif
        </div>
    </section>

    <section role="tabpanel" id="user-details-panel-history" aria-labelledby="user-details-tab-history" tabindex="0" x-show="activeTab === 'history'" x-cloak class="space-y-5">
        <div class="scroll-mt-24">
            @if ($canManageSecurity)
                @livewire(\App\Modules\Organization\Presentation\UserAccessSection::class, ['user' => $record->id, 'historyOnly' => true, 'lazy' => true], key('user-permission-history-'.$record->id))
            @else
                <x-erp.section-card title="سجل تغييرات الصلاحيات">
                    <p class="text-sm text-slate-600">عرض سجل تغييرات الصلاحيات يحتاج تفويض إدارة الأمان.</p>
                </x-erp.section-card>
            @endif
        </div>
    </section>

    <section role="tabpanel" id="user-details-panel-security" aria-labelledby="user-details-tab-security" tabindex="0" x-show="activeTab === 'security'" x-cloak class="space-y-5">
        <div id="security-activity" class="scroll-mt-24 space-y-5">
            @if ($canManageSecurity)
                <x-erp.section-card title="نشاط الحساب">
                    <p class="mb-3 text-xs text-slate-500">آخر 50 حدثًا مسجلًا. أحداث الدخول والاستعادة تُلتقط من تاريخ تفعيل هذا التسجيل؛ لا تُستنتج أحداث قديمة مفقودة.</p>
                    <x-erp.audit-timeline :events="$activity" />
                </x-erp.section-card>
            @else
                <x-erp.section-card title="الأمان والنشاط">
                    <p class="text-sm text-slate-600">عرض سجل نشاط الحساب يحتاج تفويض إدارة الأمان. الإجراءات المتاحة تخضع لتفويضاتها المستقلة.</p>
                </x-erp.section-card>
            @endif
            @if ($canReset || $canStatus)
                <x-erp.section-card title="إجراءات الأمان">
                    <div class="flex flex-wrap gap-3">
                        @if ($canReset)<flux:button variant="primary" icon="key" wire:click="openPasswordReset">إعادة تعيين كلمة المرور</flux:button>@endif
                        @if ($canStatus)<flux:button :variant="$record->active ? 'danger' : 'primary'" icon="power" wire:click="confirmStatus">{{ $record->active ? 'تعطيل الحساب' : 'تفعيل الحساب' }}</flux:button>@endif
                    </div>
                </x-erp.section-card>
            @endif
        </div>
    </section>

    <flux:modal wire:model="editing" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">تحرير بيانات المستخدم</flux:heading>
            <x-erp.user-fields :branches="$branches" />
            <p class="text-sm text-slate-500">الأدوار ونطاقات الوصول تُدار في القسم المخصص بصفحة المستخدم بعد إغلاق هذا النموذج.</p>
            <div class="flex gap-3"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ التعديلات</flux:button><flux:button type="button" wire:click="cancel">إلغاء</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal wire:model="confirmingStatus" class="w-full max-w-lg">
        <flux:heading>تأكيد {{ $record->active ? 'تعطيل' : 'تفعيل' }} الحساب</flux:heading>
        <p class="my-3 text-sm text-slate-600">سيُحفظ تغيير حالة {{ $record->name }} في سجل التدقيق، مع الاحتفاظ بسجل الحساب.</p>
        <flux:error name="status" />
        <div class="mt-3 flex gap-3"><flux:button variant="primary" wire:click="changeStatus" wire:loading.attr="disabled">تأكيد التغيير</flux:button><flux:button wire:click="cancelStatus">إلغاء</flux:button></div>
    </flux:modal>

    <flux:modal wire:model="resetPasswordModal" class="w-full max-w-lg">
        <form wire:submit="resetPassword" class="space-y-5">
            <flux:heading size="lg">إعادة تعيين كلمة المرور</flux:heading>
            <flux:text>عيّن كلمة مرور جديدة للمستخدم. ستُحفظ بأمان، وتُنهى جلساته الحالية، ولا تُعرض بعد الإرسال.</flux:text>
            <flux:input wire:model="password" type="password" label="كلمة المرور الجديدة" required minlength="12" autocomplete="new-password" />
            <flux:input wire:model="password_confirmation" type="password" label="تأكيد كلمة المرور" required minlength="12" autocomplete="new-password" />
            <div class="flex gap-3"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">تعيين كلمة المرور</flux:button><flux:button type="button" wire:click="cancelPasswordReset">إلغاء</flux:button></div>
        </form>
    </flux:modal>
</div>
