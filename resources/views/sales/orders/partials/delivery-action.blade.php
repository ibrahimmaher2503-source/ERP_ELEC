<div class="space-y-4">
    @if($record->status === 'approved')
        <x-ui.panel>
            <h2 class="mb-3 font-bold">التخصيص والتسليم بالوحدة الأساسية</h2>
            <p class="mb-4 text-sm">الطلب المؤجل يحتاج مخزونًا متاحًا قبل تخصيصه. إنشاء طلب التسليم لا يصرف المخزون؛ التأكيد يرحله مع الصرف من الأقدم أولًا وقيد التكلفة.</p>
            <div class="overflow-x-auto"><table class="w-full min-w-[700px] text-sm">
                <thead><tr><th class="p-2 text-start">الصنف</th><th class="p-2 text-start">الطلب</th><th class="p-2 text-start">المسلم</th><th class="p-2 text-start">الحجز المتبقي</th><th class="p-2 text-start">المؤجل</th><th class="p-2 text-start">كمية التسليم</th></tr></thead>
                <tbody>@foreach($record->lines as $line)
                    @php($state = $progress['lines'][$line->id] ?? null)
                    <tr wire:key="delivery-line-{{ $line->id }}" class="border-t border-slate-100">
                        <td class="p-2">{{ $line->description }}</td><td class="p-2">{{ $state['base_quantity'] ?? '—' }}</td><td class="p-2">{{ $state['delivered'] ?? '—' }}</td>
                        <td class="p-2">{{ $state['outstanding'] ?? 'يلزم نطاق عرض الحجز' }}</td><td class="p-2">{{ $state['backorder'] ?? 'يلزم نطاق عرض الحجز' }}</td>
                        <td class="p-2">@if($can('delivery_create') && $state && $state['outstanding'] !== null)<flux:input wire:model="deliveryQuantities.{{ $line->id }}" :aria-label="'كمية تسليم '.$line->description" inputmode="decimal" />@endif</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if($can('delivery_create'))
                <div class="mt-4 flex flex-wrap items-end gap-3"><flux:input wire:model="deliveryReference" label="مرجع التسليم والجهة المستلمة" /><flux:button wire:click="createDelivery" wire:loading.attr="disabled" variant="primary">إنشاء طلب تسليم</flux:button></div>
                <div class="mt-5 grid gap-4 md:grid-cols-2">@foreach($record->lines as $line)
                    @php($state = $progress['lines'][$line->id] ?? null)
                    @if($state && $state['backorder'] !== null && bccomp($state['backorder'], '0', 8) > 0)
                        <div class="flex items-end gap-2"><flux:input wire:model="backorderQuantities.{{ $line->id }}" :label="'تخصيص المؤجل: '.$line->description" inputmode="decimal" /><flux:button wire:click="allocateBackorder({{ $line->id }})" wire:loading.attr="disabled">تخصيص المتاح</flux:button></div>
                    @endif
                @endforeach</div>
            @endif
        </x-ui.panel>
        <x-ui.panel>
            <h2 class="mb-4 font-bold">طلبات التسليم</h2>
            @forelse($deliveryInstructions as $instruction)
                <div wire:key="delivery-instruction-{{ $instruction->id }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 py-3">
                    <span>#{{ $instruction->id }} · {{ $instruction->reference }} · {{ $instruction->status === 'confirmed' ? 'تسليم مؤكد' : 'مسودة تسليم' }}</span>
                    @if($instruction->status === 'draft' && $can('delivery_confirm'))<flux:button wire:click="confirmDelivery({{ $instruction->id }})" wire:loading.attr="disabled" variant="primary">تأكيد التسليم والترحيل</flux:button>@endif
                    @if($instruction->status === 'confirmed' && $can('export'))<flux:button :href="route('sales-orders.delivery-pdf', ['order' => $record->id, 'instruction' => $instruction->id])" icon="arrow-down-tray">إذن التسليم PDF</flux:button>@endif
                </div>
            @empty<p class="text-sm text-slate-500">لا توجد طلبات تسليم.</p>@endforelse
        </x-ui.panel>
    @else
        <x-ui.empty-state title="لا يوجد تسليم مؤهل" description="اعتمد أمر البيع وسجل حجز المخزون أولًا." icon="truck" />
    @endif
</div>
