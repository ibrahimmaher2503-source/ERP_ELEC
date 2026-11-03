@props(['title', 'description' => null, 'breadcrumbs' => null])

@php
    $breadcrumbs ??= match (true) {
        request()->routeIs('organization.roles.show') => ['التنظيم', 'الأدوار', 'تفاصيل الدور'],
        request()->routeIs('organization.roles') => ['التنظيم', 'الأدوار'],
        request()->routeIs('organization.permissions') => ['التنظيم', 'الصلاحيات'],
        request()->routeIs('organization.users.show') => ['التنظيم', 'المستخدمون', 'تفاصيل المستخدم'],
        request()->routeIs('organization.users') => ['التنظيم', 'المستخدمون'],
        request()->routeIs('organization.company') => ['التنظيم', 'الشركة'],
        request()->routeIs('organization.branches') => ['التنظيم', 'الفروع'],
        request()->routeIs('organization.branches.show') => ['التنظيم', 'الفروع', 'تفاصيل الفرع'],
        default => [$title],
    };
    if (($breadcrumbs[0] ?? null) === 'الرئيسية') {
        array_shift($breadcrumbs);
    }
    $breadcrumbRoutes = [
        'المشروعات' => 'projects.index',
        'المشتريات' => 'purchasing.dashboard',
        'لوحة المشتريات' => 'purchasing.dashboard',
        'عروض الموردين' => 'purchasing.offers.index',
        'مقارنة العروض' => 'purchasing.comparison',
        'أوامر الشراء' => 'purchasing.orders.index',
        'استلام المشتريات' => 'purchasing.receipts.index',
        'مرتجعات المشتريات' => 'purchasing.returns',
        'تقارير المشتريات' => 'purchasing.reports',
        'طلبات الاحتياج' => 'purchasing.requisitions.index',
        'طلبات عروض الموردين' => 'purchasing.rfqs.index',
        'المخزون' => 'inventory.dashboard',
        'المستودعات' => 'inventory.warehouses',
        'أرصدة المخزون' => 'inventory.balances',
        'المبيعات' => 'quotations.index',
        'عروض الأسعار' => 'quotations.index',
        'أوامر البيع' => 'sales-orders.index',
        'مرتجعات البيع' => 'sales-returns.index',
        'التنظيم' => 'dashboard',
        'العملاء' => 'customers.index',
        'الموردون' => 'suppliers.index',
        'الموردين' => 'suppliers.index',
        'المنتجات' => 'products.index',
        'الأصناف' => 'products.index',
        'الأصناف والمنتجات' => 'products.index',
        'الشركة' => 'organization.company',
        'الفروع' => 'organization.branches',
        'المستخدمون' => 'organization.users',
        'الأدوار' => 'organization.roles',
        'الأدوار والصلاحيات' => 'organization.roles',
        'الصلاحيات' => 'organization.permissions',
    ];
    $hasSecondaryActions = isset($secondaryActions) && trim((string) $secondaryActions) !== '';
@endphp

<header {{ $attributes->class(['erp-page-heading flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between']) }}>
    <div class="space-y-1">
        @if (count($breadcrumbs))
            <nav aria-label="مسار التنقل" dir="rtl">
                <ol class="flex flex-wrap items-center gap-2 text-xs text-[#253247]/65">
                    <li><a href="{{ route('dashboard') }}" wire:navigate class="rounded hover:text-[#202B3D] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#202B3D]">الرئيسية</a></li>
                    @foreach ($breadcrumbs as $breadcrumb)
                        <li aria-hidden="true"><flux:icon.chevron-left class="size-3" /></li>
                        <li>
                            @if($loop->last)
                                <span aria-current="page">{{ $breadcrumb }}</span>
                            @elseif(isset($breadcrumbRoutes[$breadcrumb]))
                                <a href="{{ route($breadcrumbRoutes[$breadcrumb]) }}" wire:navigate>{{ $breadcrumb }}</a>
                            @else
                                <span>{{ $breadcrumb }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif
        <flux:heading size="xl" level="1" class="!text-3xl !font-bold text-[#202B3D]">{{ $title }}</flux:heading>
        @if ($description)
            <flux:text>{{ $description }}</flux:text>
        @endif
    </div>
    @if(isset($actions) || $hasSecondaryActions)
        <div class="erp-page-actions flex flex-wrap items-center gap-2">
            @isset($actions){{ $actions }}@endisset
            @if($hasSecondaryActions)
                <div class="erp-secondary-actions" x-data="{ expanded: false }" @click.outside="expanded = false" @keydown.escape.stop="expanded = false; $refs.toggle?.focus()">
                    <button type="button" x-ref="toggle" class="erp-secondary-toggle" @click="expanded = !expanded" :aria-expanded="expanded.toString()" aria-controls="{{ $attributes->get('id', 'page-actions') }}-secondary">
                        <flux:icon.ellipsis-horizontal class="size-5" aria-hidden="true" /><span>المزيد</span>
                    </button>
                    <div id="{{ $attributes->get('id', 'page-actions') }}-secondary" class="erp-secondary-menu" data-open="true" :data-open="expanded.toString()">{{ $secondaryActions }}</div>
                </div>
            @endif
        </div>
    @endif
</header>
