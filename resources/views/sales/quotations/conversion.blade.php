<div data-erp-archetype="form" class="quotation-page space-y-4">
    @include('sales.quotations.partials.detail-header', ['heading' => 'تحويل عرض السعر إلى أمر بيع'])
    @include('sales.quotations.partials.identity')
    @include('sales.orders.partials.feedback')
    <x-ui.panel>
        @if($conversion)
            <h2 class="mb-3 font-bold">تم تحويل كامل الكميات</h2>
            <flux:button :href="route('sales-orders.show', $conversion->order_id)" wire:navigate>أمر البيع #{{ $conversion->order_id }}</flux:button>
        @elseif($acceptanceRecord && $can('convert'))
            <form wire:submit="convert" class="space-y-4">
                <h2 class="font-bold">إنشاء أمر بيع بكامل كميات الإصدار المقبول</h2>
                <p class="text-sm">تنتقل الأسعار والشروط والضريبة المحفوظة من الإصدار المقبول. يتطلب الحجز والتسليم اعتماد أمر البيع لاحقًا.</p>
                <flux:select wire:model="conversionBranch" label="فرع أمر البيع">
                    <option value="">اختر الفرع المصرح به</option>
                    @foreach($conversionBranches as $branch)<option value="{{ $branch['id'] }}">{{ $branch['name'] }}</option>@endforeach
                </flux:select>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">تحويل كامل الكميات مرة واحدة</flux:button>
            </form>
        @else
            <x-ui.empty-state title="التحويل غير متاح" description="يلزم إصدار رسمي وقبول مكتوب وصلاحية تحويل وإنشاء أمر بيع في الفرع." icon="document-text" />
        @endif
    </x-ui.panel>
</div>
