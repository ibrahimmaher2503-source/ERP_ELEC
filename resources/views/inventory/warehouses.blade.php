@use('App\Modules\Inventory\Presentation\InventoryDisplay', 'D')
@php
    $branchName = fn ($id) => collect($branches)->firstWhere('id', $id)['name'] ?? 'فرع #'.$id;
    $active = (int) ($counts[1] ?? 0);
    $inactive = (int) ($counts[0] ?? 0);
    $hasFilters = $search !== '' || $branch !== '' || $state !== '';
@endphp
<div data-erp-archetype="list" class="inventory-ui space-y-5">
<x-page.heading title="المستودعات" description="تعريف المستودعات وربطها بالفروع وإدارة حالتها" :breadcrumbs="['المخزون','المستودعات']"><x-slot:actions>@if($access->allows(auth()->id(),'warehouse_create'))<flux:button variant="primary" icon="plus" wire:click="create">إضافة مستودع</flux:button>@endif</x-slot:actions></x-page.heading>
@if(session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif

<section class="inventory-kpis" data-cols="3" aria-label="ملخص المستودعات">
    <button type="button" wire:click="$set('state','')" aria-pressed="{{ $state === '' ? 'true' : 'false' }}" @class(['is-active'=>$state===''])><span>إجمالي المستودعات</span><strong>{{ $active + $inactive }}</strong><small>ضمن نطاق بحثك</small></button>
    <button type="button" wire:click="$set('state','active')" aria-pressed="{{ $state === 'active' ? 'true' : 'false' }}" @class(['is-active'=>$state==='active'])><span>نشطة</span><strong>{{ $active }}</strong><small>تقبل مستندات جديدة</small></button>
    <button type="button" wire:click="$set('state','inactive')" aria-pressed="{{ $state === 'inactive' ? 'true' : 'false' }}" @class(['is-active'=>$state==='inactive'])><span>غير نشطة</span><strong>{{ $inactive }}</strong><small>لا تقبل مستندات</small></button>
</section>

<x-ui.panel>
    <x-ui.filters id="warehouse-filters" target="search,branch,state" :reset="['search' => '', 'branch' => '', 'state' => '']">
        <x-slot:search><flux:input label="البحث" wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="اسم أو كود المستودع" clearable /></x-slot:search>
        <div class="grid gap-3 sm:grid-cols-2">
            <flux:select wire:model.live="branch" label="الفرع"><option value="">كل الفروع</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="state" label="الحالة"><option value="">كل الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option></flux:select>
        </div>
    </x-ui.filters>
    <p class="mt-3 text-xs text-slate-500" role="status">{{ $rows->total() }} مستودع</p>
    <div class="mt-3" wire:loading.class="opacity-50" wire:target="search,branch,state,gotoPage,nextPage,previousPage">
    <x-ui.responsive-table label="المستودعات">
        <table role="table" class="w-full text-start text-sm">
            <thead role="rowgroup"><tr role="row">@foreach(['المستودع','الفرع','أمين المخزن','أصناف بها رصيد','الحالة','الإجراءات'] as $h)<th role="columnheader">{{ $h }}</th>@endforeach</tr></thead>
            <tbody role="rowgroup">
            @forelse($rows as $w)
                <tr role="row" wire:key="warehouse-{{ $w->id }}">
                    <td role="cell" data-label="المستودع"><div><strong>{{ $w->name }}</strong><small class="block text-slate-500"><bdi>{{ $w->code }}</bdi>@if($w->address) · {{ \Illuminate\Support\Str::limit($w->address, 40) }}@endif</small></div></td>
                    <td role="cell" data-label="الفرع"><div>{{ $branchName($w->branch_id) }}</div></td>
                    <td role="cell" data-label="أمين المخزن"><div>{{ ($w->storekeeper_name ?? null) ?: '—' }}</div></td>
                    <td role="cell" data-label="أصناف بها رصيد"><div class="tabular-nums">{{ in_array($w->id,$viewable,true) ? (int) ($items[$w->id] ?? 0) : '—' }}</div></td>
                    <td role="cell" data-label="الحالة"><div><x-erp.status-badge :active="$w->active" /></div></td>
                    <td role="cell" data-label="الإجراءات"><div class="flex flex-wrap gap-1">
                        @if(in_array($w->id,$viewable,true))<flux:button size="sm" variant="ghost" :href="route('inventory.balances',['warehouse'=>$w->id])" icon="cube" wire:navigate>الأرصدة</flux:button>@endif
                        @if(in_array($w->id,$movable,true))<flux:button size="sm" variant="ghost" :href="route('inventory.movements',['warehouse'=>$w->id])" icon="arrows-up-down" wire:navigate>الحركات</flux:button>@endif
                        @if($access->allows(auth()->id(),'warehouse_update'))<flux:button size="sm" wire:click="edit({{ $w->id }})" icon="pencil-square">تعديل</flux:button>@endif
                    </div></td>
                </tr>
            @empty
                <tr role="row"><td colspan="6"><x-ui.empty-state :title="$hasFilters?'لا توجد مستودعات مطابقة':'لا توجد مستودعات مسجلة'" :description="$hasFilters?'جرّب تغيير البحث أو امسح الفلاتر.':'أضف مستودعًا ثم امنح المستخدمين نطاقه من تفاصيل المستخدم.'" icon="building-storefront" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </x-ui.responsive-table>
    </div>
    <div class="mt-4">{{ $rows->links() }}</div>
</x-ui.panel>

<flux:modal wire:model="editing" class="md:w-[36rem]"><form wire:submit="save" class="space-y-4">
    <x-ui.validation-summary /><flux:heading size="lg">{{ $editingId ? 'تعديل المستودع':'إضافة مستودع' }}</flux:heading><flux:error name="inventory" />
    <x-erp.code-input prefix="WH" label="كود المستودع" wire:model="form.code" required /><flux:error name="code" />
    <flux:input label="اسم المستودع" wire:model="form.name" required /><flux:error name="name" />
    <flux:select label="الفرع" wire:model="form.branch_id" :disabled="$editingId !== null"><option value="">اختر الفرع</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
    <flux:textarea label="العنوان" wire:model="form.address" /><flux:input label="أمين المخزن" wire:model="form.storekeeper_name" maxlength="200" /><flux:textarea label="ملاحظات" wire:model="form.notes" /><flux:switch label="مستودع نشط" wire:model="form.active" />
    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ المستودع</flux:button>
</form></flux:modal>
</div>
