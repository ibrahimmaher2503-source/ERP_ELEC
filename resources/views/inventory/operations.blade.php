@use('App\Modules\Inventory\Presentation\InventoryDisplay', 'D')
@php
    $wName = fn ($id) => $warehouses->firstWhere('id', $id)?->name ?? '—';
    $titles = ['serials' => 'الأرقام المسلسلة', 'isolations' => 'المخزون غير المتاح', 'corrections' => 'تصحيح حركات المخزون'];
    $descriptions = [
        'serials' => 'تتبع كل رقم مسلسل: حالته ومكانه وسجل حركته.',
        'isolations' => 'كميات معزولة عن المتاح (تالف أو تحت الفحص). الرصيد الفعلي ثابت، والإفراج إجراء مسجل مستقل.',
        'corrections' => 'تصحيح حركة مرحّلة بحركة عكسية مرتبطة بها؛ لا تعديل مباشر للأرصدة أو للحركات.',
    ];
    $serialStates = ['available' => ['متاح', 'success'], 'reserved' => ['محجوز', 'info'], 'unavailable' => ['معزول', 'warning'], 'transit' => ['قيد النقل', 'info'], 'issued' => ['مصروف', 'neutral']];
    $hasFilters = $search !== '' || $warehouse !== '';
    $colspan = $screen === 'serials' ? 5 : ($screen === 'isolations' ? 9 : 7);
@endphp
<div data-erp-archetype="list" class="inventory-ui space-y-5">
    <x-page.heading :title="$titles[$screen] ?? 'عمليات المخزون'" :description="$descriptions[$screen] ?? ''" :breadcrumbs="['المخزون',$titles[$screen] ?? '']" />
    <x-ui.validation-summary />
    @if(session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif

    @if($screen!=='serials' && $canExecute)
    <details class="rounded-xl border border-blue-100 bg-white" @if($errors->any() || $form['source_movement_id'] !== '') open @endif>
        <summary class="cursor-pointer p-4 text-sm font-semibold text-blue-800">{{ $screen==='isolations' ? 'تسجيل عزل كمية من مخزون متاح' : 'تسجيل طلب تصحيح مرتبط بالحركة الأصلية' }}</summary>
        <form wire:submit="execute" class="grid gap-4 p-4 pt-0 sm:grid-cols-2">
            <div><flux:input label="ابحث عن حركة المصدر برقمها أو اسم الصنف أو رمزه" wire:model.live.debounce.300ms="sourceSearch" /><flux:select class="mt-2" label="حركة المصدر" wire:model="form.source_movement_id"><option value="">اختر الحركة</option>@foreach($sources as $source)<option value="{{ $source->id }}">#{{ $source->id }} · {{ $source->product_name }} ({{ $source->sku }}) · {{ D::qty($source->quantity) }} · {{ $wName($source->warehouse_id) }}</option>@endforeach</flux:select></div>
            <flux:input label="الكمية" inputmode="decimal" wire:model="form.quantity" required />
            <flux:textarea label="السبب الإلزامي" wire:model="form.reason" required rows="2" />
            <flux:textarea label="المسلسلات — رقم في كل سطر (للأصناف المسلسلة فقط)" wire:model="form.serials" rows="2" />
            <div class="sm:col-span-2"><p class="mb-3 text-sm text-slate-600">{{ $screen==='isolations' ? 'العزل لا يغيّر الرصيد الفعلي، ويخفض المتاح فقط. الإفراج إجراء مستقل مسجل ولا يحدث تلقائيًا.' : 'تفويض التصحيح ونطاق المستودع مطلوبان. أي أثر مالي إلزامي غير متاح يبقي الطلب معلقًا دون ترحيل مخزون.' }}</p><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:confirm="تسجيل هذا الإجراء؟ لا يمكن تعديل السجل بعد التنفيذ.">تنفيذ وتسجيل الإجراء</flux:button></div>
        </form>
    </details>
    @endif

    <x-ui.panel>
        <x-ui.filters id="inventory-operation-filters" target="search,warehouse" :reset="['search' => '', 'warehouse' => '']">
            <x-slot:search><flux:input label="البحث" :placeholder="$screen==='serials'?'الرقم المسلسل أو اسم الصنف أو رمزه':'اسم الصنف أو رمزه أو السبب'" icon="magnifying-glass" wire:model.live.debounce.300ms="search" clearable /></x-slot:search>
            <div class="grid gap-3 sm:grid-cols-2"><flux:select label="المستودع" wire:model.live="warehouse"><option value="">كل المستودعات المسموحة</option>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</flux:select></div>
        </x-ui.filters>
        <p class="mt-3 text-xs text-slate-500" role="status">{{ $rows->total() }} سجل</p>

        <div class="mt-3" wire:loading.class="opacity-50" wire:target="search,warehouse,gotoPage,nextPage,previousPage">
        <x-ui.responsive-table :label="$titles[$screen] ?? 'السجلات'">
            <table role="table" class="w-full text-start text-sm">
                <thead role="rowgroup"><tr role="row">
                @foreach(match($screen){'serials'=>['الرقم المسلسل','الصنف','الحالة','المستودع','الإجراء'],'isolations'=>['المرجع','الصنف','المستودع','المعزول','المحرَّر','المتبقي','السبب','الوقت','الإجراء'],default=>['المرجع','الحركة الأصلية','المستودع','كمية التصحيح','السبب','النتيجة','الوقت']} as $h)<th role="columnheader">{{ $h }}</th>@endforeach
                </tr></thead>
                <tbody role="rowgroup">
                @forelse($rows as $row)
                    <tr role="row" wire:key="operation-{{ $row->id }}">
                    @if($screen==='serials')
                        @php([$sl,$st] = $serialStates[$row->state] ?? [$row->state,'neutral'])
                        <td role="cell" data-label="الرقم المسلسل"><div><strong dir="ltr">{{ $row->entered }}</strong>@if($row->normalized !== $row->entered)<small class="block text-slate-500" dir="ltr">{{ $row->normalized }}</small>@endif</div></td>
                        <td role="cell" data-label="الصنف"><div><strong>{{ $row->product_name }}</strong><small class="block text-slate-500"><bdi>{{ $row->sku }}</bdi></small></div></td>
                        <td role="cell" data-label="الحالة"><div><span class="inventory-badge !ms-0" data-tone="{{ $st }}">{{ $sl }}</span></div></td>
                        <td role="cell" data-label="المستودع"><div>
                            @if($row->warehouse_id){{ $wName($row->warehouse_id) }}
                            @elseif($row->transfer_destination_id)في الطريق إلى {{ $wName($row->transfer_destination_id) }}@if($row->transfer_id && in_array($row->transfer_id,$viewableTransferIds,true))<small class="block"><a class="text-blue-700 hover:underline" href="{{ route('inventory.document.show',['transfer',$row->transfer_id]) }}" wire:navigate>تحويل #{{ $row->transfer_id }}</a></small>@endif
                            @else قيد النقل@endif
                        </div></td>
                        <td role="cell" data-label="الإجراء"><div><flux:button size="sm" wire:click="inspectSerial({{ $row->id }})" icon="clock">السجل</flux:button></div></td>
                    @elseif($screen==='isolations')
                        @php($left = bcsub($row->quantity,$row->released,8))
                        <td role="cell" data-label="المرجع"><div>#{{ $row->id }}</div></td>
                        <td role="cell" data-label="الصنف"><div><strong>{{ $row->product_name }}</strong><small class="block text-slate-500"><bdi>{{ $row->sku }}</bdi> · حركة #{{ $row->source_movement_id }}</small></div></td>
                        <td role="cell" data-label="المستودع"><div>{{ $wName($row->wh) }}</div></td>
                        <td role="cell" data-label="المعزول"><div class="tabular-nums">{{ D::qty($row->quantity) }}</div></td>
                        <td role="cell" data-label="المحرَّر"><div class="tabular-nums">{{ D::qty($row->released) }}</div></td>
                        <td role="cell" data-label="المتبقي"><div>@if(bccomp($left,'0',8)>0)<span class="inventory-badge !ms-0" data-tone="warning"><bdi>{{ D::qty($left) }}</bdi> معزول</span>@else<span class="inventory-badge !ms-0" data-tone="success">أُفرج بالكامل</span>@endif</div></td>
                        <td role="cell" data-label="السبب"><div>{{ \Illuminate\Support\Str::limit($row->reason, 60) }}</div></td>
                        <td role="cell" data-label="الوقت"><div><time>{{ \Carbon\CarbonImmutable::parse($row->occurred_at)->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</time><small class="block text-slate-500"><x-erp.user-name :id="$row->actor_id" /></small></div></td>
                        <td role="cell" data-label="الإجراء"><div>@if(bccomp($left,'0',8)>0 && $access->allows(auth()->id(),'isolation_release',$row->wh))<flux:button size="sm" icon="arrow-uturn-left" wire:click="openRelease({{ $row->id }})">إفراج</flux:button>@else<span class="text-slate-400">—</span>@endif</div></td>
                    @else
                        <td role="cell" data-label="المرجع"><div>#{{ $row->id }}</div></td>
                        <td role="cell" data-label="الحركة الأصلية"><div><strong>{{ $row->product_name }}</strong><small class="block text-slate-500"><bdi>{{ $row->sku }}</bdi> · {{ D::operation($row->movement_operation) }} · حركة #{{ $row->movement_id }}</small></div></td>
                        <td role="cell" data-label="المستودع"><div>{{ $wName($row->wh) }}</div></td>
                        <td role="cell" data-label="كمية التصحيح"><div class="tabular-nums">{{ D::qty($row->quantity) }}</div></td>
                        <td role="cell" data-label="السبب"><div>{{ \Illuminate\Support\Str::limit($row->reason, 60) }}</div></td>
                        <td role="cell" data-label="النتيجة"><div>@if($row->status==='posted')<span class="inventory-badge !ms-0" data-tone="success">مرحّل</span>@else<span class="inventory-badge !ms-0" data-tone="warning">بانتظار التكامل المالي</span>@endif</div></td>
                        <td role="cell" data-label="الوقت"><div><time>{{ \Carbon\CarbonImmutable::parse($row->occurred_at)->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</time><small class="block text-slate-500"><x-erp.user-name :id="$row->actor_id" /></small></div></td>
                    @endif
                    </tr>
                @empty
                    <tr role="row"><td colspan="{{ $colspan }}"><x-ui.empty-state :title="$hasFilters?'لا توجد سجلات مطابقة':'لا توجد سجلات بعد'" :description="$hasFilters?'جرّب تغيير البحث أو امسح الفلاتر.':'تظهر السجلات هنا بعد تنفيذ أول إجراء ضمن نطاق مستودعاتك.'" icon="archive-box" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </x-ui.responsive-table>
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    </x-ui.panel>

    <flux:modal wire:model="showSerialHistory" class="md:w-[38rem]">
        @if($selectedSerialRecord)
        @php([$sl,$st] = $serialStates[$selectedSerialRecord->state] ?? [$selectedSerialRecord->state,'neutral'])
        <div class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <flux:heading size="lg"><bdi>{{ $selectedSerialRecord->entered }}</bdi></flux:heading>
                    <p class="mt-1 text-xs text-slate-500">{{ $selectedSerialRecord->product_name }} · <bdi>{{ $selectedSerialRecord->sku }}</bdi>@if($selectedSerialRecord->normalized !== $selectedSerialRecord->entered) · <bdi>{{ $selectedSerialRecord->normalized }}</bdi>@endif</p>
                </div>
                <span class="inventory-badge !ms-0" data-tone="{{ $st }}">{{ $sl }}</span>
            </div>
            <p class="text-xs text-slate-600">الموقع الحالي: <strong>
                @if($selectedSerialRecord->warehouse_id){{ $wName($selectedSerialRecord->warehouse_id) }}
                @elseif($selectedSerialRecord->transfer_destination_id)في الطريق إلى {{ $wName($selectedSerialRecord->transfer_destination_id) }}@if($selectedSerialRecord->transfer_id && in_array($selectedSerialRecord->transfer_id,$viewableTransferIds,true)) (<a class="text-blue-700 hover:underline" href="{{ route('inventory.document.show',['transfer',$selectedSerialRecord->transfer_id]) }}" wire:navigate>تحويل #{{ $selectedSerialRecord->transfer_id }}</a>)@endif
                @else قيد النقل@endif
            </strong></p>
            <ol class="divide-y divide-slate-100 border-t border-slate-100">
                @forelse($history as $event)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                        <strong>{{ D::serialEvent($event->action) }}</strong>
                        <span class="text-slate-600">@if($event->movement_id)حركة #{{ $event->movement_id }}@else—@endif</span>
                        <span class="text-slate-600"><x-erp.user-name :id="$event->actor_id" /></span>
                        <time class="text-xs text-slate-500">{{ \Carbon\CarbonImmutable::parse($event->occurred_at)->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</time>
                    </li>
                @empty
                    <li class="py-3 text-sm text-slate-500">لا توجد أحداث مسجلة.</li>
                @endforelse
            </ol>
        </div>
        @endif
    </flux:modal>

    <flux:modal wire:model="showRelease" class="md:w-[32rem]">
        @if($releasing)
        @php($remaining = bcsub($releasing->quantity,$releasing->released,8))
        <form wire:submit="release({{ $releasing->id }})" class="space-y-4">
            <flux:heading size="lg">إفراج عن كمية معزولة</flux:heading>
            <dl class="grid grid-cols-3 gap-3 rounded-lg bg-slate-50 p-3 text-sm"><div><dt class="text-slate-500">الصنف</dt><dd class="font-semibold">{{ $releasing->product_name }}</dd></div><div><dt class="text-slate-500">المعزول</dt><dd class="tabular-nums">{{ D::qty($releasing->quantity) }}</dd></div><div><dt class="text-slate-500">المتبقي</dt><dd class="font-semibold tabular-nums">{{ D::qty($remaining) }}</dd></div></dl>
            <flux:input label="كمية الإفراج" wire:model="releaseQuantities.{{ $releasing->id }}" inputmode="decimal" required />
            @if($releasingHasSerials)
                <flux:textarea label="مسلسلات الإفراج — رقم في كل سطر" wire:model="releaseSerials.{{ $releasing->id }}" rows="2" required />
            @endif
            <flux:error name="inventory" />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:confirm="الإفراج عن الكمية المحددة؟">تسجيل الإفراج</flux:button>
        </form>
        @endif
    </flux:modal>
</div>
