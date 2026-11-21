@php
    $kindNames = [
        'asset' => 'أصول',
        'liability' => 'التزامات',
        'equity' => 'حقوق ملكية',
        'income' => 'إيرادات',
        'expense' => 'مصروفات',
    ];

    $starterNames = [
        'cash' => 'النقدية بالخزينة',
        'bank' => 'الحسابات البنكية',
        'receivable' => 'ذمم العملاء',
        'inventory' => 'المخزون السلعي',
        'inventory_transit' => 'مخزون بالطريق بين المستودعات',
        'tax_input' => 'ضريبة القيمة المضافة - مدخلات',
        'ap' => 'ذمم الموردين',
        'grni' => 'استلامات بانتظار الفوترة',
        'tax_output' => 'ضريبة القيمة المضافة - مخرجات',
        'sales' => 'إيرادات المبيعات',
        'cogs' => 'تكلفة البضاعة المباعة',
        'ppv' => 'فروق أسعار الشراء',
        'stock_adjustment' => 'تسويات وجرد المخزون',
        'inventory_issue' => 'مصروف الصرف الداخلي',
        'rounding' => 'فروق التقريب المسموح بها',
        'customer_advance' => 'دفعات مقدمة من العملاء',
        'maintenance_labor' => 'تكلفة عمل الصيانة المعتمدة',
        'maintenance_labor_accrual' => 'تكلفة عمل صيانة مستحقة',
        'service_contract_deferred' => 'إيراد عقود خدمة غير مكتسب',
    ];

    $displayLabels = [
        'cash' => 'حساب النقدية (الخزينة)',
        'bank' => 'حساب البنوك',
        'receivable' => 'حساب العملاء (الذمم المدينة)',
        'ap' => 'حساب الموردين (الذمم الدائنة)',
        'inventory' => 'حساب المخزون السلعي',
        'inventory_transit' => 'مخزون بالطريق بين المستودعات',
        'grni' => 'استلامات بانتظار الفوترة',
        'ppv' => 'فروق أسعار الشراء',
        'tax_input' => 'حساب ضريبة المدخلات',
        'tax_output' => 'حساب ضريبة المخرجات',
        'sales' => 'حساب إيرادات المبيعات',
        'cogs' => 'حساب تكلفة البضاعة المباعة',
        'stock_adjustment' => 'حساب تسويات المخزون',
        'inventory_issue' => 'حساب مصروف الصرف الداخلي',
        'rounding' => 'حساب فروق التقريب المسموح بها',
    ];

    $mappingGroups = [
        'الخزينة والعملاء والموردون' => [
            'cash' => 'لحركة الخزائن النقدية.',
            'bank' => 'لحسابات البنوك والتحويلات.',
            'receivable' => 'لمراقبة فواتير العملاء والتحصيل.',
            'ap' => 'لمراقبة فواتير الموردين والسداد.',
        ],
        'المخزون والمشتريات' => [
            'inventory' => 'لقيمة البضاعة في المستودعات.',
            'inventory_transit' => 'لبضاعة محوّلة بين المستودعات ولم تُستلم بعد.',
            'grni' => 'لمشتريات مستلمة بانتظار فاتورة المورد.',
            'ppv' => 'لفرق السعر بين أمر الشراء وفاتورة المورد.',
        ],
        'الضرائب' => [
            'tax_input' => 'لضريبة المشتريات القابلة للاسترداد.',
            'tax_output' => 'لضريبة المبيعات المحصلة.',
        ],
        'المبيعات والتكاليف والتسويات' => [
            'sales' => 'لإيرادات المبيعات الرئيسية.',
            'cogs' => 'لتكلفة البضاعة المباعة عند التسليم.',
            'stock_adjustment' => 'لفروق الجرد والتسويات المخزنية.',
            'inventory_issue' => 'لمصروف الصرف الداخلي للصيانة والورش والمشاريع.',
            'rounding' => 'لفروق التقريب المسموح بها في الفواتير.',
        ],
    ];

    $optionalDescriptions = [
        'customer_advance' => 'يُستخدم عند استلام دفعة مقدمة من عميل قبل إصدار فاتورة له.',
        'maintenance_labor' => 'يُستخدم لتسجيل تكلفة عمل الفنيين المعتمدة في الصيانة الميدانية.',
        'maintenance_labor_accrual' => 'يُستخدم لإثبات استحقاق تكلفة عمل الصيانة المعتمدة.',
        'service_contract_deferred' => 'يُستخدم لفواتير أقساط عقود الخدمة قبل إثبات الإيراد المكتسب.',
    ];

    $arabicNumerals = [1 => '١', 2 => '٢', 3 => '٣', 4 => '٤'];
    $openingKindLabels = [
        'gl' => 'قيد أستاذ عام',
        'ap' => 'تفاصيل مورد',
        'ar' => 'تفاصيل عميل',
        'cash' => 'تفاصيل نقدية',
        'bank' => 'تفاصيل بنك',
        'inventory' => 'مخزون افتتاحي (الأقدم أولاً)',
    ];
    $batchStatusLabels = [
        'draft' => 'مسودة',
        'submitted' => 'بانتظار اعتماد مراجع آخر',
        'approved' => 'معتمدة وجاهزة للتفعيل',
        'posted' => 'مُرحّلة ومُفعّلة',
    ];
    $batchVersionMatches = $batch && (int) $batch->configuration_version === (int) $setup['version'];
    $hasActiveCurrentBatch = $batch && $batchVersionMatches && in_array($batch->status, ['submitted', 'approved'], true);
@endphp

<div data-erp-archetype="form" class="erp-finance erp-setup space-y-5">
    <x-page.heading
        title="تجهيز النظام المالي"
        description="أكمل الخطوات الأربع بالترتيب لتجهيز تاريخ التشغيل والحسابات والضرائب والأرصدة الافتتاحية قبل فتح الترحيل المالي."
        :breadcrumbs="['المالية', 'تجهيز النظام المالي']"
    >
        <x-slot:secondaryActions>
            @if($a->allows(auth()->id(), 'account_view'))
                <flux:button :href="route('accounting.accounts')" icon="document-text" wire:navigate>دليل الحسابات</flux:button>
            @endif
            @if($a->allows(auth()->id(), 'period_view'))
                <flux:button :href="route('accounting.periods')" icon="calendar-days" wire:navigate>الفترات المالية</flux:button>
            @endif
        </x-slot:secondaryActions>
    </x-page.heading>

    @if(session('financial_message'))
        <flux:callout variant="success" icon="check-circle" class="erp-feedback--success" role="status">
            <flux:callout.text>{{ session('financial_message') }}</flux:callout.text>
        </flux:callout>
    @endif

    <x-ui.validation-summary />

    {{-- Unified Wizard Status & 4-Step Header --}}
    <section class="erp-setup-wizard-bar" aria-label="تقدم وخطوات تجهيز النظام المالي">
        @if($setup['activated_at'])
            <div class="erp-setup-wizard-top is-activated" role="status">
                <div class="erp-setup-status-main">
                    <div class="erp-setup-status-title">
                        <span class="erp-setup-pill is-success">مُفعّل</span>
                        <h2>الترحيل المالي مفعّل ويعمل</h2>
                    </div>
                    <p class="erp-setup-status-meta">
                        تاريخ التشغيل المعتمد: <strong dir="ltr">{{ $setup['go_live_date'] }}</strong>
                        · وقت التفعيل (توقيت القاهرة): <strong dir="ltr">{{ \Carbon\Carbon::parse($setup['activated_at'])->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</strong>
                        · بواسطة: <strong><x-erp.user-name :id="$setup['activated_by']" /></strong>
                    </p>
                </div>
                <div class="erp-setup-status-actions">
                    <flux:button variant="primary" size="sm" :href="route('finance.hub')" icon="squares-2x2" wire:navigate>مهام المالية</flux:button>
                </div>
            </div>
        @else
            <div class="erp-setup-wizard-top" role="status">
                <div class="erp-setup-status-main">
                    <div class="erp-setup-status-title">
                        <span class="erp-setup-pill is-warning">الترحيل المالي مغلق</span>
                        <h2>اكتملت {{ $completedStepsCount }} من 4 خطوات</h2>
                    </div>
                    <div class="erp-setup-progress-wrap">
                        <progress max="4" value="{{ $completedStepsCount }}" aria-label="تقدم خطوات التجهيز ({{ $completedStepsCount }} من 4)"></progress>
                        <span class="text-xs font-semibold text-slate-600" dir="ltr">{{ $completedStepsCount }}/4</span>
                    </div>
                </div>
                @if($firstIncompleteStep !== null && $firstIncompleteStep !== $activeStep)
                    <div class="erp-setup-status-actions">
                        <flux:button type="button" variant="primary" size="sm" wire:click="goToStep({{ $firstIncompleteStep }})">
                            الانتقال للخطوة {{ $arabicNumerals[$firstIncompleteStep] }}: {{ $stepStates[$firstIncompleteStep]['title'] }}
                        </flux:button>
                    </div>
                @endif
            </div>
        @endif

        <nav aria-label="خطوات تجهيز النظام المالي" class="erp-setup-stepper-wrap">
            <ol class="erp-setup-stepper">
                @foreach($stepStates as $stepNum => $step)
                    <li>
                        <button
                            type="button"
                            wire:click="goToStep({{ $stepNum }})"
                            aria-current="{{ $activeStep === $stepNum ? 'step' : 'false' }}"
                            @class([
                                'erp-setup-step-btn',
                                'is-active' => $activeStep === $stepNum,
                                'is-done' => $step['done'],
                            ])
                        >
                            <span class="erp-setup-step-head">
                                <span class="erp-setup-step-num" aria-hidden="true">{{ $step['done'] ? '✓' : $arabicNumerals[$stepNum] }}</span>
                                <span class="erp-setup-step-title">{{ $step['title'] }}</span>
                            </span>
                            <span class="erp-setup-step-desc">{{ $step['desc'] }}</span>
                            <span @class([
                                'erp-setup-pill',
                                'is-success' => $step['tone'] === 'success',
                                'is-warning' => $step['tone'] === 'warning',
                                'is-info' => $step['tone'] === 'info',
                                'is-neutral' => $step['tone'] === 'neutral',
                            ])>{{ $step['badge'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </nav>
    </section>

    @if(!$setup['activated_at'] && $activeStep !== 4)
        <details class="erp-setup-optional">
            <summary>
                <span>لوحة الجاهزية المالية ومن المسؤول عن كل بند ناقص</span>
                <span class="erp-setup-pill {{ collect($readinessBoard)->every(fn ($i) => $i['done']) ? 'is-success' : 'is-warning' }}">
                    مكتمل {{ collect($readinessBoard)->where('done', true)->count() }} من {{ count($readinessBoard) }}
                </span>
            </summary>
            <div class="erp-setup-optional-body">
                <ul class="erp-setup-checklist">
                    @foreach($readinessBoard as $item)
                        <li @class(['is-done' => $item['done']])>
                            <div class="flex items-start gap-3">
                                <span class="erp-setup-check-icon" aria-hidden="true">{{ $item['done'] ? '✓' : '!' }}</span>
                                <div class="space-y-0.5">
                                    <p class="text-sm font-semibold text-[#172b4d]">{{ $item['label'] }}</p>
                                    <p class="text-xs text-slate-600">{{ $item['detail'] }}</p>
                                    @if(!$item['done'])
                                        <p class="text-xs text-slate-700">
                                            المسؤول (<span class="font-medium">{{ $item['responsible_title'] }}</span> · <bdi dir="ltr">{{ $item['permission_code'] }}</bdi>):
                                            <strong>{{ $item['holders'] !== [] ? implode('، ', $item['holders']) : 'لا يوجد مستخدم نشط مفوض حالياً — راجع الأدوار والصلاحيات' }}</strong>
                                        </p>
                                        @if($item['note'])
                                            <p class="text-xs text-amber-800">{{ $item['note'] }}</p>
                                        @endif
                                    @endif
                                </div>
                            </div>
                            @if(!$item['done'])
                                <flux:button type="button" size="sm" wire:click="goToStep({{ $item['step'] }})">استكمال</flux:button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </details>
    @endif

    {{-- STEP 1: Go-Live Date, Period & Required Account Mappings --}}
    @if($activeStep === 1)
        <div wire:key="setup-step-1" class="space-y-5">
            <form wire:submit="saveConfiguration" class="space-y-5">
                <x-ui.panel class="space-y-4">
                    <div class="erp-setup-card-header">
                        <div>
                            <h2>١. تاريخ التشغيل والفترة المحاسبية</h2>
                            <p class="text-sm text-slate-600">
                                يحدد تاريخ التشغيل بداية تسجيل القيود والفواتير الرسمية. أي أرصدة قبل هذا التاريخ تُدخل كأرصدة افتتاحية في الخطوة ٣.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2 lg:items-stretch">
                        <div class="erp-setup-peer-box">
                            <flux:input
                                wire:model.live="configuration.go_live_date"
                                type="date"
                                label="تاريخ التشغيل الفعلي للشركة"
                                description:trailing="التاريخ الفعلي المعتمد من الإدارة (لا يُحدد تلقائياً من تاريخ الجهاز)."
                                :disabled="(bool) $setup['activated_at']"
                            />
                        </div>

                        <div @class([
                            'erp-setup-peer-box',
                            'is-success' => !empty($configuration['go_live_date']) && $coveringPeriod,
                            'is-warning' => !empty($configuration['go_live_date']) && !$coveringPeriod,
                        ])>
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="text-sm font-semibold text-[#3a4a63]">الفترة المحاسبية المغطّية لتاريخ التشغيل</p>
                                @if(!empty($configuration['go_live_date']) && $coveringPeriod)
                                    <span class="erp-setup-pill is-success">مغطّاة</span>
                                @elseif(!empty($configuration['go_live_date']))
                                    <span class="erp-setup-pill is-warning">ناقصة</span>
                                @endif
                            </div>

                            @if(empty($configuration['go_live_date']))
                                <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
                                    <p class="text-xs leading-6 text-slate-600">اختر تاريخ التشغيل للتحقق من وجود فترة محاسبية مفتوحة تشمله.</p>
                                    @if(!$setup['activated_at'] && $a->allows(auth()->id(), 'period_open'))
                                        <flux:button type="button" size="sm" icon="plus" wire:click="openPeriodModal">فتح فترة مالية</flux:button>
                                    @endif
                                </div>
                            @elseif($coveringPeriod)
                                <div class="space-y-0.5 pt-1">
                                    <p class="text-sm font-semibold text-emerald-900">توجد فترة مفتوحة تغطي التاريخ المختار</p>
                                    <p class="text-xs text-emerald-800">
                                        {{ $coveringPeriod->name }} (<bdi dir="ltr">{{ $coveringPeriod->starts_on }}</bdi> إلى <bdi dir="ltr">{{ $coveringPeriod->ends_on }}</bdi>)
                                    </p>
                                </div>
                            @else
                                <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
                                    <div class="space-y-0.5">
                                        <p class="text-sm font-semibold text-amber-950">لا توجد فترة مفتوحة تشمل التاريخ <bdi dir="ltr">{{ $configuration['go_live_date'] }}</bdi></p>
                                        <p class="text-xs text-amber-900">افتح فترة محاسبية تغطي هذا التاريخ قبل تفعيل الترحيل المالي.</p>
                                    </div>
                                    @if(!$setup['activated_at'] && $a->allows(auth()->id(), 'period_open'))
                                        <flux:button type="button" size="sm" variant="primary" icon="plus" wire:click="openPeriodModal">فتح فترة مالية الآن</flux:button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </x-ui.panel>

                <x-ui.panel class="space-y-4">
                    <div class="erp-setup-card-header">
                        <div>
                            <h2>٢. ربط الحسابات المطلوبة (15 حساباً مستقلاً)</h2>
                            <p class="text-sm text-slate-600">
                                اربط كل غرض محاسبي بحساب ترحيل نشط مستقل من نفس التصنيف. الحسابات المعلمة بـ «رقابي» تُحدَّث تلقائياً من المخزون والذمم والخزينة ولا تُرحل يدوياً.
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if(!$setup['activated_at'] && $a->allows(auth()->id(), 'account_manage') && !$hasAccountShortage && $missingExtraStarterCount > 0)
                                <flux:button type="button" size="sm" icon="plus" wire:click="openBulkAccountsModal">إنشاء حسابات إضافية للشركة</flux:button>
                            @endif
                            @if(!$setup['activated_at'] && $a->allows(auth()->id(), 'setup_manage') && $accounts->isNotEmpty())
                                <flux:button type="button" size="sm" icon="sparkles" wire:click="autofillMappings">ملء الربط الـ 15 تلقائياً للمراجعة</flux:button>
                            @endif
                            <span @class(['erp-setup-pill', 'is-success' => $requiredMappedCount === 15, 'is-neutral' => $requiredMappedCount < 15])>
                                مربوط {{ $requiredMappedCount }} من 15
                            </span>
                        </div>
                    </div>

                    <div class="erp-setup-source-note" role="note" aria-label="مصدر اختيارات القوائم">
                        <p class="text-xs leading-6 text-slate-700">
                            <strong class="text-[#172b4d]">من أين تأتي اختيارات القوائم أدناه؟</strong>
                            كل قائمة تعرض الحسابات النشطة (التي تقبل الترحيل) المسجّلة في
                            @if($a->allows(auth()->id(), 'account_view'))
                                <a href="{{ route('accounting.accounts') }}" wire:navigate class="font-semibold text-blue-700 underline">دليل الحسابات</a>
                            @else
                                <strong>دليل الحسابات</strong>
                            @endif
                            والمطابقة لتصنيف الغرض (أصول / التزامات / إيرادات / مصروفات).
                        </p>
                    </div>

                    @if($hasAccountShortage && !$setup['activated_at'])
                        <div class="erp-setup-shortage" role="region" aria-label="نقص في دليل الحسابات">
                            <div class="space-y-1">
                                <p class="text-sm font-semibold text-amber-950">
                                    متاح في دليل الحسابات {{ $accounts->count() }} حسابات فقط بينما المطلوب 15 حساباً مستقلاً
                                </p>
                                @if($allAccountsAreDemo)
                                    <p class="text-xs leading-6 text-amber-900">
                                        الحسابات الظاهرة حالياً (<bdi dir="ltr">DEMO-EG-...</bdi>) هي 5 حسابات تجريبية عامة للعرض فقط (حساب واحد لكل نوع)، لذلك تتكرر في كل القوائم ولا تكفي للتشغيل الفعلي.
                                    </p>
                                @endif
                                <p class="text-xs leading-6 text-amber-900">
                                    الأصول: {{ $accountsByKind['asset']['available'] }}/{{ $accountsByKind['asset']['required'] }}
                                    · الالتزامات: {{ $accountsByKind['liability']['available'] }}/{{ $accountsByKind['liability']['required'] }}
                                    · الإيرادات: {{ $accountsByKind['income']['available'] }}/{{ $accountsByKind['income']['required'] }}
                                    · المصروفات: {{ $accountsByKind['expense']['available'] }}/{{ $accountsByKind['expense']['required'] }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if($a->allows(auth()->id(), 'account_manage'))
                                    <flux:button type="button" variant="primary" size="sm" icon="plus" wire:click="openBulkAccountsModal">
                                        إنشاء دليل الحسابات الأساسي وتجهيز الربط
                                    </flux:button>
                                @endif
                                @if($a->allows(auth()->id(), 'account_view'))
                                    <flux:button size="sm" :href="route('accounting.accounts')" wire:navigate>
                                        فتح دليل الحسابات للإضافة يدوياً
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="space-y-4">
                        @foreach($mappingGroups as $groupTitle => $groupItems)
                            @php
                                $groupSelectedCount = collect(array_keys($groupItems))
                                    ->filter(fn (string $p) => !empty($configuration['mappings'][$p]))
                                    ->count();
                                $groupTotalCount = count($groupItems);
                            @endphp
                            <section class="erp-setup-group-box" aria-label="{{ $groupTitle }}">
                                <header class="erp-setup-group-head">
                                    <h3 class="erp-setup-group-title">{{ $groupTitle }}</h3>
                                    <span @class(['erp-setup-pill', 'is-success' => $groupSelectedCount === $groupTotalCount, 'is-neutral' => $groupSelectedCount < $groupTotalCount])>
                                        محدد {{ $groupSelectedCount }} من {{ $groupTotalCount }}
                                    </span>
                                </header>
                                <div class="erp-setup-group-rows">
                                    @foreach($groupItems as $purpose => $hint)
                                        @php
                                            [$label, $kind] = \App\Modules\Accounting\Application\FinancialActivation::MAPPINGS[$purpose];
                                            $displayLabel = $displayLabels[$purpose] ?? $label;
                                            $isControl = \App\Modules\Accounting\Application\FinancialActivation::MAPPINGS[$purpose][2] ?? false;
                                            $isDup = in_array($purpose, $duplicateMappingPurposes, true);
                                        @endphp
                                        <div @class(['erp-setup-map-row', 'is-duplicate' => $isDup])>
                                            <div class="erp-setup-map-info">
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <span id="setup-map-label-{{ $purpose }}" class="text-sm font-semibold text-[#172b4d]">{{ $displayLabel }}</span>
                                                    <span class="erp-setup-pill is-neutral">{{ $kindNames[$kind] ?? $kind }}</span>
                                                    @if($isControl)
                                                        <span class="erp-setup-pill is-info">رقابي</span>
                                                    @endif
                                                </div>
                                                <p class="text-xs text-slate-500">{{ $hint }}</p>
                                            </div>
                                            <div class="erp-setup-map-control">
                                                <div class="min-w-0 flex-1">
                                                    <flux:select
                                                        wire:model.live="configuration.mappings.{{ $purpose }}"
                                                        aria-labelledby="setup-map-label-{{ $purpose }}"
                                                        aria-label="{{ $displayLabel }}"
                                                        :disabled="(bool) $setup['activated_at']"
                                                    >
                                                        <option value="">اختر حساب {{ $kindNames[$kind] ?? $kind }} من دليل الحسابات…</option>
                                                        @foreach($accounts->where('kind', $kind) as $account)
                                                            <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                                        @endforeach
                                                    </flux:select>
                                                </div>
                                                @if(!$setup['activated_at'] && $a->allows(auth()->id(), 'account_manage'))
                                                    <button
                                                        type="button"
                                                        class="erp-setup-inline-btn"
                                                        wire:click="openAccountModal('mapping:{{ $purpose }}', '{{ $kind }}', '{{ $starterNames[$purpose] ?? $label }}')"
                                                    >
                                                        + جديد
                                                    </button>
                                                @endif
                                            </div>
                                            @if($isDup)
                                                <p class="erp-setup-map-dup text-xs font-semibold text-amber-800">هذا الحساب مختار لغرض آخر؛ يلزم حساب مستقل لكل غرض.</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>

                    <div class="erp-setup-step-footer">
                        <div class="flex flex-wrap items-center gap-3">
                            @if(!$setup['activated_at'] && $a->allows(auth()->id(), 'setup_manage'))
                                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ إعداد التشغيل والربط</flux:button>
                            @endif
                        </div>
                        <flux:button type="button" wire:click="goToStep(2)" icon-trailing="arrow-left">التالي: الضرائب</flux:button>
                    </div>
                </x-ui.panel>
            </form>
        </div>

    {{-- STEP 2: Tax Rules & Company Tax Validation --}}
    @elseif($activeStep === 2)
        <div wire:key="setup-step-2" class="space-y-5">
            <x-ui.panel class="space-y-4">
                <div class="erp-setup-card-header">
                    <div>
                        <h2>١. القواعد الضريبية المسجلة</h2>
                        <p class="text-sm text-slate-600">
                            لا توجد نسبة ضريبية تلقائية. أضف قاعدة للمبيعات وقاعدة للمشتريات على الأقل، ويمكنك تخصيص قواعد لأصناف بعينها أو تسجيل إعفاءات صريحة.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span @class(['erp-setup-pill', 'is-success' => $hasSaleRuleAtGoLive, 'is-neutral' => !$hasSaleRuleAtGoLive])>
                            مبيعات: {{ $hasSaleRuleAtGoLive ? 'متوفرة' : 'ناقصة' }}
                        </span>
                        <span @class(['erp-setup-pill', 'is-success' => $hasPurchaseRuleAtGoLive, 'is-neutral' => !$hasPurchaseRuleAtGoLive])>
                            مشتريات: {{ $hasPurchaseRuleAtGoLive ? 'متوفرة' : 'ناقصة' }}
                        </span>
                        <span @class(['erp-setup-pill', 'is-success' => $setup['tax_validated'], 'is-neutral' => !$setup['tax_validated']])>
                            التوثيق: {{ $setup['tax_validated'] ? 'مكتمل' : 'بانتظار التوثيق' }}
                        </span>
                    </div>
                </div>

                @if($taxRules->isEmpty())
                    <div class="erp-setup-empty-inline" role="status">
                        <flux:icon name="receipt-percent" class="size-5 shrink-0 text-blue-700" aria-hidden="true" />
                        <div>
                            <p class="text-sm font-semibold text-[#172b4d]">لا توجد قواعد ضريبية مسجلة بعد</p>
                            <p class="text-xs text-slate-600">استخدم قالب الضريبة المصرية 14% أدناه للمبيعات والمشتريات، أو أدخل قاعدة مخصصة.</p>
                        </div>
                    </div>
                @else
                    <x-ui.responsive-table label="القواعد الضريبية المسجلة">
                        <table role="table" class="w-full">
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th role="columnheader">القاعدة والمرجع</th>
                                    <th role="columnheader">المعاملة</th>
                                    <th role="columnheader">النطاق</th>
                                    <th role="columnheader">النسبة</th>
                                    <th role="columnheader">السريان</th>
                                    <th role="columnheader">الدقة</th>
                                    <th role="columnheader">الحالة</th>
                                    @if($a->allows(auth()->id(), 'tax_manage'))
                                        <th role="columnheader">إجراء</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                @foreach($taxRules as $rule)
                                    <tr role="row" wire:key="tax-rule-{{ $rule->id }}">
                                        <td role="cell" data-label="القاعدة والمرجع">
                                            <div>
                                                <strong class="block">{{ $rule->name }}</strong>
                                                <span class="text-xs text-slate-500" dir="ltr">{{ $rule->code }}</span>
                                                <p class="mt-0.5 text-xs text-slate-500" title="{{ $rule->legal_reference }}">{{ \Illuminate\Support\Str::limit($rule->legal_reference, 75) }}</p>
                                            </div>
                                        </td>
                                        <td role="cell" data-label="المعاملة">
                                            <div>
                                                <span>{{ $rule->transaction_type === 'sale' ? 'مبيعات' : 'مشتريات' }}</span>
                                                @if($rule->transaction_type === 'purchase')
                                                    <p class="text-xs text-slate-500">{{ $rule->purchase_recoverable ? 'قابلة للاسترداد' : 'تُحمّل على التكلفة' }}</p>
                                                @endif
                                            </div>
                                        </td>
                                        <td role="cell" data-label="النطاق">
                                            <div>{{ $rule->product_id ? ($productLabels[(int) $rule->product_id] ?? 'صنف #'.$rule->product_id) : 'عام لكل الأصناف' }}</div>
                                        </td>
                                        <td role="cell" data-label="النسبة">
                                            <div dir="ltr">{{ $rule->treatment === 'exempt' ? 'معفاة (0%)' : $rule->rate.'%' }}</div>
                                        </td>
                                        <td role="cell" data-label="السريان">
                                            <div dir="ltr">{{ $rule->starts_on }} → {{ $rule->effective_end ?? 'مفتوح' }}</div>
                                        </td>
                                        <td role="cell" data-label="الدقة">
                                            <div>{{ (int) $rule->tax_scale === 5 ? '5 خانات' : 'منزلتان' }}</div>
                                        </td>
                                        <td role="cell" data-label="الحالة">
                                            <div>
                                                @if($rule->withdrawn)
                                                    <span class="erp-setup-pill is-neutral">مسحوبة</span>
                                                @elseif($rule->effective_end)
                                                    <span class="erp-setup-pill is-warning">منتهية بتاريخ</span>
                                                @else
                                                    <span class="erp-setup-pill is-success">سارية</span>
                                                @endif
                                            </div>
                                        </td>
                                        @if($a->allows(auth()->id(), 'tax_manage'))
                                            <td role="cell" data-label="إجراء">
                                                <div>
                                                    @if(!$rule->withdrawn && ($rule->ending_id ?? null) === null)
                                                        <flux:button type="button" size="sm" wire:click="openEndTaxModal({{ $rule->id }})">إنهاء / سحب</flux:button>
                                                    @else
                                                        <span class="text-xs text-slate-400">مؤرشفة</span>
                                                    @endif
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.responsive-table>
                @endif
            </x-ui.panel>

            @if($a->allows(auth()->id(), 'tax_manage'))
                <x-ui.panel class="space-y-4">
                    <h2>٢. إضافة قاعدة ضريبية جديدة</h2>

                    <div class="erp-workflow-note">
                        <div class="space-y-1">
                            <strong>قالب الضريبة المصرية: السعر العام 14%</strong>
                            <p class="erp-form-hint">
                                يملأ الحقول بالسعر العام للقيمة المضافة والمرجع القانوني دون حفظ — حدد تاريخ السريان وراجع قابلية الاسترداد ثم احفظ.
                            </p>
                            <a class="text-xs underline" href="https://portal.eta.gov.eg/sites/default/files/2026-08/law.no_.149.of_.2026.pdf" target="_blank" rel="noopener noreferrer">
                                مرجع مصلحة الضرائب: المادة 3 المعدلة بالقانون 149 لسنة 2026
                            </a>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <flux:button type="button" size="sm" wire:click="prepareEgyptVat('sale')" wire:loading.attr="disabled">تعبئة قالب مبيعات 14%</flux:button>
                            <flux:button type="button" size="sm" wire:click="prepareEgyptVat('purchase')" wire:loading.attr="disabled">تعبئة قالب مشتريات 14%</flux:button>
                        </div>
                    </div>

                    <form wire:submit="addTaxRule" class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <flux:select wire:model.live="taxRule.transaction_type" label="نوع المعاملة">
                                <option value="sale">مبيعات</option>
                                <option value="purchase">مشتريات</option>
                            </flux:select>

                            @if($taxRule['transaction_type'] === 'purchase')
                                <flux:select wire:model="taxRule.purchase_recoverable" label="استرداد ضريبة المشتريات" required>
                                    <option value="">حدد المعاملة الفعلية</option>
                                    <option value="1">قابلة للاسترداد (تُخصم من الضريبة)</option>
                                    <option value="0">غير قابلة للاسترداد (تُضاف لتكلفة المخزون)</option>
                                </flux:select>
                            @endif

                            <flux:input wire:model="taxRule.code" label="كود القاعدة" placeholder="مثال: EG-VAT14-SALE" dir="ltr" required />
                            <flux:input wire:model="taxRule.name" label="اسم القاعدة" placeholder="مثال: ضريبة القيمة المضافة 14%" required />

                            <flux:select wire:model.live="taxScope" label="نطاق التطبيق">
                                <option value="all">عام لكل الأصناف غير المخصصة</option>
                                <option value="product">صنف محدد فقط</option>
                            </flux:select>

                            @if($taxScope === 'product')
                                <div wire:key="tax-scope-product">
                                    @if($canLookupProducts)
                                        <flux:select wire:model="taxRule.product_id" label="الصنف المخصص" search-model="taxProductSearch" required>
                                            <option value="">اختر الصنف…</option>
                                            @if($taxRule['product_id'] && !collect($taxProducts)->contains('id', (int) $taxRule['product_id']))
                                                <option value="{{ $taxRule['product_id'] }}">{{ $productLabels[(int) $taxRule['product_id']] ?? '#'.$taxRule['product_id'] }}</option>
                                            @endif
                                            @foreach($taxProducts as $product)
                                                <option value="{{ $product['id'] }}">{{ $product['sku'] }} · {{ $product['name'] }}</option>
                                            @endforeach
                                        </flux:select>
                                    @else
                                        <flux:input wire:model="taxRule.product_id" label="رقم الصنف" inputmode="numeric" required />
                                    @endif
                                </div>
                            @endif

                            <flux:select wire:model.live="taxRule.treatment" label="طريقة الاحتساب">
                                <option value="percentage">نسبة مئوية %</option>
                                <option value="exempt">إعفاء ضريبي صريح (0%)</option>
                            </flux:select>

                            <flux:input
                                wire:model="taxRule.rate"
                                label="النسبة %"
                                inputmode="decimal"
                                :readonly="$taxRule['treatment'] === 'exempt'"
                                required
                            />

                            <flux:input
                                wire:model="taxRule.starts_on"
                                type="date"
                                label="تاريخ بدء السريان"
                                description:trailing="يجب أن يشمل تاريخ التشغيل الفعلي."
                                required
                            />

                            <flux:input wire:model="taxRule.ends_on" type="date" label="تاريخ الانتهاء (اختياري)" />

                            <flux:select wire:model="taxRule.tax_scale" label="دقة كسور الضريبة">
                                <option value="5">5 خانات (متوافق مع الفاتورة الإلكترونية)</option>
                                <option value="2">منزلتان عشريتان (جنيه وقرش)</option>
                            </flux:select>
                        </div>

                        <flux:textarea wire:model="taxRule.legal_reference" rows="2" label="المرجع القانوني ومبرر القاعدة" required />

                        <div class="flex flex-wrap gap-3">
                            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ القاعدة الضريبية</flux:button>
                        </div>
                    </form>
                </x-ui.panel>
            @endif

            <x-ui.panel class="space-y-4">
                <h2>٣. توثيق المراجعة الضريبية للشركة</h2>
                <p class="text-sm text-slate-600">
                    سجّل مرجع مراجعة المسؤول الضريبي للتأكيد على أن القواعد ونسب التقريب المطبقة تطابق نشاط الشركة الفعلي.
                </p>

                @if($setup['tax_validated'])
                    <flux:callout variant="success" icon="check-circle" class="erp-feedback--success" role="status">
                        <flux:callout.heading>تم توثيق المراجعة الضريبية</flux:callout.heading>
                        <flux:callout.text>{{ $setup['tax_validation_reference'] }}</flux:callout.text>
                    </flux:callout>
                @elseif(!$setup['activated_at'] && $a->allows(auth()->id(), 'tax_manage'))
                    @if($taxRules->isEmpty())
                        <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                            أضف قاعدة ضريبية واحدة على الأقل (مبيعات ومشتريات) لتفعيل توثيق المراجعة الضريبية.
                        </p>
                    @else
                        <form wire:submit="validateTax" class="space-y-4">
                            <flux:textarea
                                wire:model="taxReference"
                                rows="2"
                                label="مرجع اعتماد المراجعة الضريبية (اسم المحاسب / رقم المذكرة)"
                                required
                            />
                            <flux:button
                                type="submit"
                                variant="primary"
                                wire:confirm="هل تؤكد أن القواعد الضريبية المسجلة ونظام التقريب قد راجعها المسؤول المختص؟"
                                wire:loading.attr="disabled"
                            >
                                تسجيل التحقق الضريبي
                            </flux:button>
                        </form>
                    @endif
                @endif
            </x-ui.panel>

            <div class="erp-setup-nav-bar">
                <flux:button type="button" wire:click="goToStep(1)" icon="arrow-right">السابق: التشغيل والحسابات</flux:button>
                <flux:button type="button" variant="primary" wire:click="goToStep(3)" icon-trailing="arrow-left">التالي: الأرصدة الافتتاحية</flux:button>
            </div>
        </div>

    {{-- STEP 3: Opening Balances & Independent Approval --}}
    @elseif($activeStep === 3)
        <div wire:key="setup-step-3" class="space-y-5">
            @if($batch)
                <x-ui.panel class="space-y-4">
                    <div class="erp-setup-card-header">
                        <div>
                            <h2>حزمة الأرصدة الافتتاحية الحالية (#{{ $batch->id }})</h2>
                            <p class="mt-1 text-xs text-slate-500">
                                المرجع: <strong>{{ $batch->evidence_reference }}</strong>
                                · أُعدّت بواسطة: <strong><x-erp.user-name :id="$batch->created_by" /></strong>
                                @if($batch->approved_by)
                                    · اعتُمدت بواسطة: <strong><x-erp.user-name :id="$batch->approved_by" /></strong>
                                @endif
                                · نسخة الإعداد: <strong dir="ltr">{{ $batch->configuration_version }}</strong>
                            </p>
                        </div>
                        <span @class([
                            'erp-setup-pill',
                            'is-success' => in_array($batch->status, ['approved', 'posted'], true),
                            'is-warning' => $batch->status === 'submitted',
                            'is-neutral' => $batch->status === 'draft',
                        ])>{{ $batchStatusLabels[$batch->status] ?? $batch->status }}</span>
                    </div>

                    @if(!$batchVersionMatches)
                        <flux:callout variant="warning" icon="exclamation-triangle" role="status">
                            <flux:callout.heading>تغيرت إعدادات التشغيل بعد حفظ هذه الحزمة</flux:callout.heading>
                            <flux:callout.text>
                                أُعدّت الحزمة على النسخة {{ $batch->configuration_version }} بينما الإعداد الحالي على النسخة {{ $setup['version'] }}. جهّز حزمة جديدة أدناه لاعتمادها بالنسخة الحالية.
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    @if($batch->no_balances)
                        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-950">
                            تتضمن هذه الحزمة تصريحاً صريحاً بالبدء بدون أرصدة افتتاحية (شركة جديدة / أرصدة صفرية).
                        </div>
                    @else
                        <x-ui.responsive-table label="بنود حزمة الأرصدة الافتتاحية">
                            <table role="table" class="w-full">
                                <thead role="rowgroup">
                                    <tr role="row">
                                        <th role="columnheader">النوع</th>
                                        <th role="columnheader">المرجع</th>
                                        <th role="columnheader">الفرع</th>
                                        <th role="columnheader">الحساب / الطرف / الصنف</th>
                                        <th role="columnheader">مدين</th>
                                        <th role="columnheader">دائن</th>
                                        <th role="columnheader">القيمة التفصيلية</th>
                                        <th role="columnheader">الكمية</th>
                                        <th role="columnheader">تكلفة الوحدة</th>
                                    </tr>
                                </thead>
                                <tbody role="rowgroup">
                                    @foreach($savedLines as $line)
                                        @php
                                            $detailLabel = match ($line['kind']) {
                                                'gl' => $accountLabels[(int) ($line['account_id'] ?? 0)] ?? 'حساب #'.$line['account_id'],
                                                'ap' => $supplierLabels[(int) ($line['supplier_id'] ?? 0)] ?? 'مورد #'.$line['supplier_id'],
                                                'ar' => $customerLabels[(int) ($line['customer_id'] ?? 0)] ?? 'عميل #'.$line['customer_id'],
                                                'inventory' => ($warehouseLabels[(int) ($line['warehouse_id'] ?? 0)] ?? 'مستودع #'.$line['warehouse_id']).' · '.($productLabels[(int) ($line['product_id'] ?? 0)] ?? 'صنف #'.$line['product_id']),
                                                default => '—',
                                            };
                                        @endphp
                                        <tr role="row">
                                            <td role="cell" data-label="النوع"><div>{{ $openingKindLabels[$line['kind']] ?? $line['kind'] }}</div></td>
                                            <td role="cell" data-label="المرجع"><div>{{ $line['reference'] }}</div></td>
                                            <td role="cell" data-label="الفرع"><div>{{ $branchLabels[(int) $line['branch_id']] ?? 'فرع #'.$line['branch_id'] }}</div></td>
                                            <td role="cell" data-label="الحساب / الطرف / الصنف">
                                                <div>
                                                    {{ $detailLabel }}
                                                    @if(!empty($line['serials']))
                                                        <p class="mt-1 text-xs text-slate-500">أرقام تسلسلية: {{ implode('، ', $line['serials']) }}</p>
                                                    @endif
                                                </div>
                                            </td>
                                            <td role="cell" data-label="مدين"><div dir="ltr">{{ $line['debit'] }}</div></td>
                                            <td role="cell" data-label="دائن"><div dir="ltr">{{ $line['credit'] }}</div></td>
                                            <td role="cell" data-label="القيمة التفصيلية"><div dir="ltr">{{ $line['amount'] }}</div></td>
                                            <td role="cell" data-label="الكمية"><div dir="ltr">{{ $line['quantity'] ?? '—' }}</div></td>
                                            <td role="cell" data-label="تكلفة الوحدة"><div dir="ltr">{{ $line['unit_cost'] ?? '—' }}</div></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-ui.responsive-table>
                    @endif

                    @if($reconciliation['balanced'])
                        <p class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                            المصالحة متطابقة: قيود الأستاذ العام متزنة وتفاصيل الموردين والعملاء والنقدية والبنوك والمخزون متطابقة لكل فرع.
                        </p>
                    @else
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 space-y-1">
                            @foreach($reconciliation['errors'] as $message)
                                <p class="text-sm text-amber-900">{{ $message }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div class="space-y-3">
                        @if($batch->status === 'draft' && $batchVersionMatches)
                            @if((int) $batch->created_by === (int) auth()->id() && $a->allows(auth()->id(), 'opening_manage'))
                                <flux:button type="button" variant="primary" wire:click="advance({{ $batch->id }}, {{ $batch->version }}, 'submit')" wire:loading.attr="disabled">
                                    إرسال الحزمة للاعتماد المستقل
                                </flux:button>
                            @else
                                <p class="text-xs text-slate-600">
                                    المسودة محفوظة باسم <x-erp.user-name :id="$batch->created_by" />، وهو من يرسلها للاعتماد المستقل.
                                </p>
                            @endif
                        @elseif($batch->status === 'submitted' && $batchVersionMatches)
                            @php
                                $sameAsPreparer = ((int) $batch->created_by === (int) auth()->id()) || ((int) ($setup['configured_by'] ?? 0) === (int) auth()->id());
                            @endphp
                            @if((!$sameAsPreparer || $canSelfApproveAccounting) && $a->allows(auth()->id(), 'opening_approve'))
                                @if($sameAsPreparer)
                                    <div class="w-full rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                        اعتماد ذاتي موثّق — أنت مُعد الإعداد أو حزمة الأرصدة، وتتيح السياسة المفعّلة لدورك اعتماد الحزمة مع تسجيل ذلك صراحةً في سجل التدقيق.
                                    </div>
                                @endif
                                <flux:button
                                    type="button"
                                    variant="primary"
                                    wire:click="advance({{ $batch->id }}, {{ $batch->version }}, 'approve')"
                                    wire:confirm="هل تؤكد مراجعة الأرصدة الفعلية ومصالحتها واعتمادها بهذه النسخة؟"
                                    wire:loading.attr="disabled"
                                >
                                    {{ $sameAsPreparer ? 'اعتماد الأرصدة الافتتاحية (اعتماد ذاتي موثّق)' : 'اعتماد الأرصدة الافتتاحية' }}
                                </flux:button>
                            @elseif($sameAsPreparer)
                                <flux:callout icon="shield-check" role="status">
                                    <flux:callout.heading>في انتظار اعتماد مراجع مستقل</flux:callout.heading>
                                    <flux:callout.text>
                                        تطبيقاً لمبدأ الرقابة الثنائية، لا يمكن لمن أعدّ الإعداد أو حزمة الأرصدة أن يعتمدها بنفسه. يجب أن يسجل مستخدم آخر لديه صلاحية اعتماد الأرصدة دخوله لمراجعة الحزمة واعتمادها.
                                        @if($canViewUsers)
                                            <a href="{{ route('organization.users') }}" wire:navigate class="font-semibold underline">إدارة المستخدمين والصلاحيات</a>
                                        @endif
                                    </flux:callout.text>
                                </flux:callout>
                            @else
                                <p class="text-xs text-amber-800">يلزم توفر صلاحية اعتماد الأرصدة الافتتاحية لدى مراجع مستقل عن مُعدّها.</p>
                            @endif
                        @elseif($batch->status === 'approved' && $batchVersionMatches && !$setup['activated_at'])
                            <flux:button type="button" variant="primary" wire:click="goToStep(4)" icon-trailing="arrow-left">
                                الحزمة معتمدة — انتقل لخطوة التفعيل
                            </flux:button>
                        @endif
                    </div>
                </x-ui.panel>
            @endif

            @if(!$setup['activated_at'] && $a->allows(auth()->id(), 'opening_manage'))
                @if($hasActiveCurrentBatch)
                    <details class="erp-setup-optional">
                        <summary>
                            <span>إعداد حزمة أرصدة جديدة بديلة (استبدال الحزمة الحالية)</span>
                            <span class="erp-setup-pill is-neutral">اختياري</span>
                        </summary>
                        <div class="erp-setup-optional-body">
                            @include('accounting.partials.opening-batch-editor')
                        </div>
                    </details>
                @else
                    <x-ui.panel class="space-y-5">
                        <h2>إعداد حزمة الأرصدة الافتتاحية</h2>
                        @include('accounting.partials.opening-batch-editor')
                    </x-ui.panel>
                @endif
            @elseif(!$setup['activated_at'] && !$batch)
                <x-ui.panel>
                    <x-ui.empty-state
                        title="لا توجد صلاحية لإعداد الأرصدة الافتتاحية"
                        description="اطلب من مدير النظام صلاحية إدارة الأرصدة الافتتاحية لإدخال الأرصدة أو تسجيل البدء بدون أرصدة."
                        icon="lock-closed"
                    />
                </x-ui.panel>
            @endif

            <div class="erp-setup-nav-bar">
                <flux:button type="button" wire:click="goToStep(2)" icon="arrow-right">السابق: الضرائب</flux:button>
                <flux:button type="button" variant="primary" wire:click="goToStep(4)" icon-trailing="arrow-left">التالي: المراجعة والتفعيل</flux:button>
            </div>
        </div>

    {{-- STEP 4: Final Pre-Flight Checklist & Activation --}}
    @elseif($activeStep === 4)
        <div wire:key="setup-step-4" class="space-y-5">
            @php
                $readyToActivate = !$setup['activated_at']
                    && $blockers === []
                    && $batch
                    && $batchVersionMatches
                    && $batch->status === 'approved'
                    && $reconciliation['balanced'];
            @endphp

            <x-ui.panel class="space-y-4">
                <div class="erp-setup-card-header">
                    <div>
                        <h2>لوحة الجاهزية النهائية ومن المسؤول عن كل بند ناقص</h2>
                        <p class="text-sm text-slate-600">توضح هذه القائمة حالة كل متطلب للتشغيل المالي، والأشخاص النشطين المفوضين باستكماله دون تعديل تلقائي أو تفعيل مسبق.</p>
                    </div>
                    <span class="erp-setup-pill {{ collect($readinessBoard)->every(fn ($i) => $i['done']) ? 'is-success' : 'is-warning' }}">
                        مكتمل {{ collect($readinessBoard)->where('done', true)->count() }} من {{ count($readinessBoard) }}
                    </span>
                </div>
                <ul class="erp-setup-checklist">
                    @foreach($readinessBoard as $item)
                        <li @class(['is-done' => $item['done']])>
                            <div class="flex items-start gap-3">
                                <span class="erp-setup-check-icon" aria-hidden="true">{{ $item['done'] ? '✓' : '!' }}</span>
                                <div class="space-y-0.5">
                                    <p class="text-sm font-semibold text-[#172b4d]">{{ $item['label'] }}</p>
                                    <p class="text-xs text-slate-600">{{ $item['detail'] }}</p>
                                    <p class="text-xs text-slate-700">
                                        المسؤول (<span class="font-medium">{{ $item['responsible_title'] }}</span> · <bdi dir="ltr">{{ $item['permission_code'] }}</bdi>):
                                        <strong>{{ $item['holders'] !== [] ? implode('، ', $item['holders']) : 'لا يوجد مستخدم نشط مفوض حالياً' }}</strong>
                                    </p>
                                    @if(!$item['done'] && $item['note'])
                                        <p class="text-xs text-amber-800">{{ $item['note'] }}</p>
                                    @endif
                                </div>
                            </div>
                            @if(!$item['done'])
                                <flux:button type="button" size="sm" wire:click="goToStep({{ $item['step'] }})">استكمال</flux:button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.panel>

            <x-ui.panel class="space-y-4">
                <h2>تفعيل الترحيل المالي</h2>
                <p class="text-sm text-slate-600">
                    التفعيل يرحّل حزمة الافتتاح المعتمدة في الأستاذ العام والموردين والعملاء والخزينة والمخزون معاً في معاملة واحدة لا تقبل التجزئة، ويُثبّت تاريخ التشغيل ليبدأ النظام بالعمل الفعلي.
                </p>

                @if($setup['activated_at'])
                    <flux:callout variant="success" icon="check-circle" class="erp-feedback--success" role="status">
                        <flux:callout.heading>تم التفعيل بنجاح</flux:callout.heading>
                        <flux:callout.text>
                            الترحيل المالي مفتوح بدءاً من <bdi dir="ltr">{{ $setup['go_live_date'] }}</bdi>. يمكنك الآن إدارة القيود والفواتير والتحصيل من مهام المالية.
                        </flux:callout.text>
                    </flux:callout>
                    <div class="flex flex-wrap gap-2">
                        <flux:button variant="primary" :href="route('finance.hub')" icon="squares-2x2" wire:navigate>الذهاب لمهام المالية</flux:button>
                        @if(isset($nav['journals']))
                            <flux:button :href="route('accounting.journals')" wire:navigate>القيود اليومية</flux:button>
                        @endif
                        @if(isset($nav['trial-balance']))
                            <flux:button :href="route('accounting.trial-balance')" wire:navigate>ميزان المراجعة</flux:button>
                        @endif
                    </div>
                @elseif($readyToActivate && $a->allows(auth()->id(), 'financial_activate'))
                    <flux:button
                        type="button"
                        variant="primary"
                        wire:click="activate({{ $batch->id }}, {{ $batch->version }})"
                        wire:confirm="هل تؤكد ترحيل الأرصدة الافتتاحية المعتمدة وتثبيت تاريخ التشغيل وتفعيل الترحيل المالي؟ هذه العملية نهائية ولا يمكن التراجع عنها."
                        wire:loading.attr="disabled"
                    >
                        ترحيل الافتتاح وتفعيل الترحيل المالي
                    </flux:button>
                @elseif($readyToActivate)
                    <flux:callout variant="warning" icon="lock-closed" role="status">
                        <flux:callout.heading>بانتظار صاحب صلاحية التفعيل المالي</flux:callout.heading>
                        <flux:callout.text>جميع المتطلبات مكتملة، لكن تفعيل النظام المالي يتطلب صلاحية التفعيل المالي (M11.financial_activate).</flux:callout.text>
                    </flux:callout>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <p class="text-sm text-amber-950">لا يمكن تفعيل الترحيل المالي قبل اكتمال البنود الستة أعلاه.</p>
                        @if($firstIncompleteStep !== null && $firstIncompleteStep !== 4)
                            <flux:button type="button" variant="primary" size="sm" wire:click="goToStep({{ $firstIncompleteStep }})">
                                العودة إلى الخطوة {{ $arabicNumerals[$firstIncompleteStep] }}: {{ $stepStates[$firstIncompleteStep]['title'] }}
                            </flux:button>
                        @endif
                    </div>
                @endif
            </x-ui.panel>

            <div class="erp-setup-nav-bar">
                <flux:button type="button" wire:click="goToStep(3)" icon="arrow-right">السابق: الأرصدة الافتتاحية</flux:button>
            </div>
        </div>
    @endif

    {{-- Secondary Collapsed Disclosure: Optional Module Account Mappings --}}
    <details class="erp-setup-optional">
        <summary>
            <span>إعدادات اختيارية للوحدات المتقدمة (لا تمنع التفعيل المالي)</span>
            <span class="erp-setup-pill is-neutral">اختياري · مربوط {{ $optionalMappedCount }} من 4</span>
        </summary>
        <div class="erp-setup-optional-body space-y-4">
            <p class="text-sm text-slate-600">
                تُطلب هذه الحسابات فقط عند استخدام دفعات العملاء المقدمة أو تكاليف عمل الصيانة المعتمدة أو إيراد عقود الخدمة المؤجل. بعد حفظ أي ربط اختياري لا يمكن تغييره لاحقاً.
            </p>
            <div class="grid gap-4 md:grid-cols-2">
                @foreach(\App\Modules\Accounting\Application\FinancialActivation::OPTIONAL_MAPPINGS as $purpose => [$label, $kind])
                    @php
                        $isLocked = isset($configuration['mappings'][$purpose]);
                        $isControl = \App\Modules\Accounting\Application\FinancialActivation::OPTIONAL_MAPPINGS[$purpose][2] ?? false;
                    @endphp
                    <form wire:submit="saveOptionalMapping('{{ $purpose }}')" class="erp-setup-map-card space-y-3" wire:key="optional-map-{{ $purpose }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="erp-setup-pill is-neutral">{{ $kindNames[$kind] ?? $kind }}</span>
                                @if($isControl)
                                    <span class="erp-setup-pill is-info">رقابي</span>
                                @endif
                                @if($isLocked)
                                    <span class="erp-setup-pill is-success">مثبّت</span>
                                @endif
                            </div>
                            @if(!$isLocked && $a->allows(auth()->id(), 'account_manage'))
                                <button
                                    type="button"
                                    class="erp-setup-inline-link"
                                    wire:click="openAccountModal('optional:{{ $purpose }}', '{{ $kind }}', '{{ $starterNames[$purpose] ?? $label }}')"
                                >
                                    + حساب جديد
                                </button>
                            @endif
                        </div>

                        <flux:select
                            wire:model="optionalMappings.{{ $purpose }}"
                            :label="$label"
                            :description="$optionalDescriptions[$purpose] ?? null"
                            :disabled="$isLocked"
                        >
                            <option value="">اختر حساب ترحيل مستقلًا</option>
                            @foreach($accounts->where('kind', $kind) as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                            @endforeach
                        </flux:select>

                        @if(!$isLocked && $a->allows(auth()->id(), 'setup_manage'))
                            <flux:button
                                type="submit"
                                size="sm"
                                wire:confirm="تثبيت هذا الحساب الاختياري؟ لا يمكن تعديل الربط الاختياري بعد حفظه."
                                wire:loading.attr="disabled"
                            >
                                حفظ الربط الاختياري
                            </flux:button>
                        @endif
                    </form>
                @endforeach
            </div>
        </div>
    </details>

    {{-- Modal 1: Quick Create Single Account --}}
    <flux:modal wire:model="showAccountModal" class="w-full max-w-lg">
        <form wire:submit="createQuickAccount" class="space-y-4">
            <x-ui.validation-summary />
            <flux:heading size="lg">إنشاء حساب جديد في دليل الحسابات</flux:heading>
            <p class="text-sm text-slate-600">
                سيُنشأ الحساب كحساب ترحيل نشط من تصنيف
                <strong>{{ $kindNames[$newAccount['kind']] ?? $newAccount['kind'] }}</strong>
                ويُختار تلقائياً في القائمة.
            </p>
            <flux:input wire:model="newAccount.code" label="كود الحساب" dir="ltr" required placeholder="مثال: 1104" />
            <flux:input wire:model="newAccount.name" label="اسم الحساب" required />
            <flux:select wire:model="newAccount.parent_id" label="الحساب الأب التجميعي (اختياري)">
                <option value="">بدون أب (حساب رئيسي مباشر)</option>
                @foreach($parentAccounts->where('kind', $newAccount['kind']) as $parent)
                    <option value="{{ $parent->id }}">{{ $parent->code }} · {{ $parent->name }}</option>
                @endforeach
            </flux:select>
            <div class="flex flex-wrap gap-3">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">إنشاء الحساب واختياره</flux:button>
                <flux:button type="button" wire:click="$set('showAccountModal', false)">إلغاء</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal 2: Bulk Create Missing Required Starter Accounts --}}
    <flux:modal wire:model="showBulkAccountsModal" class="w-full max-w-3xl">
        <form wire:submit="createSuggestedAccounts" class="space-y-4">
            <x-ui.validation-summary />
            <flux:heading size="lg">إنشاء قالب الدليل المختصر للشركة</flux:heading>
            <p class="text-sm text-slate-600">
                راجع الأكواد والأسماء المقترحة للأغراض الـ 15 الإلزامية وكذلك حسابات الشركاء والعربون وإيراد الإصلاح والمصروفات لمراجعتها قبل الحفظ أو الاعتماد.
            </p>
            @if($suggestedAccounts === [] && $suggestedExtraAccounts === [])
                <p class="rounded border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">كل الحسابات المقترحة موجودة أو محددة بالفعل.</p>
            @else
                <div class="max-h-[58vh] space-y-4 overflow-y-auto pe-1">
                    @if($suggestedAccounts !== [])
                        <h3 class="text-xs font-bold text-slate-600">١. الحسابات الإلزامية للربط (15 غرضاً)</h3>
                        <div class="space-y-2.5">
                            @foreach($suggestedAccounts as $idx => $row)
                                <div wire:key="suggested-acc-{{ $row['purpose'] }}" class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50/70 p-3 sm:grid-cols-[8.5rem_1fr] sm:items-end">
                                    <div class="flex items-center gap-2 sm:col-span-2">
                                        <flux:checkbox wire:model="suggestedAccounts.{{ $idx }}.selected" :label="$row['label'].' ('.$kindNames[$row['kind']].')'" />
                                    </div>
                                    <flux:input wire:model="suggestedAccounts.{{ $idx }}.code" label="الكود" dir="ltr" required />
                                    <flux:input wire:model="suggestedAccounts.{{ $idx }}.name" label="اسم الحساب المقترح" required />
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if($suggestedExtraAccounts !== [])
                        <h3 class="pt-2 text-xs font-bold text-slate-600">٢. حسابات إضافية للشركاء والعربون والإصلاح والمصروفات</h3>
                        <div class="space-y-2.5">
                            @foreach($suggestedExtraAccounts as $eIdx => $row)
                                <div wire:key="suggested-extra-{{ $row['key'] }}" class="grid gap-3 rounded-lg border border-slate-200 bg-blue-50/40 p-3 sm:grid-cols-[8.5rem_1fr] sm:items-end">
                                    <div class="flex items-center gap-2 sm:col-span-2">
                                        <flux:checkbox wire:model="suggestedExtraAccounts.{{ $eIdx }}.selected" :label="$row['name'].' ('.($kindNames[$row['kind']] ?? $row['kind']).((! ($row['postable'] ?? true)) ? ' · حساب تجميعي أب' : '').')'" />
                                    </div>
                                    <flux:input wire:model="suggestedExtraAccounts.{{ $eIdx }}.code" label="الكود" dir="ltr" required />
                                    <flux:input wire:model="suggestedExtraAccounts.{{ $eIdx }}.name" label="اسم الحساب المقترح" required />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
            <div class="flex flex-wrap gap-3">
                @if($suggestedAccounts !== [] || $suggestedExtraAccounts !== [])
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">إنشاء الحسابات المحددة وربطها للمراجعة</flux:button>
                @endif
                <flux:button type="button" wire:click="$set('showBulkAccountsModal', false)">إلغاء</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal 3: Open Financial Period --}}
    <flux:modal wire:model="showPeriodModal" class="w-full max-w-lg">
        <form wire:submit="createPeriod" class="space-y-4">
            <x-ui.validation-summary />
            <flux:heading size="lg">فتح فترة محاسبية جديدة</flux:heading>
            <p class="text-sm text-slate-600">يجب أن تقع بداية الفترة ونهايتها حول تاريخ التشغيل الفعلي دون تداخل مع فترة أخرى.</p>
            <flux:input wire:model="newPeriod.name" label="اسم الفترة المحاسبية" placeholder="مثال: السنة المالية 2026" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="newPeriod.starts_on" type="date" label="تاريخ البداية" required />
                <flux:input wire:model="newPeriod.ends_on" type="date" label="تاريخ النهاية" required />
            </div>
            <div class="flex flex-wrap gap-3">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">فتح الفترة</flux:button>
                <flux:button type="button" wire:click="$set('showPeriodModal', false)">إلغاء</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal 4: End / Withdraw Tax Rule --}}
    <flux:modal wire:model="showEndTaxModal" class="w-full max-w-lg">
        <form wire:submit="endTaxRule" class="space-y-4">
            <x-ui.validation-summary />
            <flux:heading size="lg">إنهاء أو سحب قاعدة ضريبية</flux:heading>
            <p class="text-sm text-slate-600">تظل المستندات الصادرة سابقاً محتفظة بلقطاتها التاريخية دون تعديل.</p>
            <flux:input wire:model="taxEnd.ends_on" type="date" label="آخر يوم سريان (غير مطلوب عند السحب قبل التفعيل)" />
            <flux:textarea wire:model="taxEnd.reason" rows="2" label="سبب التغيير ومرجع التحقق" required />
            @if(!$setup['activated_at'])
                <flux:checkbox wire:model="taxEnd.withdraw" label="سحب القاعدة بالكامل قبل التفعيل (لأنها أُدخلت بالخطأ)" />
            @endif
            <div class="flex flex-wrap gap-3">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">تأكيد التسجيل</flux:button>
                <flux:button type="button" wire:click="$set('showEndTaxModal', false)">إلغاء</flux:button>
            </div>
        </form>
    </flux:modal>

    <p class="text-xs text-slate-500" wire:loading role="status">جارٍ تنفيذ الطلب، انتظر اكتمال العملية…</p>
</div>
