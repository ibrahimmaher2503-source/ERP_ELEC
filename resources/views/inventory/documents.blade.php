@use('App\Modules\Inventory\Presentation\InventoryDisplay', 'D')
@php
    $access = app(\App\Modules\Inventory\Application\InventoryAccess::class);
    $canCreate = $access->ids(auth()->id(), app(\App\Modules\Inventory\Application\InventoryDocuments::class)->permission($kind, 'create')) !== [];
    $wName = fn ($id) => $warehouses->firstWhere('id', $id)?->name ?? '—';
    $hasFilters = $search !== '' || $warehouse !== '' || $direction !== '' || $status !== '';
    $total = $counts->sum();
    $purchasingNav = $kind === 'opening' ? app(\App\Modules\Purchasing\Application\PurchasingAccess::class)->navigation(auth()->id()) : [];
    $accountingNav = $kind === 'opening' ? app(\App\Modules\Accounting\Application\AccountingAccess::class)->navigation(auth()->id()) : [];
@endphp
<div data-erp-archetype="list" class="inventory-ui space-y-5">
    <x-page.heading :title="$title" description="مستندات مخزنية بدورة اعتماد مستقلة وسجل محفوظ" :breadcrumbs="['المخزون',$title]">
        <x-slot:actions>@if($canCreate)<flux:button variant="primary" icon="plus" :href="route('inventory.document.create',$kind)" wire:navigate>مستند جديد</flux:button>@endif</x-slot:actions>
        @if($canExport)
            <x-slot:secondaryActions>
                <flux:button icon="arrow-down-tray" :href="route('inventory.export',['entity'=>$kind,'warehouse'=>$warehouse])">تصدير Excel</flux:button>
            </x-slot:secondaryActions>
        @endif
    </x-page.heading>

    @if($kind === 'opening')
    <flux:callout icon="information-circle" class="!bg-blue-50">
        <flux:callout.heading>سجل الأرصدة الافتتاحية اليدوية</flux:callout.heading>
        <flux:callout.text>
            هذا السجل مخصص لمستندات الافتتاح اليدوي للمخزون. استلام فواتير المشتريات يتم عبر وحدة المشتريات، والافتتاح المالي المربوط بدليل الحسابات يتم عبر التجهيز المالي.
            @if(isset($purchasingNav['receipts.index']) || isset($accountingNav['setup']))
                <span class="mt-2 flex flex-wrap gap-2">
                    @if(isset($purchasingNav['receipts.index']))<flux:button size="sm" :href="route('purchasing.receipts.index')" wire:navigate>استلام المشتريات</flux:button>@endif
                    @if(isset($accountingNav['setup']))<flux:button size="sm" :href="route('accounting.setup')" wire:navigate>تجهيز النظام المالي</flux:button>@endif
                </span>
            @endif
        </flux:callout.text>
    </flux:callout>
    @endif

    <x-ui.panel>
        <x-ui.filters id="inventory-document-filters" target="search,warehouse,direction,status" :reset="['search' => '', 'warehouse' => '', 'direction' => '', 'status' => '']">
            <x-slot:search><flux:input wire:model.live.debounce.300ms="search" label="البحث" icon="magnifying-glass" placeholder="رقم المستند أو السبب" clearable /></x-slot:search>
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:select wire:model.live="warehouse" label="المستودع"><option value="">كل المستودعات المصرح بها</option>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</flux:select>
                @if($kind === 'transfer' && $warehouse !== '')
                <flux:select wire:model.live="direction" label="اتجاه التحويل">
                    <option value="">كل الاتجاهات</option>
                    <option value="out">صادر من المستودع</option>
                    <option value="in">وارد إلى المستودع</option>
                </flux:select>
                @endif
            </div>
        </x-ui.filters>

        <div class="inventory-chips" role="group" aria-label="تصفية حسب حالة المستند">
            <button type="button" wire:click="$set('status','')" aria-pressed="{{ $status === '' ? 'true' : 'false' }}" @class(['is-active'=>$status===''])>الكل <b>{{ $total }}</b></button>
            @foreach(D::DOCUMENT_STATUS as $key=>$meta)
                @if($counts[$key] ?? 0)
                    @php([$chipLabel] = D::documentStatus($kind, $key))
                    <button type="button" wire:click="$set('status','{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}" @class(['is-active'=>$status===$key])>{{ $chipLabel }} <b>{{ $counts[$key] }}</b></button>
                @endif
            @endforeach
        </div>

        <p class="mt-3 text-xs text-slate-500" role="status">{{ $rows->total() }} مستند</p>
        <div class="mt-3" wire:loading.class="opacity-50" wire:target="search,warehouse,direction,status,gotoPage,nextPage,previousPage">
        <x-ui.responsive-table label="مستندات {{ $title }}">
            <table role="table" class="w-full text-start text-sm">
                <thead role="rowgroup"><tr role="row">@foreach(['المستند','المستودع','الأصناف','السبب','الحالة','آخر تحديث','الإجراء'] as $h)<th role="columnheader">{{ $h }}</th>@endforeach</tr></thead>
                <tbody role="rowgroup">
                @forelse($rows as $r)
                    @php([$statusLabel,$tone] = D::documentStatus($kind, $r->status))
                    @php($ls = $lineStats[$r->id] ?? null)
                    <tr role="row" wire:key="document-{{ $r->id }}">
                        <td role="cell" data-label="المستند"><div><a class="font-semibold" href="{{ route('inventory.document.show',[$kind,$r->id]) }}" wire:navigate>#{{ $r->id }}</a><small class="block text-slate-500"><x-erp.user-name :id="$r->created_by" /></small></div></td>
                        <td role="cell" data-label="المستودع"><div>{{ $wName($r->warehouse_id) }}@if($r->destination_id)<span class="block text-xs text-slate-500">← {{ $wName($r->destination_id) }}</span>@endif</div></td>
                        <td role="cell" data-label="الأصناف"><div>
                            @if(!$ls)—
                            @elseif((int)$ls->units === 1){{ $ls->items }} صنف · <bdi>{{ D::qty((string) $ls->total) }}</bdi> {{ $ls->unit_name }}
                            @else{{ $ls->items }} صنف · <span class="text-slate-500">وحدات متعددة</span>@endif
                        </div></td>
                        <td role="cell" data-label="السبب"><div>{{ \Illuminate\Support\Str::limit($r->reason, 65) }}</div></td>
                        <td role="cell" data-label="الحالة"><div><span class="inventory-badge !ms-0" data-tone="{{ $tone }}">{{ $statusLabel }}</span></div></td>
                        <td role="cell" data-label="آخر تحديث"><div><time>{{ \Carbon\CarbonImmutable::parse($r->updated_at)->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</time></div></td>
                        <td role="cell" data-label="الإجراء"><div><flux:button size="sm" :href="route('inventory.document.show',[$kind,$r->id])" wire:navigate>عرض</flux:button></div></td>
                    </tr>
                @empty
                    <tr role="row"><td colspan="7"><x-ui.empty-state :title="$hasFilters?'لا توجد مستندات مطابقة':'لا توجد مستندات بعد'" :description="$hasFilters?'جرّب تغيير البحث أو امسح الفلاتر.':($canCreate?'ابدأ بإنشاء أول مستند من زر «مستند جديد».':'تظهر المستندات هنا حسب صلاحياتك ونطاق المستودع.')" icon="archive-box" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </x-ui.responsive-table>
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    </x-ui.panel>
</div>
