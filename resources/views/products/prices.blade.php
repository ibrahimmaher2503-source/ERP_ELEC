<div data-erp-archetype="list" class="space-y-5" dir="rtl">
    <x-page.heading title="أسعار البيع المعتمدة" :description="$product->name.' · '.$product->sku" :breadcrumbs="['المنتجات', 'الأصناف', $product->name, 'الأسعار']">
        <x-slot:actions>
            <flux:button :href="route('products.show', $product->id)" icon="arrow-right" wire:navigate>العودة للصنف</flux:button>
        </x-slot:actions>
    </x-page.heading>
    <x-ui.validation-summary />
    @if(session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif
    @if($access->allows(auth()->id(),'M02.price_manage') && $product->active)
    <x-erp.section-card title="إضافة مسودة سعر بيع جديد" icon="banknotes">
        <form wire:submit="save" class="grid gap-4 md:grid-cols-3">
            <flux:input label="السعر (ج.م)" wire:model="form.amount" inputmode="decimal" placeholder="مثال: 250.00" required />
            <flux:select label="الوحدة" wire:model="form.unit_id" required><option value="">اختر الوحدة</option>@if($product->baseUnit?->active)<option value="{{ $product->base_unit_id }}">{{ $product->baseUnit->name }} (أساسية)</option>@endif @foreach($product->conversions as $conversion)@if($conversion->unit?->active)<option value="{{ $conversion->unit_id }}">{{ $conversion->unit->name }} (× {{ $conversion->factor }})</option>@endif @endforeach</flux:select>
            <flux:input label="مرجع اعتماد السعر" wire:model="form.reference" placeholder="مثال: قائمة أسعار نوفمبر 2026" required />
            <flux:input label="يسري من تاريخ" type="date" wire:model="form.starts_on" required />
            <flux:input label="يسري حتى تاريخ (اختياري)" type="date" wire:model="form.ends_on" />
            <div class="self-end"><flux:button type="submit" variant="primary" icon="plus" wire:loading.attr="disabled">حفظ مسودة السعر</flux:button></div>
        </form>
        <p class="mt-3 text-xs text-slate-500">يلزم اعتماد السعر ليصبح السعر المرجعي في عروض الأسعار وأوامر البيع. لا يسمح بتداخل أسعار معتمدة للصنف والوحدة في الفترة نفسها.</p>
    </x-erp.section-card>
    @elseif(!$product->active)
    <flux:callout color="amber">الصنف غير نشط. فعّله قبل إضافة سعر جديد.</flux:callout>
    @endif
    <x-erp.section-card :title="'سجل أسعار الصنف ('.$rows->count().')'" icon="clipboard-document-list" class="!p-0 overflow-hidden"><div class="overflow-x-auto"><x-ui.responsive-table label="السجلات المعروضة"><table role="table" class="w-full text-sm"><thead role="rowgroup" class="bg-slate-50 text-slate-600"><tr role="row">@foreach(['الوحدة','السعر (ج.م)','من','حتى','المرجع','الحالة','الإجراء'] as $label)<th role="columnheader" class="p-3.5 text-start">{{ $label }}</th>@endforeach</tr></thead><tbody role="rowgroup" class="divide-y divide-slate-100">
        @php($canSelfApprovePrice = app(\App\Modules\Organization\Application\Contracts\OrganizationApi::class)->allowsSelfApproval(auth()->id(), 'purchasing'))
        @forelse($rows as $price)<tr role="row" wire:key="price-{{ $price->id }}" class="hover:bg-slate-50/70"><td role="cell" data-label="الوحدة" class="p-3.5 font-medium"><div>{{ $price->unit_name ?? $price->unit_id }}</div></td><td role="cell" data-label="السعر (ج.م)" class="p-3.5 font-bold tabular-nums"><div><bdi>{{ $price->amount }}</bdi> ج.م</div></td><td role="cell" data-label="من" class="p-3.5 tabular-nums"><div><bdi>{{ $price->starts_on }}</bdi></div></td><td role="cell" data-label="حتى" class="p-3.5 tabular-nums"><div><bdi>{{ $price->ends_on ?? 'مفتوح' }}</bdi></div></td><td role="cell" data-label="المرجع" class="p-3.5"><div>{{ $price->reference }}</div></td><td role="cell" data-label="الحالة" class="p-3.5"><div><span class="orders-badge" data-size="sm" data-tone="{{ $price->status === 'approved' ? 'success' : ($price->status === 'draft' ? 'warning' : 'neutral') }}">{{ ['draft'=>'مسودة','approved'=>'معتمد','withdrawn'=>'مسحوب'][$price->status] }}</span></div></td><td role="cell" data-label="الإجراء" class="p-3.5"><div class="flex flex-wrap gap-1.5">
        @if($product->active && $price->status==='draft' && ($price->created_by!==auth()->id() || $canSelfApprovePrice) && $access->allows(auth()->id(),'M02.price_approve'))<flux:button size="xs" variant="primary" icon="check" wire:click="decide({{ $price->id }},'approve')" wire:loading.attr="disabled">{{ $price->created_by===auth()->id() ? 'اعتماد (ذاتي موثّق)' : 'اعتماد' }}</flux:button>@endif
        @if($price->status!=='withdrawn' && $access->allows(auth()->id(),'M02.price_manage'))<flux:button size="xs" variant="ghost" icon="x-mark" wire:click="decide({{ $price->id }},'withdraw')" wire:confirm="سحب السعر من الاستخدام الجديد مع حفظ تاريخه؟" wire:loading.attr="disabled">سحب</flux:button>@endif
        </div></td></tr>@empty<tr role="row"><td colspan="7" class="p-8 text-center text-slate-500">لا توجد أسعار مسجلة لهذا الصنف.</td></tr>@endforelse
    </tbody></table></x-ui.responsive-table></div></x-erp.section-card>
</div>
