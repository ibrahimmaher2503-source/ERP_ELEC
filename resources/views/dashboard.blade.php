@php
    $actor = auth()->id();
    $destinations = [];
    foreach ([
        [\App\Modules\Parties\Application\CustomerAccess::class, 'M03.customer_view', 'customers.index', 'العملاء', 'بيانات العملاء والتواصل والملفات المالية', 'user-group', 'تجاري'],
        [\App\Modules\Parties\Application\SupplierAccess::class, 'M03.supplier_view', 'suppliers.index', 'الموردون', 'دليل الموردين ومستنداتهم وحساباتهم', 'truck', 'تجاري'],
        [\App\Modules\Products\Application\ProductAccess::class, 'M02.product_view', 'products.index', 'الأصناف والمنتجات', 'الكتالوج والمواصفات والوحدات والأسعار', 'cube', 'تشغيلي'],
    ] as [$access, $permission, $route, $title, $description, $icon, $group]) {
        if (app($access)->allows($actor, $permission)) {
            $destinations[] = compact('route', 'title', 'description', 'icon', 'group');
        }
    }
    foreach ([
        [\App\Modules\Sales\Application\QuotationAccess::class, 'quotations.index', 'عروض الأسعار', 'العروض والمراجعات والاعتمادات', 'document-text'],
        [\App\Modules\Sales\Application\SalesOrderAccess::class, 'sales-orders.index', 'أوامر البيع', 'متابعة الطلبات والتسليمات', 'clipboard-document-list'],
    ] as [$access, $route, $title, $description, $icon]) {
        if (app($access)->allows($actor)) {
            $destinations[] = compact('route', 'title', 'description', 'icon') + ['group' => 'تجاري'];
        }
    }
    foreach ([
        [\App\Modules\Purchasing\Application\PurchasingAccess::class, 'purchasing', 'المشتريات', 'الاحتياجات وعروض الموردين والاستلامات', 'shopping-cart', 'تشغيلي'],
        [\App\Modules\Inventory\Application\InventoryAccess::class, 'inventory', 'المخزون', 'الأرصدة والمستودعات وحركة الأصناف', 'archive-box', 'تشغيلي'],
        [\App\Modules\Accounting\Application\AccountingAccess::class, 'accounting', 'المحاسبة', 'القيود والفترات والتقارير المالية', 'calculator', 'مالي'],
        [\App\Modules\ReceivablesPayables\Application\ReceivablesPayablesAccess::class, 'financial', 'الذمم والفواتير', 'فواتير العملاء والموردين والأرصدة', 'document-currency-dollar', 'مالي'],
        [\App\Modules\Treasury\Application\TreasuryAccess::class, 'treasury', 'الخزينة', 'الحسابات والمتحصلات والمدفوعات', 'banknotes', 'مالي'],
    ] as [$access, $prefix, $title, $description, $icon, $group]) {
        $navigation = array_filter(app($access)->navigation($actor));
        if ($navigation) {
            $route = $prefix.'.'.array_key_first($navigation);
            $destinations[] = compact('route', 'title', 'description', 'icon', 'group');
        }
    }
    foreach ([
        [\App\Modules\Projects\Application\ProjectAccess::class, 'projects_enabled', 'projects.index', 'المشروعات', 'المراحل والمهام والموارد والتقارير', 'folder'],
        [\App\Modules\Projects\Application\TenderAccess::class, 'tenders_enabled', 'tenders.dashboard', 'المناقصات', 'العطاءات والعروض والضمانات', 'document-check'],
        [\App\Modules\FieldMaintenance\Application\MaintenanceAccess::class, null, 'maintenance.dashboard', 'الصيانة الميدانية', 'طلبات الخدمة وأوامر العمل والزيارات', 'wrench-screwdriver'],
        [\App\Modules\WorkshopRepairs\Application\WorkshopAccess::class, null, 'workshops.dashboard', 'الورش والإصلاح', 'استلام المعدات والإصلاح والفحص والتسليم', 'cog-6-tooth'],
    ] as [$access, $gate, $route, $title, $description, $icon]) {
        if (($gate === null || config('release.'.$gate)) && (app($access)->branches($actor) || ($access === \App\Modules\Projects\Application\ProjectAccess::class && app($access)->query($actor)->exists()))) {
            $destinations[] = compact('route', 'title', 'description', 'icon') + ['group' => 'تشغيلي'];
        }
    }
    $organizationAccess = app(\App\Modules\Organization\Application\OrganizationAccess::class);
    $permissions = app(\App\Modules\Organization\Application\PermissionAccess::class);
    $userAccess = app(\App\Modules\Organization\Application\UserAccess::class);
    foreach ([
        ['organization.company', 'الشركة', 'هوية المنشأة وبياناتها الأساسية', 'building-office', $organizationAccess->allows(auth()->user(), 'M01.company_view')],
        ['organization.branches', 'الفروع', 'بيانات الفروع ومواقع العمل', 'building-office-2', $organizationAccess->allows(auth()->user(), 'M01.branch_admin') || $organizationAccess->allows(auth()->user(), 'M01.branch_create') || $permissions->branchIds(auth()->user(), 'M01.branch_view') !== []],
        ['organization.users', 'المستخدمون', 'الحسابات والأدوار ونطاقات الوصول', 'users', $userAccess->can(auth()->user(), 'M01.user_admin') || $userAccess->can(auth()->user(), 'M01.user_create') || $permissions->branchIds(auth()->user(), 'M01.user_view') !== []],
        ['organization.roles', 'الأدوار والصلاحيات', 'مراجعة صلاحيات الأدوار وإدارتها', 'shield-check', $permissions->allows(auth()->user(), 'M01.security_admin')],
    ] as [$route, $title, $description, $icon, $allowed]) {
        if ($allowed) {
            $destinations[] = compact('route', 'title', 'description', 'icon') + ['group' => 'إدارة'];
        }
    }
@endphp

@php
    $metricLabels = [
        'orders' => 'عدد الأوامر', 'draft' => 'مسودات', 'total' => 'الإجمالي',
        'active' => 'نشطة', 'submitted' => 'مقدمة', 'open' => 'مفتوحة',
        'revenue' => 'الإيراد ج.م', 'expenses' => 'المصروف ج.م', 'net' => 'صافي النتيجة ج.م',
        'customer_invoiced' => 'فواتير العملاء ج.م', 'supplier_invoiced' => 'فواتير الموردين ج.م',
        'bank' => 'البنك ج.م', 'cash' => 'النقدية ج.م',
    ];
@endphp

<section class="executive-dashboard" aria-label="لوحة المؤشرات التنفيذية" data-erp-archetype="dashboard">
    <x-page.heading title="لوحة المؤشرات" description="أهلًا، {{ auth()->user()->name }}. إليك نظرة على أعمالك.">
        <x-slot:actions>
            <time class="dashboard-today" datetime="{{ now('Africa/Cairo')->toDateString() }}"><flux:icon.calendar-days class="size-4" />{{ now('Africa/Cairo')->translatedFormat('l، j F Y') }}</time>
            <a class="dashboard-directory-link" href="#workspace-modules">وحدات العمل <flux:icon.arrow-down class="size-4" /></a>
        </x-slot:actions>
    </x-page.heading>

    <x-ui.panel class="dashboard-filters">
        <div class="dashboard-filter-intro"><flux:icon.adjustments-horizontal class="size-5" /><span>نطاق العرض</span></div>
        <div class="dashboard-filter-fields">
            <flux:select label="نطاق مؤشرات الفرع" wire:model.live="branch">
                <option value="">كل الفروع المسموحة لكل وحدة</option>
                @foreach($branchIds as $id)<option value="{{ $id }}">الفرع #{{ $id }}</option>@endforeach
            </flux:select>
            <flux:input type="date" label="المؤشرات من تاريخ" wire:model.live="from" />
            <flux:input type="date" label="المؤشرات حتى تاريخ" wire:model.live="to" />
        </div>
        @error('from')<p class="dashboard-filter-error" role="alert">{{ $message }}</p>@enderror
        @error('to')<p class="dashboard-filter-error" role="alert">{{ $message }}</p>@enderror
    </x-ui.panel>

    <div class="dashboard-results" wire:loading.class="opacity-60" wire:target="branch,from,to">
        <p class="dashboard-loading" wire:loading wire:target="branch,from,to" role="status">جارٍ تحديث المؤشرات…</p>
        <x-ui.panel class="dashboard-finance-overview">
            <div class="dashboard-section-heading">
                <div><h2>الملخص المالي</h2><p>ثلاث قراءات مستقلة لأعمالك، بالجنيه المصري</p></div>
                <span class="dashboard-period"><bdi>{{ $analytics['from'] }}</bdi><span aria-label="إلى">←</span><bdi>{{ $analytics['to'] }}</bdi></span>
            </div>
            <div class="dashboard-finance-grid">
            @foreach(['accounting' => ['الأداء المالي · الأستاذ', 'chart-bar'], 'receivables' => ['الفواتير المرحلة', 'document-currency-dollar'], 'treasury' => ['البنك والنقدية', 'banknotes']] as $key => [$title, $icon])
                @php($section = $analytics['sections'][$key])
                <section class="dashboard-finance-panel" wire:key="executive-{{ $key }}" aria-labelledby="finance-{{ $key }}">
                    <div class="dashboard-panel-heading">
                        <span class="dashboard-section-icon"><flux:icon :name="$icon" class="size-5" /></span>
                        <h3 id="finance-{{ $key }}">{{ $title }}</h3>
                        @if($section['status'] === 'available' && $section['route'])
                            <a href="{{ route($section['route']) }}" wire:navigate class="dashboard-open" aria-label="فتح {{ $title }}"><flux:icon.arrow-up-left class="size-4" /></a>
                        @endif
                    </div>
                    @if($section['status'] === 'available')
                        <dl @class(['dashboard-finance-metrics', 'dashboard-ledger-metrics' => $key === 'accounting'])>
                            @foreach($section['metrics'] as $metric => $value)
                                <div @class(['dashboard-net' => $metric === 'net'])>
                                    <dt>{{ $metricLabels[$metric] }}</dt>
                                    <dd><bdi>{{ $value }}</bdi></dd>
                                </div>
                            @endforeach
                        </dl>
                        <p class="dashboard-basis">{{ $section['basis'] }}</p>
                    @else
                        <div class="dashboard-unavailable"><flux:icon.lock-closed class="size-5" /><p>{{ $section['reason'] }}</p></div>
                    @endif
                </section>
            @endforeach
            </div>
        </x-ui.panel>

        <div class="dashboard-work-grid">
            <x-ui.panel class="dashboard-operations">
                <div class="dashboard-section-heading">
                    <div><h2>متابعة التشغيل</h2><p>حجم الأعمال وحالتها في الفترة المحددة</p></div>
                    <flux:icon.squares-2x2 class="size-5" />
                </div>
                <div class="dashboard-operation-list">
                    @foreach(['sales' => ['أوامر البيع', 'clipboard-document-list'], 'projects' => ['المشروعات', 'folder'], 'tenders' => ['المناقصات', 'document-check'], 'maintenance' => ['الصيانة الميدانية', 'wrench-screwdriver'], 'workshops' => ['الورش', 'cog-6-tooth']] as $key => [$title, $icon])
                        @php($section = $analytics['sections'][$key])
                        <section class="dashboard-operation" wire:key="executive-{{ $key }}" aria-labelledby="operation-{{ $key }}">
                            <div class="dashboard-operation-heading">
                                <span class="dashboard-section-icon"><flux:icon :name="$icon" class="size-5" /></span>
                                <div>
                                    <h3 id="operation-{{ $key }}">{{ $title }}</h3>
                                    @if($section['status'] === 'available')
                                        <details class="dashboard-definition"><summary>طريقة الاحتساب</summary><p>{{ $section['basis'] }}</p></details>
                                    @endif
                                </div>
                            </div>
                            @if($section['status'] === 'available')
                                <dl class="dashboard-operation-metrics">
                                    @foreach($section['metrics'] as $metric => $value)
                                        <div><dt>{{ $metricLabels[$metric] }}</dt><dd><bdi>{{ $value }}</bdi></dd></div>
                                    @endforeach
                                </dl>
                                @if($section['route'])
                                    <a href="{{ route($section['route']) }}" wire:navigate class="dashboard-open" aria-label="فتح {{ $title }}"><flux:icon.chevron-left class="size-4" /></a>
                                @endif
                            @else
                                <p class="dashboard-operation-unavailable"><flux:icon.lock-closed class="size-4" />{{ $section['reason'] }}</p>
                            @endif
                        </section>
                    @endforeach
                </div>
                <p class="dashboard-scope-note"><flux:icon.information-circle class="size-4" /><span>كل مؤشر يعرض بيانات وحدته في نطاق صلاحياتك. تفاصيل الفترة والحالة موضحة في طريقة الاحتساب.</span></p>
            </x-ui.panel>

            <x-ui.panel id="workspace-modules" class="erp-workspace" x-data="{ group: 'الكل' }" aria-labelledby="workspace-modules-title">
                <div class="dashboard-section-heading"><div><h2 id="workspace-modules-title">وحدات العمل</h2><p>انتقل مباشرة إلى عملك</p></div><flux:icon.arrow-up-left class="size-5" /></div>
                <div class="workspace-filters" role="group" aria-label="تصفية وحدات العمل">
                    @foreach(['الكل', 'تجاري', 'تشغيلي', 'مالي', 'إدارة'] as $group)
                        <button type="button" @click="group = @js($group)" :aria-pressed="group === @js($group)" :class="{ 'is-current': group === @js($group) }">{{ $group }}</button>
                    @endforeach
                </div>
                <nav class="workspace-module-grid" aria-label="روابط وحدات العمل">
                    @forelse($destinations as $destination)
                        <a href="{{ route($destination['route']) }}" wire:navigate class="workspace-module" x-show="group === 'الكل' || group === @js($destination['group'])" title="{{ $destination['description'] }}">
                            <flux:icon :name="$destination['icon']" class="workspace-module-icon size-4" />
                            <h3>{{ $destination['title'] }}</h3>
                            <span class="sr-only">{{ $destination['description'] }}</span>
                        </a>
                    @empty
                        <x-ui.empty-state title="لا توجد وحدات متاحة لحسابك" description="راجع مسؤول النظام لتحديد صلاحياتك ونطاق عملك." icon="lock-closed" />
                    @endforelse
                </nav>
                <p x-cloak x-show="group !== 'الكل' && !@js(array_values(array_unique(array_column($destinations, 'group')))).includes(group)" class="workspace-group-empty" role="status">لا توجد وحدات متاحة لحسابك في هذا القسم.</p>
                <a href="{{ route('profile.edit') }}" wire:navigate class="workspace-account"><flux:icon.user-circle class="size-5" />إعدادات الحساب<flux:icon.chevron-left class="size-4" /></a>
            </x-ui.panel>
        </div>
    </div>
</section>
