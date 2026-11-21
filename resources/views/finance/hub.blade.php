@php
    $acc = app(\App\Modules\Accounting\Application\AccountingAccess::class)->navigation(auth()->id());
    $fin = app(\App\Modules\ReceivablesPayables\Application\ReceivablesPayablesAccess::class)->navigation(auth()->id());
    $tre = app(\App\Modules\Treasury\Application\TreasuryAccess::class)->navigation(auth()->id());
    $daily = array_filter([
        ['financial.customer-invoices', isset($fin['customer-invoices']), 'فاتورة عميل', 'أصدر فاتورة من تسليم مؤكد وتابع المسودات والمصدرة', 'document-text'],
        ['treasury.collections', isset($tre['collections']), 'تحصيل من عميل', 'سجّل دفعة استلمتها من عميل على فواتيره', 'banknotes'],
        ['financial.supplier-invoices', isset($fin['supplier-invoices']), 'فاتورة مورد', 'سجّل فاتورة استلمتها من مورد وطابقها مع الاستلام', 'receipt-percent'],
        ['treasury.payments', isset($tre['payments']), 'سداد لمورد', 'جهّز دفعة لمورد على فواتيره المستحقة', 'arrow-up-tray'],
        ['treasury.transfers', isset($tre['transfers']), 'تحويل بين الخزينة والبنك', 'انقل مبلغًا بين حساباتك النقدية والبنكية', 'arrows-right-left'],
        ['accounting.journals', isset($acc['journals']), 'قيد يومية', 'سجّل قيدًا محاسبيًا يدويًا للمحاسب', 'pencil-square'],
    ], fn ($t) => $t[1]);
    $follow = array_filter([
        ['financial.ar', isset($fin['ar']), 'حسابات العملاء', 'كم يدين لك كل عميل'],
        ['financial.ap', isset($fin['ap']), 'حسابات الموردين', 'كم تدين لكل مورد'],
        ['financial.aging', isset($fin['aging']), 'أعمار الديون', 'ما المتأخر وبكم يومًا'],
        ['treasury.accounts', isset($tre['accounts']), 'البنك والخزينة', 'حساباتك النقدية وأرصدتها'],
        ['treasury.statements', isset($tre['statements']), 'كشوف البنك والمطابقة', 'طابق كشف البنك مع حركاتك'],
        ['financial.advances', isset($fin['advances']), 'مقدمات العملاء', 'دفعات مقدمة من العملاء'],
        ['accounting.partners', isset($acc['partners']), 'جاري الشركاء', 'مساهمات ومسحوبات ومصروفات الشركاء'],
    ], fn ($t) => $t[1]);
    $reports = array_filter([
        ['accounting.income-statement', isset($acc['income-statement']), 'قائمة الدخل', 'هل ربحت أم خسرت في فترة'],
        ['accounting.balance-sheet', isset($acc['balance-sheet']), 'الميزانية', 'ما تملكه وما عليك في تاريخ'],
        ['accounting.trial-balance', isset($acc['trial-balance']), 'ميزان المراجعة', 'أرصدة كل الحسابات للتأكد من توازنها'],
        ['accounting.ledger', isset($acc['ledger']), 'الأستاذ العام', 'كل حركات حساب بعينه'],
    ], fn ($t) => $t[1]);
    $accountant = array_filter([
        ['accounting.grni', isset($acc['grni']), 'استلامات لم تُفوتر بعد', 'بضاعة استلمتها ولم تصل فاتورتها'],
        ['accounting.ppv', isset($acc['ppv']), 'فروق أسعار الشراء', 'الفرق بين سعر الأمر وسعر الفاتورة'],
        ['accounting.reconciliation', isset($acc['reconciliation']), 'مطابقة الحسابات', 'مقارنة المخزون والذمم والخزينة بالأستاذ'],
        ['accounting.closing', isset($acc['closing']), 'إقفال الفترة المالية', 'قفل شهر بعد مراجعته'],
        ['accounting.periods', isset($acc['periods']), 'الفترات المالية', 'فتح الشهور وإدارتها'],
        ['accounting.accounts', isset($acc['accounts']), 'دليل الحسابات', 'شجرة حسابات الشركة'],
        ['accounting.setup', isset($acc['setup']), 'تجهيز النظام المالي', 'تاريخ التشغيل والضرائب والأرصدة الافتتاحية'],
    ], fn ($t) => $t[1]);
@endphp
<div data-erp-archetype="dashboard" class="erp-finance space-y-8">
    <x-page.heading title="المالية" description="ابدأ من المهمة التي تريد إنجازها، ولا تحتاج حفظ أي رقم داخلي" :breadcrumbs="['المالية']" />
    <x-erp.finance-banner />

    @if(! $daily && ! $follow && ! $reports && ! $accountant)
        <x-ui.empty-state title="لا توجد صفحات مالية متاحة لحسابك" description="اطلب من مدير النظام صلاحيات المالية وربطك بفرع." icon="lock-closed" />
    @endif

    @if($daily)
    <section aria-labelledby="fin-daily"><h2 id="fin-daily" class="mb-3 text-sm font-semibold text-slate-500">المهام اليومية</h2>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($daily as [$route, , $title, $desc, $icon])
                <a href="{{ route($route) }}" wire:navigate class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:border-blue-300 hover:shadow-sm focus-visible:outline-2 focus-visible:outline-blue-600"><span class="grid size-10 shrink-0 place-items-center rounded-lg bg-blue-50 text-blue-700"><flux:icon :name="$icon" class="size-5" /></span><span><span class="block font-semibold text-slate-900">{{ $title }}</span><span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ $desc }}</span></span></a>
            @endforeach
        </div>
    </section>
    @endif

    @foreach(['follow' => ['المتابعة: من لي ومن عليّ', $follow], 'reports' => ['التقارير المالية', $reports], 'accountant' => ['أدوات المحاسب والإقفال والإعداد', $accountant]] as $key => [$heading, $items])
        @if($items)
        <section aria-labelledby="fin-{{ $key }}"><h2 id="fin-{{ $key }}" class="mb-3 text-sm font-semibold text-slate-500">{{ $heading }}</h2>
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($items as [$route, , $title, $desc])
                    <a href="{{ route($route) }}" wire:navigate class="rounded-lg border border-slate-200 bg-white px-4 py-3 transition hover:border-blue-300 focus-visible:outline-2 focus-visible:outline-blue-600"><span class="block text-sm font-semibold text-slate-800">{{ $title }}</span><span class="block text-xs text-slate-500">{{ $desc }}</span></a>
                @endforeach
            </div>
        </section>
        @endif
    @endforeach
</div>
