@if($catalogAvailable && in_array($w->status,['approved','repair','waiting_parts']) && $access->allows(auth()->id(),$w->branch_id,'parts_alternative_approve'))
<x-ui.panel><form wire:submit="approveAlternative" class="space-y-3">
    <flux:heading>اعتماد بديل قبل حجز الطلب الأصلي</flux:heading>
    <flux:select label="طلب القطعة الأصلي" wire:model="requirementId"><option value="">اختر الطلب</option>@foreach($requirements as $r)<option value="{{ $r->id }}">#{{ $r->id }}</option>@endforeach</flux:select>
    <flux:select label="الصنف البديل" wire:model="alternativeProduct" search-model="productSearch"><option value="">اختر البديل</option>@foreach($products as $p)@if($p['kind']!=='service')<option value="{{ $p['id'] }}">{{ $p['name'] }}</option>@endif @endforeach</flux:select>
    <flux:textarea label="مبرر البديل وموافقته الفنية" wire:model="reason" />
    <flux:button type="submit">اعتماد البديل مستقلاً</flux:button>
</form></x-ui.panel>
@endif
