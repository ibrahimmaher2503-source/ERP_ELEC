@php
    $reportScreens = [
        'ledger' => ['label' => 'الأستاذ العام', 'icon' => 'book-open'],
        'trial-balance' => ['label' => 'ميزان المراجعة', 'icon' => 'scale'],
        'income-statement' => ['label' => 'قائمة الدخل', 'icon' => 'trending-up'],
        'balance-sheet' => ['label' => 'الميزانية', 'icon' => 'building-library'],
        'grni' => ['label' => 'استلامات لم تُفوتر', 'icon' => 'inbox-stack'],
        'ppv' => ['label' => 'فروق أسعار الشراء', 'icon' => 'arrows-right-left'],
        'reconciliation' => ['label' => 'مطابقة الحسابات', 'icon' => 'check-badge'],
        'closing' => ['label' => 'إقفال الفترة المالية', 'icon' => 'lock-closed'],
    ];
    $descriptions = [
        'income-statement' => 'هل ربحت أم خسرت في الفترة: الإيرادات ناقص المصروفات',
        'balance-sheet' => 'ما تملكه وما عليك وصافي حقوق الملكية في تاريخ محدد',
        'trial-balance' => 'أرصدة كل الحسابات للتأكد أن المدين يساوي الدائن',
        'ledger' => 'كل حركات حساب بعينه أو كافة الحسابات مع الرصيد التراكمي',
        'grni' => 'مطابقة بضاعة مستلمة بانتظار الفوترة بين الأستاذ العام وسجل الاستلامات',
        'ppv' => 'تحليل فروق أسعار فواتير الموردين المرحّلة مقابل تكلفة الاستلام',
        'reconciliation' => 'مطابقة الحسابات الرقابية في الأستاذ العام مع سجلات الذمم والمخزون والخزينة',
        'closing' => 'مراجعة جاهزية الفترات المحاسبية وإقفالها رسمياً بعد اكتمال المطابقات',
    ];
    $kindBadgeColors = [
        'asset' => 'bg-blue-50 text-blue-800 border-blue-200',
        'liability' => 'bg-amber-50 text-amber-800 border-amber-200',
        'equity' => 'bg-violet-50 text-violet-800 border-violet-200',
        'income' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
        'expense' => 'bg-rose-50 text-rose-800 border-rose-200',
    ];
    $reconciliationLinks = [
        'ap' => ['route' => 'financial.ap', 'label' => 'سجل الذمم الدائنة'],
        'ar' => ['route' => 'financial.ar', 'label' => 'سجل الذمم المدينة'],
        'grni' => ['route' => 'purchasing.receipts.index', 'label' => 'استلامات المشتريات'],
        'inventory' => ['route' => 'inventory.balances', 'label' => 'أرصدة المخزون'],
        'inventory_transit' => ['route' => 'inventory.transfers', 'label' => 'تحويلات المخزون'],
        'bank' => ['route' => 'treasury.accounts', 'label' => 'حسابات الخزينة والبنوك'],
        'cash' => ['route' => 'treasury.accounts', 'label' => 'حسابات الخزينة والبنوك'],
        'customer_advance' => ['route' => 'financial.advances', 'label' => 'دفعات العملاء المقدمة'],
        'service_contract_deferred' => ['route' => 'service-contracts.index', 'label' => 'عقود الصيانة'],
    ];
    $defaultFrom = now('Africa/Cairo')->startOfYear()->toDateString();
    $defaultTo = now('Africa/Cairo')->toDateString();
@endphp

<div data-erp-archetype="report" class="erp-finance space-y-5">
    <x-page.heading :title="$nav[$screen]" :description="($descriptions[$screen] ?? 'بيانات مالية مرحّلة فعلياً').' · بالجنيه المصري'" :breadcrumbs="['المالية', 'التقارير المالية', $nav[$screen]]" />
    <x-erp.finance-banner />

    {{-- شريط التنقل السريع بين التقارير المحاسبية --}}
    <nav class="flex flex-wrap items-center gap-1.5 rounded-xl border border-slate-200 bg-white p-2 shadow-2xs" aria-label="التقارير المحاسبية">
        @foreach($reportScreens as $key => $meta)
            @if(isset($nav[$key]))
                <flux:button
                    size="sm"
                    :variant="$screen === $key ? 'primary' : 'ghost'"
                    :href="route('accounting.'.$key)"
                    :aria-current="$screen === $key ? 'page' : null"
                    wire:navigate
                >{{ $meta['label'] }}</flux:button>
            @endif
        @endforeach
    </nav>

    @if(session('accounting_message'))
        <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-800">{{ session('accounting_message') }}</div>
    @endif
    <x-ui.validation-summary />

    {{-- فلاتر التقارير + اختصارات الفترات الزمنية --}}
    @if($screen !== 'closing')
        <x-ui.panel>
            <x-ui.filters
                id="accounting-report-filters"
                target="branch,from,to,account,kind,search,status,source"
                :reset="['branch' => '', 'from' => $defaultFrom, 'to' => $defaultTo, 'account' => '', 'kind' => '', 'search' => '', 'status' => '', 'source' => '']"
            >
                <x-slot:search>
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        icon="magnifying-glass"
                        label="بحث في التقرير"
                        :placeholder="match($screen) {
                            'ledger' => 'ابحث برقم القيد أو كود/اسم الحساب أو البيان…',
                            'ppv' => 'ابحث برقم الفاتورة أو اسم المورد…',
                            'grni', 'reconciliation' => 'ابحث باسم بند المصالحة…',
                            default => 'ابحث بكود الحساب أو اسم الحساب…'
                        }"
                        clearable
                    />
                </x-slot:search>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <flux:select wire:model.live="branch" label="نطاق التقرير">
                        <option value="">إجمالي الشركة (كل الفروع المصرح بها)</option>
                        @foreach($branches as $item)
                            <option value="{{ $item['id'] }}">{{ $item['name'] }}</option>
                        @endforeach
                    </flux:select>

                    @if(!in_array($screen, ['balance-sheet', 'grni', 'reconciliation'], true))
                        <flux:input wire:model.live="from" type="date" label="من تاريخ" />
                    @endif

                    <flux:input wire:model.live="to" type="date" :label="in_array($screen, ['balance-sheet', 'grni', 'reconciliation'], true) ? 'الرصيد حتى تاريخ' : 'إلى تاريخ'" />

                    @if($screen === 'ledger')
                        <flux:select wire:model.live="account" label="تصفية بحساب محدد">
                            <option value="">كل الحسابات المرحّلة</option>
                            @foreach($accounts as $acc)
                                <option value="{{ $acc->id }}">{{ $acc->code }} · {{ $acc->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model.live="source" label="مصدر القيد">
                            <option value="">كل المصادر (يدوي + آلي)</option>
                            <option value="manual">قيود يومية يدوية فقط</option>
                            <option value="system">قيود مستندات النظام فقط</option>
                        </flux:select>
                    @elseif(in_array($screen, ['trial-balance', 'income-statement', 'balance-sheet'], true))
                        <flux:select wire:model.live="kind" label="تصنيف الحساب">
                            <option value="">كل التصنيفات</option>
                            @foreach(($screen === 'income-statement' ? ['income', 'expense'] : ($screen === 'balance-sheet' ? ['asset', 'liability', 'equity'] : ['asset', 'liability', 'equity', 'income', 'expense'])) as $k)
                                <option value="{{ $k }}">{{ \App\Modules\Accounting\Application\FinancialReports::kindLabel($k) }}</option>
                            @endforeach
                        </flux:select>
                    @elseif($screen === 'reconciliation')
                        <flux:select wire:model.live="status" label="حالة المطابقة">
                            <option value="">كل البنود</option>
                            <option value="matched">المتطابقة فقط</option>
                            <option value="mismatch">الفروق القائمة فقط</option>
                        </flux:select>
                    @endif
                </div>

                {{-- اختصارات زمنية سريعة + فترات محاسبية مسجلة --}}
                <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-3 text-xs">
                    <span class="me-1 font-medium text-slate-500">اختصارات الفترة:</span>
                    @if(!in_array($screen, ['balance-sheet', 'grni', 'reconciliation'], true))
                        <flux:button type="button" size="xs" variant="ghost" wire:click="applyDatePreset('today')">اليوم</flux:button>
                        <flux:button type="button" size="xs" variant="ghost" wire:click="applyDatePreset('month')">هذا الشهر</flux:button>
                        <flux:button type="button" size="xs" variant="ghost" wire:click="applyDatePreset('quarter')">هذا الربع</flux:button>
                        <flux:button type="button" size="xs" variant="ghost" wire:click="applyDatePreset('year')">هذه السنة</flux:button>
                        <flux:button type="button" size="xs" variant="ghost" wire:click="applyDatePreset('all')">منذ البداية</flux:button>
                    @else
                        <flux:button type="button" size="xs" variant="ghost" wire:click="applyDatePreset('today')">تاريخ اليوم</flux:button>
                    @endif

                    @if($periods->isNotEmpty())
                        <span class="mx-1 text-slate-300">|</span>
                        <span class="me-1 font-medium text-slate-500">فترات مسجلة:</span>
                        @foreach($periods as $p)
                            <flux:button type="button" size="xs" variant="outline" wire:click="applyPeriodRange({{ $p->id }})">
                                {{ $p->name }} ({{ $p->status === 'open' ? 'مفتوحة' : 'مغلقة' }})
                            </flux:button>
                        @endforeach
                    @endif
                </div>
            </x-ui.filters>
        </x-ui.panel>
    @endif

    @if($filtersValid && $data !== null)
        {{-- بطاقات الملخص السريع (KPIs) حسب نوع التقرير --}}
        @if($screen === 'ledger')
            <div class="erp-kpi-summary grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-erp.stat-card label="عدد السطور المرحّلة" :value="(string) count($data['rows'])" icon="list-bullet" tone="blue" />
                <x-erp.stat-card label="إجمالي المدين" :value="$data['debit'].' ج.م'" icon="arrow-up-right" tone="blue" />
                <x-erp.stat-card label="إجمالي الدائن" :value="$data['credit'].' ج.م'" icon="arrow-down-left" tone="purple" />
                <x-erp.stat-card label="صافي الحركة (مدين - دائن)" :value="$data['net'].' ج.م'" icon="scale" :tone="bccomp($data['net'], '0.00', 2) === 0 ? 'emerald' : 'amber'" />
            </div>
        @elseif($screen === 'trial-balance')
            <div class="erp-kpi-summary grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-erp.stat-card label="عدد الحسابات النشطة" :value="(string) count($data['rows'])" icon="hashtag" tone="blue" />
                <x-erp.stat-card label="إجمالي المدين" :value="$data['debit'].' ج.م'" icon="arrow-up-right" tone="blue" />
                <x-erp.stat-card label="إجمالي الدائن" :value="$data['credit'].' ج.م'" icon="arrow-down-left" tone="purple" />
                <x-erp.stat-card label="حالة التوازن (الفرق)" :value="($data['balanced'] ? 'متوازن · ' : 'فرق · ').$data['difference'].' ج.م'" :icon="$data['balanced'] ? 'check-circle' : 'exclamation-triangle'" :tone="$data['balanced'] ? 'emerald' : 'red'" />
            </div>
        @elseif($screen === 'income-statement')
            @php $incSummary = $data['summary'] ?? []; @endphp
            <div class="erp-kpi-summary grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-erp.stat-card label="إجمالي الإيرادات" :value="($incSummary['total_income'] ?? '0.00').' ج.م'" icon="arrow-trending-up" tone="emerald" />
                <x-erp.stat-card label="إجمالي المصروفات والتكاليف" :value="($incSummary['total_expense'] ?? '0.00').' ج.م'" icon="arrow-trending-down" tone="amber" />
                <x-erp.stat-card
                    :label="($incSummary['profitable'] ?? true) ? 'صافي الربح للفترة' : 'صافي الخسارة للفترة'"
                    :value="($incSummary['net_profit'] ?? '0.00').' ج.م'"
                    :icon="($incSummary['profitable'] ?? true) ? 'check-badge' : 'exclamation-circle'"
                    :tone="($incSummary['profitable'] ?? true) ? 'emerald' : 'red'"
                />
                <x-erp.stat-card
                    label="هامش صافي الربح"
                    :value="isset($incSummary['profit_margin']) && $incSummary['profit_margin'] !== null ? $incSummary['profit_margin'].'%' : 'غير متاح'"
                    icon="chart-pie"
                    :tone="($incSummary['profitable'] ?? true) ? 'blue' : 'red'"
                />
            </div>
        @elseif($screen === 'balance-sheet')
            @php $bsSummary = $data['summary'] ?? []; @endphp
            <div class="erp-kpi-summary grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <x-erp.stat-card label="إجمالي الأصول" :value="($bsSummary['total_assets'] ?? '0.00').' ج.م'" icon="building-library" tone="blue" />
                <x-erp.stat-card label="إجمالي الالتزامات" :value="($bsSummary['total_liabilities'] ?? '0.00').' ج.م'" icon="clipboard-document-list" tone="amber" />
                <x-erp.stat-card label="حقوق الملكية المسجلة" :value="($bsSummary['total_equity'] ?? '0.00').' ج.م'" icon="shield-check" tone="purple" />
                <x-erp.stat-card label="صافي أرباح/خسائر الفترة الجارية" :value="($bsSummary['current_earnings'] ?? '0.00').' ج.م'" icon="arrow-trending-up" :tone="bccomp($bsSummary['current_earnings'] ?? '0.00', '0.00', 2) >= 0 ? 'emerald' : 'red'" />
                <x-erp.stat-card label="توازن المركز المالي (الأصول = الالتزامات + الملكية)" :value="(($bsSummary['equation_balanced'] ?? true) ? 'متوازن · ' : 'فرق · ').($bsSummary['equation_difference'] ?? '0.00').' ج.م'" :icon="($bsSummary['equation_balanced'] ?? true) ? 'check-circle' : 'exclamation-triangle'" :tone="($bsSummary['equation_balanced'] ?? true) ? 'emerald' : 'red'" />
            </div>
        @elseif($screen === 'ppv')
            <div class="erp-kpi-summary grid gap-3 sm:grid-cols-3">
                <x-erp.stat-card label="إجمالي استلامات بانتظار الفوترة (GRNI)" :value="($data['grni_total'] ?? '0.00').' ج.م'" icon="inbox-stack" tone="blue" />
                <x-erp.stat-card label="إجمالي فروق أسعار الشراء (PPV)" :value="($data['ppv_total'] ?? '0.00').' ج.م'" icon="arrows-right-left" :tone="bccomp($data['ppv_total'] ?? '0.00', '0.00', 2) === 0 ? 'emerald' : 'amber'" />
                <x-erp.stat-card label="إجمالي الفواتير المشمولة" :value="($data['invoice_total'] ?? '0.00').' ج.م'" icon="document-text" tone="purple" />
            </div>
        @elseif(in_array($screen, ['grni', 'reconciliation'], true))
            <div class="erp-kpi-summary grid gap-3 sm:grid-cols-3">
                <x-erp.stat-card label="بنود متطابقة" :value="(string) ($data['matched_count'] ?? 0).' من '.(string) ($data['total_count'] ?? 0)" icon="check-circle" tone="emerald" />
                <x-erp.stat-card label="بنود بها فروق قائمة" :value="(string) ($data['mismatched_count'] ?? 0)" icon="exclamation-triangle" :tone="($data['mismatched_count'] ?? 0) === 0 ? 'emerald' : 'red'" />
                <x-erp.stat-card label="إجمالي الفروق الرقابية" :value="($data['diff_total'] ?? '0.00').' ج.م'" icon="scale" :tone="($data['all_ok'] ?? false) ? 'emerald' : 'red'" />
            </div>
        @elseif($screen === 'closing')
            <div class="erp-kpi-summary grid gap-3 sm:grid-cols-3">
                <x-erp.stat-card label="الفترات المفتوحة" :value="(string) ($data['open_count'] ?? 0)" icon="lock-open" tone="blue" />
                <x-erp.stat-card label="الفترات المغلقة رسمياً" :value="(string) ($data['closed_count'] ?? 0)" icon="lock-closed" tone="emerald" />
                <x-erp.stat-card label="قيود غير مرحّلة في الفترات المفتوحة" :value="(string) ($data['unposted_total'] ?? 0)" icon="document-magnifying-glass" :tone="($data['unposted_total'] ?? 0) === 0 ? 'emerald' : 'amber'" />
            </div>
        @endif

        {{-- جدول التقرير الرئيسي --}}
        <x-ui.panel>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-slate-900">{{ $nav[$screen] }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        @if($screen === 'closing')
                            يتطلب الإقفال عدم وجود أي قيود غير مرحّلة داخل الفترة وتطابق جميع حسابات المراقبة في كل الفروع.
                        @elseif(in_array($screen, ['balance-sheet', 'grni', 'reconciliation'], true))
                            الرصيد التراكمي المرحّل حتى تاريخ <bdi dir="ltr" class="font-semibold text-slate-700">{{ $to ?: $defaultTo }}</bdi> · العملة: ج.م
                        @else
                            الفترة من <bdi dir="ltr" class="font-semibold text-slate-700">{{ $from ?: 'البداية' }}</bdi> إلى <bdi dir="ltr" class="font-semibold text-slate-700">{{ $to ?: $defaultTo }}</bdi> · العملة: ج.م
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($screen === 'ledger' && $account !== '')
                        <flux:button size="sm" variant="ghost" wire:click="$set('account', '')">عرض كل الحسابات</flux:button>
                    @endif
                    @if(in_array($screen, ['ledger', 'trial-balance', 'income-statement', 'balance-sheet', 'grni', 'ppv', 'reconciliation'], true))
                        @php
                            $exportParams = array_filter([
                                'report' => $screen,
                                'branch' => $branch ?: null,
                                'from' => $from ?: null,
                                'to' => $to ?: null,
                                'account' => $account ?: null,
                                'kind' => $kind ?: null,
                                'search' => $search ?: null,
                                'status' => $status ?: null,
                                'source' => $source ?: null,
                            ], fn ($v) => $v !== null);
                        @endphp
                        <flux:button :href="route('accounting.reports.export', array_merge($exportParams, ['format' => 'xlsx']))" size="sm" icon="arrow-down-tray">تصدير Excel</flux:button>
                        <flux:button :href="route('accounting.reports.export', array_merge($exportParams, ['format' => 'pdf']))" size="sm" icon="document-arrow-down">تصدير PDF</flux:button>
                    @endif
                </div>
            </div>

            <div class="overflow-x-auto">
                <x-ui.responsive-table :label="$nav[$screen]">
                    <table role="table" class="w-full text-sm">
                        @if($screen === 'ledger')
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th role="columnheader">التاريخ</th>
                                    <th role="columnheader">رقم القيد</th>
                                    <th role="columnheader">المصدر</th>
                                    <th role="columnheader">الحساب</th>
                                    <th role="columnheader">البيان</th>
                                    <th role="columnheader">مدين</th>
                                    <th role="columnheader">دائن</th>
                                    <th role="columnheader">الرصيد التراكمي</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                @if(($data['opening_balance'] ?? null) !== null && $from !== '')
                                    <tr role="row" class="bg-blue-50/60 font-semibold text-blue-950">
                                        <td role="cell" data-label="التاريخ"><div class="tabular-nums">قبل {{ $from }}</div></td>
                                        <td role="cell" data-label="رقم القيد"><div>—</div></td>
                                        <td role="cell" data-label="المصدر"><div><span class="rounded border border-blue-200 bg-blue-100/70 px-2 py-0.5 text-xs">رصيد سابق</span></div></td>
                                        <td role="cell" data-label="الحساب">
                                            <div>
                                                @if($data['selected_account'] ?? null)
                                                    <bdi dir="ltr">{{ $data['selected_account']->code }}</bdi> · {{ $data['selected_account']->name }}
                                                @else
                                                    الحساب المحدد
                                                @endif
                                            </div>
                                        </td>
                                        <td role="cell" data-label="البيان"><div>رصيد مرحّل ما قبل بداية الفترة المحددة</div></td>
                                        <td role="cell" data-label="مدين"><div class="tabular-nums">{{ bccomp($data['opening_balance'], '0.00', 2) > 0 ? $data['opening_balance'] : '0.00' }}</div></td>
                                        <td role="cell" data-label="دائن"><div class="tabular-nums">{{ bccomp($data['opening_balance'], '0.00', 2) < 0 ? bcsub('0.00', $data['opening_balance'], 2) : '0.00' }}</div></td>
                                        <td role="cell" data-label="الرصيد التراكمي"><div class="tabular-nums" dir="ltr">{{ $data['opening_balance'] }}</div></td>
                                    </tr>
                                @endif
                                @forelse($data['rows'] as $row)
                                    <tr role="row">
                                        <td role="cell" data-label="التاريخ"><div class="tabular-nums">{{ $row->journal_date }}</div></td>
                                        <td role="cell" data-label="رقم القيد" dir="ltr">
                                            <div class="flex items-center gap-1.5">
                                                <span class="font-medium text-slate-800">{{ $row->official_number }}</span>
                                                <a href="{{ route('accounting.journals.pdf', $row->journal_id) }}" class="text-xs text-blue-600 hover:underline" title="تنزيل PDF القيد">PDF</a>
                                            </div>
                                        </td>
                                        <td role="cell" data-label="المصدر">
                                            <div>
                                                <span class="inline-flex items-center rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-medium text-slate-700">
                                                    {{ $row->source_label ?? 'قيد مرحّل' }}
                                                </span>
                                            </div>
                                        </td>
                                        <td role="cell" data-label="الحساب">
                                            <div>
                                                <button type="button" wire:click="$set('account', '{{ $row->account_id }}')" class="text-start font-medium text-blue-700 hover:underline" title="تصفية الأستاذ العام بهذا الحساب">
                                                    <bdi dir="ltr">{{ $row->account_code }}</bdi> · {{ $row->account_name }}
                                                </button>
                                            </div>
                                        </td>
                                        <td role="cell" data-label="البيان"><div>{{ $row->description ?: ($row->journal_description ?? '—') }}</div></td>
                                        <td role="cell" data-label="مدين"><div class="tabular-nums">{{ $row->debit }}</div></td>
                                        <td role="cell" data-label="دائن"><div class="tabular-nums">{{ $row->credit }}</div></td>
                                        <td role="cell" data-label="الرصيد التراكمي"><div class="font-semibold tabular-nums" dir="ltr">{{ $row->running_balance }}</div></td>
                                    </tr>
                                @empty
                                    <tr role="row"><td colspan="8"><x-ui.empty-state title="لا توجد حركات مرحّلة" description="غيّر النطاق أو الفترة أو الحساب المختار لعرض حركات الأستاذ العام." /></td></tr>
                                @endforelse
                            </tbody>
                            @if(count($data['rows']) > 0 || ($data['opening_balance'] ?? null) !== null)
                                <tfoot>
                                    <tr class="border-t-2 border-slate-200 bg-slate-50 font-bold text-slate-900">
                                        <td colspan="5" class="p-3">إجمالي حركات الفترة ({{ count($data['rows']) }} سطر){{ ($data['opening_balance'] ?? null) !== null ? ' · الرصيد الختامي شامل الرصيد السابق' : '' }}</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['debit'] }} ج.م</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['credit'] }} ج.م</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['closing_balance'] ?? $data['net'] }} ج.م</td>
                                    </tr>
                                </tfoot>
                            @endif

                        @elseif($screen === 'trial-balance')
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th role="columnheader">الكود</th>
                                    <th role="columnheader">الحساب</th>
                                    <th role="columnheader">التصنيف</th>
                                    <th role="columnheader">افتتاحي مدين</th>
                                    <th role="columnheader">افتتاحي دائن</th>
                                    <th role="columnheader">حركة مدين</th>
                                    <th role="columnheader">حركة دائن</th>
                                    <th role="columnheader">ختامي مدين</th>
                                    <th role="columnheader">ختامي دائن</th>
                                    <th role="columnheader">حركات الحساب</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                @forelse($data['rows'] as $row)
                                    <tr role="row">
                                        <td role="cell" data-label="الكود" dir="ltr"><div class="font-mono font-medium">{{ $row->account_code }}</div></td>
                                        <td role="cell" data-label="الحساب"><div class="font-medium text-slate-900">{{ $row->account_name }}</div></td>
                                        <td role="cell" data-label="التصنيف">
                                            <div>
                                                <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium {{ $kindBadgeColors[$row->kind] ?? 'bg-slate-100 text-slate-700 border-slate-200' }}">
                                                    {{ $row->kind_label ?? \App\Modules\Accounting\Application\FinancialReports::kindLabel($row->kind) }}
                                                </span>
                                            </div>
                                        </td>
                                        <td role="cell" data-label="افتتاحي مدين"><div class="tabular-nums text-slate-600">{{ $row->opening_debit ?? '0.00' }}</div></td>
                                        <td role="cell" data-label="افتتاحي دائن"><div class="tabular-nums text-slate-600">{{ $row->opening_credit ?? '0.00' }}</div></td>
                                        <td role="cell" data-label="حركة مدين"><div class="tabular-nums font-medium">{{ $row->debit }}</div></td>
                                        <td role="cell" data-label="حركة دائن"><div class="tabular-nums font-medium">{{ $row->credit }}</div></td>
                                        <td role="cell" data-label="ختامي مدين"><div class="font-semibold tabular-nums text-blue-950">{{ $row->closing_debit ?? '0.00' }}</div></td>
                                        <td role="cell" data-label="ختامي دائن"><div class="font-semibold tabular-nums text-blue-950">{{ $row->closing_credit ?? '0.00' }}</div></td>
                                        <td role="cell" data-label="حركات الحساب">
                                            <div>
                                                <flux:button
                                                    size="xs"
                                                    variant="ghost"
                                                    :href="route('accounting.ledger', array_filter(['account' => $row->account_id, 'branch' => $branch ?: null, 'from' => $from ?: null, 'to' => $to ?: null]))"
                                                    wire:navigate
                                                >كشف الأستاذ</flux:button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr role="row"><td colspan="10"><x-ui.empty-state title="لا توجد أرصدة" description="لا توجد قيود مرحّلة ضمن النطاق المحدد." /></td></tr>
                                @endforelse
                            </tbody>
                            @if(count($data['rows']) > 0)
                                <tfoot>
                                    <tr class="border-t-2 border-slate-200 bg-slate-50 font-bold text-slate-900">
                                        <td colspan="3" class="p-3">إجمالي ميزان المراجعة ({{ ($data['balanced'] ?? true) ? 'متوازن' : 'غير متوازن' }})</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['opening_debit'] ?? '0.00' }}</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['opening_credit'] ?? '0.00' }}</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['debit'] }}</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['credit'] }}</td>
                                        <td class="p-3 tabular-nums text-blue-950" dir="ltr">{{ $data['closing_debit'] ?? '0.00' }}</td>
                                        <td class="p-3 tabular-nums text-blue-950" dir="ltr">{{ $data['closing_credit'] ?? '0.00' }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            @endif

                        @elseif(in_array($screen, ['income-statement', 'balance-sheet'], true))
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th role="columnheader">الكود</th>
                                    <th role="columnheader">الحساب</th>
                                    <th role="columnheader">التصنيف</th>
                                    <th role="columnheader">مدين</th>
                                    <th role="columnheader">دائن</th>
                                    <th role="columnheader">الرصيد الطبيعي (ج.م)</th>
                                    <th role="columnheader">النسبة من المجموعة</th>
                                    <th role="columnheader">حركات الحساب</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                @if(count($data['rows']) === 0)
                                    <tr role="row"><td colspan="8"><x-ui.empty-state title="لا توجد أرصدة" description="لا توجد قيود مرحّلة ضمن النطاق المحدد." /></td></tr>
                                @else
                                    @foreach(($data['summary']['by_kind'] ?? []) as $groupKind => $groupMeta)
                                        @if(($groupMeta['count'] ?? 0) > 0)
                                            <tr role="row" class="bg-slate-100/80 font-bold text-slate-800">
                                                <td colspan="8" class="px-3 py-2 text-xs">
                                                    قسم {{ $groupMeta['label'] }} ({{ $groupMeta['count'] }} حساب)
                                                </td>
                                            </tr>
                                            @foreach($groupMeta['rows'] as $row)
                                                <tr role="row">
                                                    <td role="cell" data-label="الكود" dir="ltr"><div class="font-mono font-medium">{{ $row->account_code }}</div></td>
                                                    <td role="cell" data-label="الحساب"><div class="font-medium text-slate-900">{{ $row->account_name }}</div></td>
                                                    <td role="cell" data-label="التصنيف">
                                                        <div>
                                                            <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium {{ $kindBadgeColors[$row->kind] ?? 'bg-slate-100 text-slate-700 border-slate-200' }}">
                                                                {{ $row->kind_label }}
                                                            </span>
                                                        </div>
                                                    </td>
                                                    <td role="cell" data-label="مدين"><div class="tabular-nums">{{ $row->debit }}</div></td>
                                                    <td role="cell" data-label="دائن"><div class="tabular-nums">{{ $row->credit }}</div></td>
                                                    <td role="cell" data-label="الرصيد الطبيعي"><div class="font-semibold tabular-nums text-blue-950" dir="ltr">{{ $row->natural_balance }}</div></td>
                                                    <td role="cell" data-label="النسبة من المجموعة">
                                                        <div class="flex items-center gap-2">
                                                            <span class="w-12 text-xs font-semibold tabular-nums text-slate-600" dir="ltr">{{ $row->share_percent ?? '0.0' }}%</span>
                                                            <progress class="h-2 w-20 accent-blue-600" value="{{ (float) ($row->share_percent ?? 0) }}" max="100"></progress>
                                                        </div>
                                                    </td>
                                                    <td role="cell" data-label="حركات الحساب">
                                                        <div>
                                                            <flux:button
                                                                size="xs"
                                                                variant="ghost"
                                                                :href="route('accounting.ledger', array_filter(['account' => $row->account_id, 'branch' => $branch ?: null, 'from' => $from ?: null, 'to' => $to ?: null]))"
                                                                wire:navigate
                                                            >كشف الأستاذ</flux:button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                            <tr role="row" class="border-b-2 border-slate-200 bg-slate-50/70 font-semibold text-slate-800">
                                                <td colspan="3" class="p-2.5 text-xs">إجمالي قسم {{ $groupMeta['label'] }}</td>
                                                <td class="p-2.5 tabular-nums" dir="ltr">{{ $groupMeta['debit'] }}</td>
                                                <td class="p-2.5 tabular-nums" dir="ltr">{{ $groupMeta['credit'] }}</td>
                                                <td class="p-2.5 font-bold tabular-nums text-blue-950" dir="ltr">{{ $groupMeta['natural'] }} ج.م</td>
                                                <td class="p-2.5 text-xs tabular-nums" dir="ltr">100%</td>
                                                <td></td>
                                            </tr>
                                        @endif
                                    @endforeach
                                @endif
                            </tbody>
                            @if(count($data['rows']) > 0)
                                <tfoot>
                                    @if($screen === 'income-statement')
                                        <tr class="border-t-2 border-slate-300 bg-slate-100 font-bold text-slate-950">
                                            <td colspan="5" class="p-3">
                                                صافي نتيجة قائمة الدخل ({{ ($data['summary']['profitable'] ?? true) ? 'صافي ربح' : 'صافي خسارة' }})
                                            </td>
                                            <td class="p-3 text-base tabular-nums {{ ($data['summary']['profitable'] ?? true) ? 'text-emerald-800' : 'text-red-800' }}" dir="ltr">
                                                {{ $data['summary']['net_profit'] ?? '0.00' }} ج.م
                                            </td>
                                            <td colspan="2" class="p-3 text-xs text-slate-600">
                                                @if(isset($data['summary']['profit_margin']) && $data['summary']['profit_margin'] !== null)
                                                    هامش الربح: <bdi dir="ltr">{{ $data['summary']['profit_margin'] }}%</bdi>
                                                @endif
                                            </td>
                                        </tr>
                                    @else
                                        <tr class="border-t border-slate-200 bg-blue-50/50 font-semibold text-slate-800">
                                            <td colspan="5" class="p-2.5 text-xs">صافي أرباح / (خسائر) الفترة الجارية المضاف لحقوق الملكية</td>
                                            <td class="p-2.5 tabular-nums" dir="ltr">{{ $data['summary']['current_earnings'] ?? '0.00' }} ج.م</td>
                                            <td colspan="2"></td>
                                        </tr>
                                        <tr class="border-t-2 border-slate-300 bg-slate-100 font-bold text-slate-950">
                                            <td colspan="5" class="p-3">إجمالي الالتزامات وحقوق الملكية (شاملاً أرباح الفترة)</td>
                                            <td class="p-3 text-base tabular-nums text-blue-950" dir="ltr">{{ $data['summary']['liabilities_plus_equity'] ?? '0.00' }} ج.م</td>
                                            <td colspan="2" class="p-3 text-xs {{ ($data['summary']['equation_balanced'] ?? true) ? 'text-emerald-700' : 'text-red-700' }}">
                                                {{ ($data['summary']['equation_balanced'] ?? true) ? 'متطابق مع إجمالي الأصول' : 'فرق معادلة الميزانية: '.($data['summary']['equation_difference'] ?? '0.00').' ج.م' }}
                                            </td>
                                        </tr>
                                    @endif
                                </tfoot>
                            @endif

                        @elseif($screen === 'ppv')
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th role="columnheader">الفاتورة</th>
                                    <th role="columnheader">المورد</th>
                                    <th role="columnheader">التاريخ</th>
                                    <th role="columnheader">استلامات بانتظار الفوترة</th>
                                    <th role="columnheader">فروق سعر الشراء</th>
                                    <th role="columnheader">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                @forelse($data['rows'] as $row)
                                    <tr role="row">
                                        <td role="cell" data-label="الفاتورة" dir="ltr"><div class="font-medium">{{ $row['official_number'] }}</div></td>
                                        <td role="cell" data-label="المورد"><div>{{ $row['supplier_name'] }}</div></td>
                                        <td role="cell" data-label="التاريخ"><div class="tabular-nums">{{ $row['invoice_date'] }}</div></td>
                                        <td role="cell" data-label="استلامات بانتظار الفوترة"><div class="tabular-nums">{{ $row['grni_amount'] }}</div></td>
                                        <td role="cell" data-label="فروق سعر الشراء"><div class="font-semibold tabular-nums">{{ $row['ppv_amount'] }}</div></td>
                                        <td role="cell" data-label="الإجمالي"><div class="tabular-nums">{{ $row['total_amount'] }}</div></td>
                                    </tr>
                                @empty
                                    <tr role="row"><td colspan="6"><x-ui.empty-state title="لا توجد فروق أسعار" description="تظهر فواتير الموردين المرحّلة ذات فروق الأسعار هنا عند وجودها." /></td></tr>
                                @endforelse
                            </tbody>
                            @if(count($data['rows']) > 0)
                                <tfoot>
                                    <tr class="border-t-2 border-slate-200 bg-slate-50 font-bold text-slate-900">
                                        <td colspan="3" class="p-3">الإجمالي ({{ count($data['rows']) }} فاتورة)</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['grni_total'] }} ج.م</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['ppv_total'] }} ج.م</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['invoice_total'] }} ج.م</td>
                                    </tr>
                                </tfoot>
                            @endif

                        @elseif(in_array($screen, ['grni', 'reconciliation'], true))
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th role="columnheader">المصالحة الرقابية</th>
                                    <th role="columnheader">رصيد الأستاذ العام</th>
                                    <th role="columnheader">رصيد السجل المصدري</th>
                                    <th role="columnheader">الفرق</th>
                                    <th role="columnheader">النتيجة</th>
                                    <th role="columnheader">السجل المصدري</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                @forelse($data['rows'] as $row)
                                    <tr role="row">
                                        <td role="cell" data-label="المصالحة الرقابية"><div class="font-medium text-slate-900">{{ $row['label'] }}</div></td>
                                        <td role="cell" data-label="رصيد الأستاذ العام"><div class="tabular-nums" dir="ltr">{{ $row['gl'] }}</div></td>
                                        <td role="cell" data-label="رصيد السجل المصدري"><div class="tabular-nums" dir="ltr">{{ $row['source'] }}</div></td>
                                        <td role="cell" data-label="الفرق"><div class="font-semibold tabular-nums {{ $row['ok'] ? 'text-slate-700' : 'text-red-700' }}" dir="ltr">{{ $row['difference'] }}</div></td>
                                        <td role="cell" data-label="النتيجة">
                                            <div>
                                                <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold {{ $row['ok'] ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700' }}">
                                                    {{ $row['ok'] ? 'متطابق' : 'فرق قائم' }}
                                                </span>
                                            </div>
                                        </td>
                                        <td role="cell" data-label="السجل المصدري">
                                            <div>
                                                @php $linkMeta = $reconciliationLinks[$row['key']] ?? null; @endphp
                                                @if($linkMeta && \Illuminate\Support\Facades\Route::has($linkMeta['route']))
                                                    <flux:button size="xs" variant="ghost" :href="route($linkMeta['route'])" wire:navigate>{{ $linkMeta['label'] }}</flux:button>
                                                @else
                                                    <span class="text-xs text-slate-400">—</span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr role="row"><td colspan="6"><x-ui.empty-state title="لا توجد بنود مطابقة" description="لا توجد بنود مطابقة تطابق الفلتر الحالي." /></td></tr>
                                @endforelse
                            </tbody>
                            @if(count($data['rows']) > 0)
                                <tfoot>
                                    <tr class="border-t-2 border-slate-200 bg-slate-50 font-bold text-slate-900">
                                        <td class="p-3">الإجمالي الرقابي</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['gl_total'] }} ج.م</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['source_total'] }} ج.م</td>
                                        <td class="p-3 tabular-nums" dir="ltr">{{ $data['diff_total'] }} ج.م</td>
                                        <td colspan="2" class="p-3 text-xs {{ ($data['all_ok'] ?? false) ? 'text-emerald-700' : 'text-red-700' }}">
                                            {{ ($data['all_ok'] ?? false) ? 'جميع الحسابات الرقابية متطابقة مع السجلات الفرعية' : 'يوجد فرق بين الأستاذ العام والسجلات الفرعية يجب تسويته قبل الإقفال' }}
                                        </td>
                                    </tr>
                                </tfoot>
                            @endif

                        @else
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th role="columnheader">الفترة</th>
                                    <th role="columnheader">البداية</th>
                                    <th role="columnheader">النهاية</th>
                                    <th role="columnheader">القيود المرحّلة</th>
                                    <th role="columnheader">قيود غير مرحّلة</th>
                                    <th role="columnheader">الحالة</th>
                                    <th role="columnheader">توثيق الإقفال</th>
                                    <th role="columnheader">الإجراء</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                @forelse($data['rows'] as $row)
                                    <tr role="row">
                                        <td role="cell" data-label="الفترة"><div class="font-semibold text-slate-900">{{ $row->name }}</div></td>
                                        <td role="cell" data-label="البداية"><div class="tabular-nums">{{ $row->starts_on }}</div></td>
                                        <td role="cell" data-label="النهاية"><div class="tabular-nums">{{ $row->ends_on }}</div></td>
                                        <td role="cell" data-label="القيود المرحّلة"><div class="tabular-nums">{{ $row->posted_count ?? 0 }}</div></td>
                                        <td role="cell" data-label="قيود غير مرحّلة">
                                            <div>
                                                @if(($row->unposted_count ?? 0) > 0)
                                                    <span class="inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                                        {{ $row->unposted_count }} قيد غير مرحّل
                                                    </span>
                                                @else
                                                    <span class="text-xs font-medium text-emerald-700">لا يوجد (جاهز)</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td role="cell" data-label="الحالة">
                                            <div>
                                                <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold {{ $row->status === 'open' ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-slate-200 bg-slate-100 text-slate-700' }}">
                                                    {{ $row->status === 'open' ? 'مفتوحة' : 'مغلقة رسمياً' }}
                                                </span>
                                            </div>
                                        </td>
                                        <td role="cell" data-label="توثيق الإقفال">
                                            <div class="text-xs text-slate-600">
                                                @if(!empty($row->closure_sha256))
                                                    <div>بواسطة: <x-erp.user-name :id="$row->closed_by" /></div>
                                                    <div class="mt-0.5 font-mono text-[11px] text-slate-500" dir="ltr" title="{{ $row->closure_sha256 }}">SHA: {{ substr((string) $row->closure_sha256, 0, 12) }}…</div>
                                                @else
                                                    <span class="text-slate-400">—</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td role="cell" data-label="الإجراء">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <flux:button size="xs" variant="ghost" :href="route('accounting.reconciliation', ['to' => $row->ends_on])" wire:navigate>مراجعة المطابقات</flux:button>
                                                @if(!empty($row->closure_sha256))
                                                    <flux:button size="xs" variant="outline" wire:click="inspectClosure({{ $row->id }})">شهادة الإقفال</flux:button>
                                                @endif
                                                @if($row->status === 'open' && $access->allows(auth()->id(), 'period_close'))
                                                    <flux:button
                                                        wire:click="close({{ $row->id }})"
                                                        wire:confirm="لن يتم الإقفال إلا بعد نجاح المصالحات وعدم وجود قيود غير مرحّلة. متابعة؟"
                                                        size="sm"
                                                        variant="primary"
                                                        :disabled="($row->unposted_count ?? 0) > 0"
                                                    >إقفال بعد المصالحة</flux:button>
                                                @elseif($row->status === 'closed' && $access->allows(auth()->id(), 'period_reopen'))
                                                    <flux:button size="xs" variant="ghost" wire:click="openReopenModal({{ $row->id }})">إعادة فتح الفترة</flux:button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr role="row"><td colspan="8" class="p-8"><x-ui.empty-state title="لا توجد فترات مالية بعد" description="افتح فترة مالية من صفحة «الفترات المالية»، ثم تظهر هنا لتراجعها وتقفلها بعد اكتمال المطابقات." icon="calendar-days" /></td></tr>
                                @endforelse
                            </tbody>
                        @endif
                    </table>
                </x-ui.responsive-table>
            </div>
        </x-ui.panel>

        @if($screen === 'closing')
            <flux:modal wire:model.self="showReopenModal" class="max-w-lg">
                <form wire:submit="confirmReopenPeriod" class="space-y-4">
                    <div>
                        <flux:heading size="lg">إعادة فتح الفترة المالية: {{ $reopenPeriodName }}</flux:heading>
                        <p class="mt-1 text-xs text-slate-500">إعادة فتح فترة مغلقة إجراء استثنائي يُسجّل في سجل المراجعة الإلزامي مع الاحتفاظ بشهادة الإقفال السابقة.</p>
                    </div>
                    <flux:textarea
                        wire:model="reopenReason"
                        label="سبب إعادة فتح الفترة (إلزامي — 5 أحرف على الأقل)"
                        rows="3"
                        placeholder="اكتب السبب الرقابي أو المحاسبي لإعادة فتح الفترة…"
                        required
                    />
                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button type="button" variant="ghost" wire:click="$set('showReopenModal', false)">إلغاء</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">تأكيد إعادة الفتح</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal wire:model.self="showEvidenceModal" class="max-w-2xl">
                @if($inspectedClosure)
                    <div class="space-y-4">
                        <div>
                            <flux:heading size="lg">شهادة إقفال الفترة المالية · {{ $inspectedClosure['period_name'] }}</flux:heading>
                            <p class="mt-1 text-xs text-slate-500">لقطة المطابقات الرقابية الموثقة وقت الإقفال الرسمي للفترة.</p>
                        </div>
                        <dl class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs sm:grid-cols-2">
                            <div><dt class="text-slate-500">نطاق الفترة</dt><dd class="mt-0.5 font-semibold" dir="ltr">{{ $inspectedClosure['starts_on'] }} → {{ $inspectedClosure['cutoff'] }}</dd></div>
                            <div><dt class="text-slate-500">أُقفلت بواسطة</dt><dd class="mt-0.5 font-semibold"><x-erp.user-name :id="$inspectedClosure['closed_by']" /></dd></div>
                            <div><dt class="text-slate-500">توقيت الإقفال</dt><dd class="mt-0.5 font-mono" dir="ltr">{{ $inspectedClosure['closed_at'] }}</dd></div>
                            <div><dt class="text-slate-500">عدد الفروع المشمولة</dt><dd class="mt-0.5 font-semibold">{{ count($inspectedClosure['branches']) }} فرع</dd></div>
                            <div class="sm:col-span-2"><dt class="text-slate-500">البصمة الرقمية للشهادة (SHA-256)</dt><dd class="mt-0.5 break-all font-mono text-[11px] text-slate-700" dir="ltr">{{ $inspectedClosure['sha256'] }}</dd></div>
                        </dl>
                        <div class="flex justify-end">
                            <flux:button type="button" variant="ghost" wire:click="$set('showEvidenceModal', false)">إغلاق</flux:button>
                        </div>
                    </div>
                @endif
            </flux:modal>
        @endif
    @endif
</div>
