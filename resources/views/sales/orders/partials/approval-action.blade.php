<x-erp.section-card title="الاعتماد والحجز" icon="check">
    @if($record->status === 'pending_approval' && $can('approve') && !$isReviewRequester)
        <p class="mb-4 text-sm">اختر مستودع كل بند. يسجل الاعتماد الحجز الفعلي والكمية المؤجلة عند نقص المخزون.</p>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach($record->lines as $line)
                <flux:select wire:model="reservationWarehouses.{{ $line->id }}" :label="$line->description">
                    <option value="">اختر مستودع الحجز</option>
                    @foreach($warehouseChoices as $warehouse)<option value="{{ $warehouse['id'] }}">{{ $warehouse['name'] }}</option>@endforeach
                </flux:select>
            @endforeach
        </div>
        @if($commercialReview['blockers'])<ul class="mt-4 list-inside list-disc text-sm text-amber-900">@foreach($commercialReview['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>@endif
        <flux:button class="mt-4" wire:click="operate('approve')" wire:loading.attr="disabled" variant="primary" icon="check">اعتماد أمر البيع وطلب الحجز</flux:button>
    @elseif($record->status === 'approved')
        <p>أمر البيع معتمد، والحجز والتسليم يعرضان النتائج الفعلية في تبويب التسليم.</p>
    @else
        <p class="text-sm">يتطلب الاعتماد طلب مراجعة مستقلًا ومصدرًا تجاريًا معتمدًا وصلاحية الفرع ونطاق مستودع الحجز.</p>
    @endif
    @if($record->source_type === 'direct' && $record->status === 'pending_approval' && $commercialReview['exceptions'])
        <div class="mt-5 space-y-3 border-t border-slate-100 pt-4">
            <h3 class="font-bold">قرار الاستثناءات الإضافي</h3>
            <p class="text-sm">{{ count($commercialReview['exceptions']) }} استثناءات عن الأسعار أو الشروط المعتمدة. يلزم قرار مستقل من دور المسؤول المحدد، ثم اعتماد الأمر.</p>
            @if($can('exception_approve') && !$isReviewRequester)
                <flux:textarea wire:model="exceptionDecisionReason" label="سبب قرار اعتماد الاستثناءات" rows="2" />
                <flux:button wire:click="approveCommercialExceptions" wire:loading.attr="disabled">تسجيل القرار الإضافي</flux:button>
            @endif
        </div>
    @endif
</x-erp.section-card>
