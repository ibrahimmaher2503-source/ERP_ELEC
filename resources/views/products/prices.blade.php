<div data-erp-archetype="list" class="space-y-5">
    <x-page.heading title="أسعار البيع المعتمدة" :description="$product->name" :breadcrumbs="['المنتجات','الأسعار']">
        <x-slot:actions><flux:button :href="route('products.show',$product->id)" wire:navigate>العودة للصنف</flux:button></x-slot:actions>
    </x-page.heading>
    <x-ui.validation-summary />
    @if(session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif
    @if($access->allows(auth()->id(),'M02.price_manage') && $product->active)
    <x-ui.panel>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-3">
            <flux:input label="السعر (ج.م)" wire:model="form.amount" inputmode="decimal" required />
            <flux:select label="الوحدة" wire:model="form.unit_id" required><option value="">اختر الوحدة</option>@if($product->baseUnit?->active)<option value="{{ $product->base_unit_id }}">{{ $product->baseUnit->name }}</option>@endif @foreach($product->conversions as $conversion)@if($conversion->unit?->active)<option value="{{ $conversion->unit_id }}">{{ $conversion->unit->name }}</option>@endif @endforeach</flux:select>
            <flux:input label="مرجع اعتماد السعر" wire:model="form.reference" required />
            <flux:input label="يسري من" type="date" wire:model="form.starts_on" required />
            <flux:input label="يسري حتى" type="date" wire:model="form.ends_on" />
            <div class="self-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ للاعتماد</flux:button></div>
        </form>
        <p class="mt-3 text-sm text-slate-600">يلزم شخص مستقل لاعتماد السعر. لا يسمح بتداخل أسعار معتمدة للصنف والوحدة في التاريخ نفسه.</p>
    </x-ui.panel>
    @elseif(!$product->active)
    <flux:callout color="amber">الصنف غير نشط. فعّله قبل إضافة سعر جديد.</flux:callout>
    @endif
    <x-ui.panel><div class="overflow-x-auto"><x-ui.responsive-table label="السجلات المعروضة"><table role="table" class="w-full text-sm"><thead role="rowgroup" class="bg-blue-50"><tr role="row">@foreach(['الوحدة','السعر (ج.م)','من','حتى','المرجع','الحالة','الإجراء'] as $label)<th role="columnheader" class="p-3 text-start">{{ $label }}</th>@endforeach</tr></thead><tbody role="rowgroup">
        @forelse($rows as $price)<tr role="row" wire:key="price-{{ $price->id }}" class="border-b border-slate-100"><td role="cell" data-label="الوحدة" class="p-3"><div>{{ $price->unit_name ?? $price->unit_id }}</div></td><td role="cell" data-label="السعر (ج.م)" class="p-3"><div>{{ $price->amount }}</div></td><td role="cell" data-label="من" class="p-3"><div>{{ $price->starts_on }}</div></td><td role="cell" data-label="حتى" class="p-3"><div>{{ $price->ends_on ?? 'مفتوح' }}</div></td><td role="cell" data-label="المرجع" class="p-3"><div>{{ $price->reference }}</div></td><td role="cell" data-label="الحالة" class="p-3"><div>{{ ['draft'=>'مسودة','approved'=>'معتمد','withdrawn'=>'مسحوب'][$price->status] }}</div></td><td role="cell" data-label="الإجراء" class="p-3"><div>
        @if($product->active && $price->status==='draft' && $price->created_by!==auth()->id() && $access->allows(auth()->id(),'M02.price_approve'))<flux:button size="sm" wire:click="decide({{ $price->id }},'approve')" wire:loading.attr="disabled">اعتماد</flux:button>@endif
        @if($price->status!=='withdrawn' && $access->allows(auth()->id(),'M02.price_manage'))<flux:button size="sm" wire:click="decide({{ $price->id }},'withdraw')" wire:confirm="سحب السعر من الاستخدام الجديد مع حفظ تاريخه؟" wire:loading.attr="disabled">سحب</flux:button>@endif
        </div></td></tr>@empty<tr role="row"><td colspan="7" class="p-8 text-center text-slate-500">لا توجد أسعار مسجلة لهذا الصنف.</td></tr>@endforelse
    </tbody></table></x-ui.responsive-table></div></x-ui.panel>
</div>
