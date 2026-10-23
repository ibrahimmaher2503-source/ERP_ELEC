<div class="space-y-4">
    <x-page.heading title="مرتجعات البيع" description="مرتجعات مستقلة مرتبطة بأوامر البيع والتسليمات المؤكدة" :breadcrumbs="['المبيعات', 'مرتجعات البيع']">
        <x-slot:actions>@if(app(\App\Modules\Sales\Application\SalesOrderAccess::class)->allows(auth()->id()))<flux:button :href="route('sales-orders.index')" wire:navigate icon="arrow-right">أوامر البيع</flux:button>@endif</x-slot:actions>
    </x-page.heading>
    <x-erp.section-card title="أهلية المرتجع" icon="arrow-uturn-right">
        <x-ui.empty-state title="لا توجد تسليمات مؤكدة مؤهلة للمرتجع" description="إنشاء المرتجع يحتاج إذن تسليم مؤكدًا من الوحدة المالكة وكميات متاحة للإرجاع. استلام وفحص وتصرف المخزون والتسوية المالية تظل لدى أصحابها؛ لم تسجل أي حركة أو زيادة مخزون." />
    </x-erp.section-card>
</div>
