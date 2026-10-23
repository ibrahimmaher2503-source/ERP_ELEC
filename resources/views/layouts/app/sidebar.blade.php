<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        @include('partials.head', ['erpLight' => true])
    </head>
    <body class="erp-shell min-h-screen bg-slate-50 text-[#253247]">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-white focus:p-3">{{ __('Skip to content') }}</a>

        <flux:sidebar sticky collapsible="mobile" class="erp-sidebar border-s border-[#E5E8EE] bg-white">
            <flux:sidebar.header class="border-b border-[#E5E8EE] pb-4">
                <a href="{{ route('dashboard') }}" wire:navigate class="flex min-w-0 items-center gap-3 rounded-lg px-2 py-1 text-[#202B3D] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#202B3D]">
                    <svg aria-hidden="true" viewBox="0 0 44 48" class="size-10 shrink-0 text-[#202B3D]" fill="none">
                        <path d="M22 2 3 13v22l19 11 19-11V13L22 2Z" fill="currentColor" />
                        <path d="m24 8-13 19h10l-2 13 14-21H22l2-11Z" fill="white" />
                    </svg>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-bold tracking-wide">ERP Electrical</span>
                        <span class="block text-xs text-[#253247]/70">سياق العمل</span>
                    </span>
                </a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav class="erp-nav">
                <flux:sidebar.item class="erp-nav-home" icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    الرئيسية
                </flux:sidebar.item>

                <flux:sidebar.group heading="الوحدات" class="grid">
                    <flux:sidebar.group class="erp-nav-organization" heading="التنظيم" icon="building-office-2" expandable :expanded="request()->routeIs('organization.*')">
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
                    @if (array_filter($productNavigation ?? []))
                        <flux:sidebar.group class="erp-nav-products" heading="المنتجات" icon="cube" expandable :expanded="request()->routeIs('products.*')">
                    @foreach (['index'=>'الأصناف','categories'=>'التصنيفات','brands'=>'العلامات التجارية','units'=>'وحدات القياس'] as $destination=>$label)
                                @if ($productNavigation[$destination] ?? false)
                                    <flux:sidebar.item :icon="['index' => 'cube', 'categories' => 'rectangle-stack', 'brands' => 'tag', 'units' => 'scale'][$destination]" :href="route('products.'.$destination)" :current="request()->routeIs('products.'.$destination) || ($destination === 'index' && request()->routeIs('products.show','products.edit','products.create','products.import'))" wire:navigate>{{ $label }}</flux:sidebar.item>
                                @endif
                            @endforeach
                        </flux:sidebar.group>
                    @endif
                    @if (($quotationNavigation ?? false) || ($salesOrderNavigation ?? false) || ($salesReturnNavigation ?? false) || ($organizationNavigation['security'] ?? false))
                        <flux:sidebar.group heading="المبيعات" icon="document-text" expandable :expanded="request()->routeIs('dashboard','quotations.*','sales-orders.*','sales-returns.*')">
                            @if($salesOrderNavigation ?? false)<flux:sidebar.item icon="clipboard-document-list" :href="route('sales-orders.index')" :current="request()->routeIs('sales-orders.*')" wire:navigate>أوامر البيع</flux:sidebar.item>@endif
                            @if (! ($salesOrderNavigation ?? false) && ($organizationNavigation['security'] ?? false))
                                <flux:sidebar.item icon="clipboard-document-list" disabled aria-disabled="true" title="راجع صلاحية العرض ونطاق الفرع في تفاصيل المستخدم">أوامر البيع</flux:sidebar.item>
                                <p class="px-3 py-1 text-xs text-slate-500">أوامر البيع تحتاج صلاحية عرض ونطاق فرع</p>
                                @if ($organizationNavigation['users'] ?? false)
                                    <flux:sidebar.item icon="users" :href="route('organization.users')" wire:navigate>إعداد نطاق الفروع</flux:sidebar.item>
                                @endif
                            @endif
                            @if($salesReturnNavigation ?? false)<flux:sidebar.item icon="arrow-uturn-right" :href="route('sales-returns.index')" :current="request()->routeIs('sales-returns.*')" wire:navigate>مرتجعات البيع</flux:sidebar.item>@endif
                            @if($organizationNavigation['security'] ?? false)<flux:sidebar.item icon="shield-check" :href="route('sales-orders.policy')" :current="request()->routeIs('sales-orders.policy')" wire:navigate>إعدادات اعتماد أوامر البيع</flux:sidebar.item>@endif
                            @if($quotationNavigation ?? false)<flux:sidebar.item icon="document-text" :href="route('quotations.index')" :current="request()->routeIs('quotations.*')" wire:navigate>عروض الأسعار</flux:sidebar.item>@elseif($organizationNavigation['security'] ?? false)<flux:sidebar.item icon="shield-check" :href="route('organization.roles')" wire:navigate>تهيئة صلاحيات المبيعات</flux:sidebar.item>@endif
                            @if(app(\App\Modules\Sales\Application\QuotationAccess::class)->allows(auth()->id(),'policy_manage'))<flux:sidebar.item :href="route('quotations.policy')" :current="request()->routeIs('quotations.policy')" wire:navigate>سياسات عروض الأسعار</flux:sidebar.item>@endif
                        </flux:sidebar.group>
                    @endif
                    @if($inventoryNavigation ?? [])
                    <flux:sidebar.group heading="المخزون" icon="archive-box" expandable :expanded="request()->routeIs('inventory.*')">
                        @foreach(['dashboard'=>'لوحة المخزون','warehouses'=>'المستودعات','balances'=>'الأرصدة','movements'=>'الحركات','reservations'=>'الحجوزات','transfers'=>'التحويلات','counts'=>'الجرد','adjustments'=>'التسويات','receipts'=>'الأرصدة الافتتاحية','issues'=>'الصرف'] as $destination=>$label)
                        @if($inventoryNavigation[$destination] ?? false)<flux:sidebar.item :href="route('inventory.'.$destination)" :current="request()->routeIs('inventory.'.$destination)" wire:navigate>{{ $label }}</flux:sidebar.item>@endif
                        @endforeach
                    </flux:sidebar.group>
                    @endif
                    @if(app(\App\Modules\Projects\Application\ProjectAccess::class)->query(auth()->id())->exists() || app(\App\Modules\Projects\Application\ProjectAccess::class)->branches(auth()->id()))
                    <flux:sidebar.group heading="المشروعات" icon="folder" expandable :expanded="request()->routeIs('projects.*')">
                        <flux:sidebar.item icon="chart-bar" :href="route('projects.dashboard')" :current="request()->routeIs('projects.dashboard')" wire:navigate>لوحة المشروعات</flux:sidebar.item>
                        <flux:sidebar.item icon="folder" :href="route('projects.index')" :current="request()->routeIs('projects.*')" wire:navigate>قائمة المشروعات</flux:sidebar.item>
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
                    @php($accountingNavigation = app(\App\Modules\Accounting\Application\AccountingAccess::class)->navigation(auth()->id()))
                    @if($accountingNavigation)
                    <flux:sidebar.group heading="المحاسبة والمالية" icon="calculator" expandable :expanded="request()->routeIs('accounting.*')">
                    @foreach($accountingNavigation as $destination=>$label)<flux:sidebar.item :href="route('accounting.'.$destination)" :current="request()->routeIs('accounting.'.$destination)" wire:navigate>{{ $label }}</flux:sidebar.item>@endforeach
                    </flux:sidebar.group>
                    @endif
                    @foreach ([


                        ['DeliveryReturns', 'التسليم والمرتجعات', 'truck'],
                        ['FieldMaintenance', 'الصيانة الميدانية', 'wrench-screwdriver'],
                        ['WorkshopRepairs', 'إصلاحات الورشة', 'wrench'],
                        ['ServiceContracts', 'عقود الخدمة', 'document-text'],

                        ['ReceivablesPayables', 'الذمم المدينة والدائنة', 'scale'],
                        ['Treasury', 'الخزينة', 'building-library'],
                        ['Payroll', 'الموظفون والرواتب', 'user-group'],
                        ['Attendance', 'الحضور', 'clock'],
                        ['Reporting', 'التقارير', 'chart-pie'],
                        ['System', 'النظام', 'cog-6-tooth'],
                    ] as [$module, $label, $icon])
                        <flux:sidebar.item :icon="$icon" disabled aria-disabled="true" title="{{ $label }} — مخطط له">{{ $label }}</flux:sidebar.item>
                    @endforeach
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />
            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <flux:header class="erp-topbar sticky top-0 flex min-h-16 items-center gap-3 border-b border-[#E5E8EE] bg-white px-4 sm:px-6 lg:px-8">
                <flux:sidebar.toggle class="lg:hidden" icon="bars-2" aria-label="فتح القائمة" />

                <label class="erp-search hidden sm:block">
                    <span class="sr-only">البحث في النظام، مخطط له</span>
                    <input type="search" disabled placeholder="البحث في النظام (مخطط له)" aria-disabled="true">
                    <flux:icon.magnifying-glass aria-hidden="true" />
                </label>
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

                <button type="button" disabled aria-label="الواجهة الإنجليزية مخطط لها" title="الواجهة الإنجليزية مخطط لها" class="hidden text-xs font-semibold text-slate-400 lg:block">English</button>

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
