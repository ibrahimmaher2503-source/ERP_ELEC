<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        @include('partials.head', ['erpLight' => true])
    </head>
    <body class="erp-shell min-h-screen bg-slate-50 text-[#253247]">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-white focus:p-3">{{ __('Skip to content') }}</a>

        <flux:sidebar sticky collapsible="mobile" class="erp-sidebar border-s"
            x-data="{
                query: '', links: [],
                get matches() {
                    const query = this.query.trim().toLocaleLowerCase('ar');
                    return this.links.filter(link => (link.label + ' ' + link.section).toLocaleLowerCase('ar').includes(query));
                }
            }"
            x-init="links = Array.from(new Map(Array.from($refs.navigation.querySelectorAll('a[data-flux-sidebar-item][href]')).map(link => [link.getAttribute('href'), {
                href: link.getAttribute('href'),
                label: link.textContent.trim(),
                section: link.closest('.erp-nav-section')?.querySelector('h2')?.textContent.trim() ?? ''
            }])).values())">
            <flux:sidebar.header class="erp-sidebar-header border-b pb-4">
                <a href="{{ route('dashboard') }}" wire:navigate class="erp-sidebar-brand flex min-w-0 items-center gap-3 rounded-lg px-2 py-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#93C5FD]">
                    <svg aria-hidden="true" viewBox="0 0 44 48" class="erp-sidebar-logo size-10 shrink-0" fill="none">
                        <path d="M22 2 3 13v22l19 11 19-11V13L22 2Z" fill="currentColor" />
                        <path d="m24 8-13 19h10l-2 13 14-21H22l2-11Z" fill="white" />
                    </svg>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold">Synthetic Electrical</span>
                        <span class="erp-sidebar-brand-subtitle block text-xs">إدارة الأعمال الكهربائية</span>
                    </span>
                </a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <div class="erp-nav-search">
                <label for="erp-navigation-search" class="sr-only">البحث في الصفحات المتاحة</label>
                <flux:icon.magnifying-glass aria-hidden="true" class="size-4" />
                <input id="erp-navigation-search" type="search" x-model="query" @keydown.escape="query = ''" placeholder="ابحث عن صفحة…" autocomplete="off" aria-controls="erp-navigation-results" />
            </div>
            <nav id="erp-navigation-results" x-cloak x-show="query.trim().length" aria-label="نتائج البحث في الصفحات" class="erp-nav-results">
                <p role="status" class="px-3 py-2 text-xs text-slate-500" x-text="matches.length ? 'الصفحات المطابقة: ' + matches.length : 'لا توجد صفحات مطابقة'"></p>
                <template x-for="link in matches" :key="link.href">
                    <a :href="link.href" class="erp-nav-result">
                        <span class="block" x-text="link.label"></span>
                        <small class="block text-slate-500" x-show="link.section" x-text="link.section"></small>
                    </a>
                </template>
            </nav>
            <flux:sidebar.nav class="erp-nav" aria-label="وحدات النظام المتاحة" x-ref="navigation" x-show="!query.trim().length">
                <flux:sidebar.item class="erp-nav-home" icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    الرئيسية
                </flux:sidebar.item>

                <div class="erp-nav-section grid gap-1">
                    <h2 class="px-3 py-1 text-xs font-semibold text-slate-500">العمليات التجارية</h2>
                    @if (($quotationNavigation ?? false) || ($salesOrderNavigation ?? false) || ($organizationNavigation['security'] ?? false))
                        <flux:sidebar.group heading="المبيعات" icon="document-text" expandable :expanded="request()->routeIs('quotations.*','sales-orders.*','sales-returns.*')">
                            @if($quotationNavigation ?? false)<flux:sidebar.item icon="document-text" :href="route('quotations.index')" :current="request()->routeIs('quotations.*') && !request()->routeIs('quotations.policy')" wire:navigate>عروض الأسعار</flux:sidebar.item>@elseif($organizationNavigation['security'] ?? false)<flux:sidebar.item icon="shield-check" :href="route('organization.roles')" wire:navigate>تهيئة صلاحيات المبيعات</flux:sidebar.item>@endif
                            @if($salesOrderNavigation ?? false)<flux:sidebar.item icon="clipboard-document-list" :href="route('sales-orders.index')" :current="request()->routeIs('sales-orders.*') && !request()->routeIs('sales-orders.policy')" wire:navigate>أوامر البيع</flux:sidebar.item>@endif
                            @if (! ($salesOrderNavigation ?? false) && ($organizationNavigation['security'] ?? false))
                                <p class="px-3 py-1 text-xs text-slate-500">أوامر البيع تحتاج صلاحية عرض ونطاق فرع</p>
                                @if ($organizationNavigation['users'] ?? false)
                                    <flux:sidebar.item icon="users" :href="route('organization.users')" wire:navigate>إعداد نطاق الفروع</flux:sidebar.item>
                                @endif
                            @endif
                            @if($organizationNavigation['security'] ?? false)<flux:sidebar.item icon="shield-check" :href="route('sales-orders.policy')" :current="request()->routeIs('sales-orders.policy')" wire:navigate>إعدادات اعتماد أوامر البيع</flux:sidebar.item>@endif
                            @if(app(\App\Modules\Sales\Application\QuotationAccess::class)->allows(auth()->id(),'policy_manage'))<flux:sidebar.item :href="route('quotations.policy')" :current="request()->routeIs('quotations.policy')" wire:navigate>سياسات عروض الأسعار</flux:sidebar.item>@endif
                        </flux:sidebar.group>
                    @endif
                    @php($purchasingNavigation = app(\App\Modules\Purchasing\Application\PurchasingAccess::class)->navigation(auth()->id()))
                    @if($purchasingNavigation)
                    <flux:sidebar.group heading="المشتريات" icon="shopping-cart" expandable :expanded="request()->routeIs('purchasing.*')">
                        @foreach($purchasingNavigation as $destination=>[$label,$permission])
                        <flux:sidebar.item :href="route('purchasing.'.$destination)" :current="request()->routeIs('purchasing.'.$destination) || (str_ends_with($destination,'.index') && request()->routeIs('purchasing.'.str_replace('.index','.*',$destination)))" wire:navigate>{{ $label }}</flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                    @endif
                    @if(config('release.tenders_enabled') && app(\App\Modules\Projects\Application\TenderAccess::class)->branches(auth()->id()))
                    <flux:sidebar.group heading="المناقصات" icon="document-text" expandable :expanded="request()->routeIs('tenders.*')">
                        <flux:sidebar.item :href="route('tenders.dashboard')" :current="request()->routeIs('tenders.dashboard')" wire:navigate>متابعة المناقصات</flux:sidebar.item>
                        <flux:sidebar.item :href="route('tenders.index')" :current="request()->routeIs('tenders.index','tenders.show','tenders.create')" wire:navigate>سجل العطاءات</flux:sidebar.item>
                        @if(app(\App\Modules\Projects\Application\TenderAccess::class)->branches(auth()->id(),'report'))<flux:sidebar.item :href="route('tenders.reports')" :current="request()->routeIs('tenders.reports')" wire:navigate>تقارير المناقصات</flux:sidebar.item>@endif
                    </flux:sidebar.group>
                    @endif
                    @if (($customerNavigation ?? false) || ($supplierNavigation ?? false))
                        <flux:sidebar.group class="erp-nav-parties" heading="العملاء والموردون" icon="users" expandable :expanded="request()->routeIs('customers.*','suppliers.*')">
                            @if ($customerNavigation ?? false)<flux:sidebar.item icon="user-group" :href="route('customers.index')" :current="request()->routeIs('customers.*')" wire:navigate>العملاء</flux:sidebar.item>@endif
                            @if ($supplierNavigation ?? false)
                                <flux:sidebar.item icon="truck" :href="route('suppliers.index')" :current="request()->routeIs('suppliers.index','suppliers.show','suppliers.edit','suppliers.import')" wire:navigate>قائمة الموردين</flux:sidebar.item>
                                @if (app(\App\Modules\Parties\Application\SupplierAccess::class)->allows(auth()->id(),'M03.supplier_create'))<flux:sidebar.item icon="plus" :href="route('suppliers.create')" :current="request()->routeIs('suppliers.create')" wire:navigate>إضافة مورد</flux:sidebar.item>@endif
                                @if (app(\App\Modules\Parties\Application\SupplierAccess::class)->allows(auth()->id(),'M03.supplier_category_manage'))<flux:sidebar.item icon="squares-2x2" :href="route('suppliers.categories')" :current="request()->routeIs('suppliers.categories')" wire:navigate>تصنيفات الموردين</flux:sidebar.item>@endif
                            @endif
                        </flux:sidebar.group>
                    @endif
                </div>

                <div class="erp-nav-section grid gap-1">
                    <h2 class="px-3 py-1 text-xs font-semibold text-slate-500">المخزون والأصناف</h2>
                    @if($inventoryNavigation ?? [])
                    <flux:sidebar.group heading="المخزون" icon="archive-box" expandable :expanded="request()->routeIs('inventory.*')">
                        @foreach(['dashboard'=>'لوحة المخزون','warehouses'=>'المستودعات','balances'=>'الأرصدة','movements'=>'الحركات','reservations'=>'الحجوزات','transfers'=>'التحويلات','counts'=>'الجرد','adjustments'=>'التسويات','receipts'=>'الأرصدة الافتتاحية','issues'=>'الصرف'] as $destination=>$label)
                        @if($inventoryNavigation[$destination] ?? false)<flux:sidebar.item :href="route('inventory.'.$destination)" :current="request()->routeIs('inventory.'.$destination)" wire:navigate>{{ $label }}</flux:sidebar.item>@endif
                        @endforeach
                    </flux:sidebar.group>
                    @endif
                    @if (array_filter($productNavigation ?? []))
                        <flux:sidebar.group class="erp-nav-products" heading="المنتجات" icon="cube" expandable :expanded="request()->routeIs('products.*')">
                    @foreach (['index'=>'الأصناف','categories'=>'التصنيفات','brands'=>'العلامات التجارية','units'=>'وحدات القياس'] as $destination=>$label)
                                @if ($productNavigation[$destination] ?? false)
                                    <flux:sidebar.item :icon="['index' => 'cube', 'categories' => 'rectangle-stack', 'brands' => 'tag', 'units' => 'scale'][$destination]" :href="route('products.'.$destination)" :current="request()->routeIs('products.'.$destination) || ($destination === 'index' && request()->routeIs('products.show','products.edit','products.create','products.import'))" wire:navigate>{{ $label }}</flux:sidebar.item>
                                @endif
                            @endforeach
                        </flux:sidebar.group>
                    @endif
                </div>

                <div class="erp-nav-section grid gap-1">
                    <h2 class="px-3 py-1 text-xs font-semibold text-slate-500">المشروعات والخدمات</h2>
                    @if(config('release.projects_enabled') && (app(\App\Modules\Projects\Application\ProjectAccess::class)->query(auth()->id())->exists() || app(\App\Modules\Projects\Application\ProjectAccess::class)->branches(auth()->id())))
                    <flux:sidebar.group heading="المشروعات" icon="folder" expandable :expanded="request()->routeIs('projects.*')">
                        <flux:sidebar.item icon="folder" :href="route('projects.index')" :current="request()->routeIs('projects.*') && !request()->routeIs('projects.reports','projects.export')" wire:navigate>المشروعات</flux:sidebar.item>
                        @if(app(\App\Modules\Projects\Application\ProjectAccess::class)->branches(auth()->id(),'report_view'))<flux:sidebar.item icon="document-chart-bar" :href="route('projects.reports')" :current="request()->routeIs('projects.reports')" wire:navigate>تقارير المشروعات</flux:sidebar.item>@endif
                    </flux:sidebar.group>
                    @endif
                    @if(app(\App\Modules\ServiceContracts\Application\ServiceContractAccess::class)->branches(auth()->id()))<flux:sidebar.item icon="document-check" :href="route('service-contracts.index')" :current="request()->routeIs('service-contracts.*')" wire:navigate>عقود الخدمة</flux:sidebar.item>@endif
                    @if(app(\App\Modules\FieldMaintenance\Application\MaintenanceAccess::class)->branches(auth()->id()))
                    <flux:sidebar.group heading="الصيانة الميدانية" icon="wrench-screwdriver" expandable :expanded="request()->routeIs('maintenance.*')">
                        <flux:sidebar.item :href="route('maintenance.dashboard')" :current="request()->routeIs('maintenance.dashboard')" wire:navigate>لوحة الصيانة</flux:sidebar.item>
                        <flux:sidebar.item :href="route('maintenance.requests')" :current="request()->routeIs('maintenance.requests*')" wire:navigate>طلبات الصيانة</flux:sidebar.item>
                        <flux:sidebar.item :href="route('maintenance.work-orders')" :current="request()->routeIs('maintenance.work-orders*')" wire:navigate>أوامر العمل</flux:sidebar.item>
                        <flux:sidebar.item :href="route('maintenance.schedule')" :current="request()->routeIs('maintenance.schedule')" wire:navigate>جدول الزيارات</flux:sidebar.item>
                    </flux:sidebar.group>
                    @endif
                    @if(app(\App\Modules\WorkshopRepairs\Application\WorkshopAccess::class)->branches(auth()->id()))
                    <flux:sidebar.group heading="الورش والإصلاح" icon="cog-6-tooth" expandable :expanded="request()->routeIs('workshops.*')">
                    @foreach(['dashboard'=>'لوحة الورش','intakes'=>'استلام المعدات','jobs'=>'أوامر الإصلاح','reports'=>'تقارير الورش','settings'=>'إعداد الورش'] as $destination=>$label)
                    @if($destination!=='reports'||app(\App\Modules\WorkshopRepairs\Application\WorkshopAccess::class)->branches(auth()->id(),'reports_view'))<flux:sidebar.item :href="route('workshops.'.$destination)" :current="request()->routeIs('workshops.'.$destination, 'workshops.'.$destination.'.*')" wire:navigate>{{ $label }}</flux:sidebar.item>@endif
                    @endforeach
                    </flux:sidebar.group>
                    @endif
                </div>

                <div class="erp-nav-section grid gap-1">
                    <h2 class="px-3 py-1 text-xs font-semibold text-slate-500">المالية</h2>
                    @php($accountingNavigation = app(\App\Modules\Accounting\Application\AccountingAccess::class)->navigation(auth()->id()))
                    @if($accountingNavigation)
                    <flux:sidebar.group heading="المحاسبة والمالية" icon="calculator" expandable :expanded="request()->routeIs('accounting.*')">
                    @foreach($accountingNavigation as $destination=>$label)<flux:sidebar.item :href="route('accounting.'.$destination)" :current="request()->routeIs('accounting.'.$destination)" wire:navigate>{{ $label }}</flux:sidebar.item>@endforeach
                    </flux:sidebar.group>
                    @endif
                    @php($financialNavigation = app(\App\Modules\ReceivablesPayables\Application\ReceivablesPayablesAccess::class)->navigation(auth()->id()))
                    @if($financialNavigation)
                    <flux:sidebar.group heading="الذمم المدينة والدائنة" icon="banknotes" expandable :expanded="request()->routeIs('financial.*')">
                    @foreach($financialNavigation as $destination=>$item)<flux:sidebar.item :href="route('financial.'.$destination)" :current="request()->routeIs('financial.'.$destination)" wire:navigate>{{ $item[0] }}</flux:sidebar.item>@endforeach
                    </flux:sidebar.group>
                    @endif
                    @php($treasuryNavigation = app(\App\Modules\Treasury\Application\TreasuryAccess::class)->navigation(auth()->id()))
                    @if($treasuryNavigation)
                    <flux:sidebar.group heading="الخزينة" icon="banknotes" expandable :expanded="request()->routeIs('treasury.*')">
                    @foreach($treasuryNavigation as $destination=>$item)<flux:sidebar.item :href="route('treasury.'.$destination)" :current="request()->routeIs('treasury.'.$destination)" wire:navigate>{{ $item[0] }}</flux:sidebar.item>@endforeach
                    </flux:sidebar.group>
                    @endif
                </div>

                <div class="erp-nav-section grid gap-1">
                    <h2 class="px-3 py-1 text-xs font-semibold text-slate-500">الموظفون والإدارة</h2>
                    @if($organizationNavigation['employees'] ?? false)<flux:sidebar.item icon="users" :href="route('organization.employees')" :current="request()->routeIs('organization.employees')" wire:navigate>الموظفون</flux:sidebar.item>@endif
                    @if(app(\App\Modules\Attendance\Application\AttendanceAccess::class)->branches(auth()->id()))<flux:sidebar.item icon="clock" :href="route('attendance.index')" :current="request()->routeIs('attendance.*')" wire:navigate>الحضور والانصراف</flux:sidebar.item>@endif
                    @if(array_filter(array_intersect_key($organizationNavigation ?? [], array_flip(['company', 'branches', 'users', 'security']))))
                    <flux:sidebar.group class="erp-nav-organization" heading="التنظيم" icon="building-office-2" expandable :expanded="request()->routeIs('organization.*') && !request()->routeIs('organization.employees')">
                        @if (($organizationNavigation['company'] ?? false) && \Illuminate\Support\Facades\Route::has('organization.company'))
                            <flux:sidebar.item icon="building-office" :href="route('organization.company')" :current="request()->routeIs('organization.company')" wire:navigate>الشركة</flux:sidebar.item>
                        @endif
                        @if (($organizationNavigation['branches'] ?? false) && \Illuminate\Support\Facades\Route::has('organization.branches'))
                            <flux:sidebar.item icon="building-office-2" :href="route('organization.branches')" :current="request()->routeIs('organization.branches*')" wire:navigate>الفروع</flux:sidebar.item>
                        @endif
                        @if (($organizationNavigation['users'] ?? false) && \Illuminate\Support\Facades\Route::has('organization.users'))
                            <flux:sidebar.item icon="users" :href="route('organization.users')" :current="request()->routeIs('organization.users*')" wire:navigate>المستخدمون</flux:sidebar.item>
                        @endif
                        @if (($organizationNavigation['security'] ?? false) && \Illuminate\Support\Facades\Route::has('organization.roles'))
                            <flux:sidebar.item icon="shield-check" :href="route('organization.roles')" :current="request()->routeIs('organization.roles*', 'organization.permissions*')" wire:navigate>الأدوار والصلاحيات</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                    @endif
                </div>
            </flux:sidebar.nav>

            <flux:spacer />
            <x-desktop-user-menu class="erp-sidebar-account hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <flux:header class="erp-topbar sticky top-0 flex min-h-16 items-center gap-3 border-b border-[#E5E8EE] bg-white px-4 sm:px-6 lg:px-8">
                <flux:sidebar.toggle class="lg:hidden" icon="bars-2" aria-label="فتح القائمة" />

                <div class="hidden items-center gap-2 text-sm text-[#253247]/70 sm:flex" aria-label="تاريخ اليوم">
                    <flux:icon.calendar-days class="size-4" />
                    <time datetime="{{ now('Africa/Cairo')->toDateString() }}">{{ now('Africa/Cairo')->format('Y-m-d') }}</time>
                </div>

                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-[#202B3D]">{{ ($organizationCompany ?? null)?->name ?? config('app.name', 'ERP Electrical') }}</div>
                        @if (($organizationCompany ?? null)?->code)
                            <div class="text-xs text-[#253247]/70">{{ $organizationCompany->code }}</div>
                        @endif
                    </div>
                </div>

                <flux:dropdown position="bottom" align="end" class="lg:hidden">
                    <flux:profile :avatar="\App\Support\DefaultAvatar::url(auth()->id(), auth()->user()->gender)" aria-label="قائمة الحساب" icon-trailing="chevron-down" />
                    <flux:menu>
                        <flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">{{ __('Log out') }}</flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
        </flux:header>

        <flux:main id="main-content" class="erp-main min-h-[calc(100vh-4rem)] p-4 sm:p-6 lg:p-8">
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
