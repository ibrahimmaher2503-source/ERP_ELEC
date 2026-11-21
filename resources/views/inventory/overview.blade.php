@use('App\Modules\Inventory\Presentation\InventoryDisplay', 'D')
@php
    $access = app(\App\Modules\Inventory\Application\InventoryAccess::class);
    $docs = app(\App\Modules\Inventory\Application\InventoryDocuments::class);
    $canDoc = fn ($k) => $access->ids(auth()->id(), $docs->permission($k, 'create')) !== [];
    $wName = fn ($id) => $warehouseNames[$id] ?? $warehouses->firstWhere('id', $id)?->name ?? '—';
    $isDash = $screen === 'dashboard';
    $isBalances = $screen === 'balances';
    $hasFilters = $search !== '' || $branch !== '' || $warehouse !== '' || $operation !== '' || $status !== '' || $category !== '' || $dateFrom !== '' || $dateTo !== '';
    $colspan = $screen === 'reservations' ? 10 : ($isBalances ? 8 : 7);
@endphp
<div data-erp-archetype="{{ $isDash ? 'dashboard' : ($screen === 'reservations' ? 'list' : 'report') }}" class="inventory-ui space-y-5">

    <x-page.heading :title="$title" :description="match($screen){'dashboard'=>'ما يحتاج متابعتك الآن في المستودعات المصرح بها','balances'=>'الرصيد الفعلي والمحجوز والمتاح لكل صنف في كل مستودع','movements'=>'سجل ثابت لكل ما رُحّل في المخزون، بدون تعديل','reservations'=>'الكميات المحجوزة لطلبات البيع والمشروعات والصيانة'}" :breadcrumbs="['المخزون',$title]">
        <x-slot:actions>
            @if(($isDash || $isBalances) && $canDoc('opening'))<flux:button variant="primary" icon="plus" :href="route('inventory.document.create','opening')" wire:navigate>إضافة رصيد لمنتج</flux:button>@endif
        </x-slot:actions>
        @if($canExport)
        <x-slot:secondaryActions>
            <flux:button icon="arrow-down-tray" :href="route('inventory.export',['entity'=>$isDash?'balances':$screen,'warehouse'=>$warehouse,'branch'=>$branch,'search'=>$search,'status'=>$status,'category'=>$category,'operation'=>$operation,'date_from'=>$dateFrom,'date_to'=>$dateTo])">تصدير Excel</flux:button>
        </x-slot:secondaryActions>
        @endif
    </x-page.heading>
    <x-ui.validation-summary />

    @if($isDash)
        @if($canViewBalanceSummary && ($stats['products'] ?? 0) === 0)
            <x-ui.panel class="border-blue-200 bg-blue-50/60"><div class="flex flex-wrap items-center justify-between gap-4"><div class="max-w-2xl"><h2 class="text-lg font-bold text-blue-900">ابدأ بإضافة أول رصيد</h2><p class="mt-1 text-sm leading-7 text-slate-600">لا يوجد أي رصيد مرحّل في المخزون حتى الآن. أضف رصيدًا افتتاحيًا: اختر المستودع والصنف والكمية، احفظ المسودة، ثم أرسلها للاعتماد من مستخدم آخر وبعدها رحّلها.</p></div>@if($canDoc('opening'))<flux:button variant="primary" icon="plus" :href="route('inventory.document.create','opening')" wire:navigate>إضافة رصيد لمنتج</flux:button>@endif</div></x-ui.panel>
        @endif

        @if($canViewBalanceSummary)
        <section aria-label="ملخص المخزون" class="inventory-kpis erp-kpi-summary">
            @if($canViewBalances)<a href="{{ route('inventory.balances') }}" wire:navigate><span>الأصناف ذات أرصدة</span><strong>{{ $stats['products'] }}</strong><small>عرض كل الأرصدة</small></a>
            @else<div><span>الأصناف ذات أرصدة</span><strong>{{ $stats['products'] }}</strong><small>ضمن نطاقك</small></div>@endif

            @if($canViewWarehouses)<a href="{{ route('inventory.warehouses') }}" wire:navigate><span>المستودعات النشطة</span><strong>{{ $stats['warehouses'] }}</strong><small>ضمن نطاقك</small></a>
            @else<div><span>المستودعات النشطة</span><strong>{{ $stats['warehouses'] }}</strong><small>ضمن نطاقك</small></div>@endif

            @if($canViewBalances)<a href="{{ route('inventory.balances',['status'=>'low']) }}" wire:navigate @class(['is-warning'=>$stats['low']>0])><span>تحت الحد الأدنى</span><strong>{{ $stats['low'] }}</strong><small>تحتاج توريد أو تحويل</small></a>
            @else<div @class(['is-warning'=>$stats['low']>0])><span>تحت الحد الأدنى</span><strong>{{ $stats['low'] }}</strong><small>تحتاج توريد أو تحويل</small></div>@endif

            @if($canViewBalances)<a href="{{ route('inventory.balances',['status'=>'empty']) }}" wire:navigate @class(['is-danger'=>$stats['empty']>0])><span>متاحها صفر</span><strong>{{ $stats['empty'] }}</strong><small>لا يمكن صرفها حاليًا</small></a>
            @else<div @class(['is-danger'=>$stats['empty']>0])><span>متاحها صفر</span><strong>{{ $stats['empty'] }}</strong><small>لا يمكن صرفها حاليًا</small></div>@endif
        </section>
        @endif

        <section aria-label="إجراءات سريعة"><h2 class="mb-3 text-sm font-semibold text-slate-500">إجراءات سريعة</h2><div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([['opening','إضافة رصيد افتتاحي','plus','أدخل كمية صنف في مستودع'],['issue','صرف من المخزون','arrow-up-tray','اخصم كمية بمستند صرف'],['transfer','تحويل بين المستودعات','arrows-right-left','انقل كمية من مستودع إلى آخر'],['count','جرد المخزون','clipboard-document-list','قارن العد الفعلي بالدفتري']] as [$k,$t,$ic,$d])
                @if($canDoc($k))<a href="{{ route('inventory.document.create',$k) }}" wire:navigate class="inventory-action"><span class="inventory-action-icon"><flux:icon :name="$ic" class="size-5" /></span><span><strong>{{ $t }}</strong><small>{{ $d }}</small></span></a>@endif
            @endforeach
        </div></section>

        <div class="grid gap-4 lg:grid-cols-5">
            <x-erp.section-card title="مهام تحتاج إجراءك" icon="bell-alert" class="lg:col-span-3">
                <div class="space-y-5">
                    <div>
                        <h3 class="inventory-task-title">مستندات بانتظار اعتمادك <span class="inventory-count">{{ $pendingApprovals->count() }}</span></h3>
                        @forelse($pendingApprovals as $doc)
                            <a href="{{ route('inventory.document.show',[$doc->kind,$doc->id]) }}" wire:navigate class="inventory-task"><strong>{{ \App\Modules\Inventory\Application\InventoryDocuments::KINDS[$doc->kind] }} #{{ $doc->id }}</strong><span>{{ $wName($doc->warehouse_id) }}@if($doc->destination_id) ← {{ $wName($doc->destination_id) }}@endif</span><em>عرض واعتماد</em></a>
                        @empty<p class="inventory-task-empty">لا توجد مستندات بانتظار اعتمادك.</p>@endforelse
                    </div>
                    <div>
                        <h3 class="inventory-task-title">تحويلات مرسلة بانتظار الاستلام <span class="inventory-count">{{ $transit->count() }}</span></h3>
                        @forelse($transit as $t)
                            <a href="{{ route('inventory.document.show',['transfer',$t->transfer_id]) }}" wire:navigate class="inventory-task"><strong>{{ $t->product_name }}</strong><span><bdi>{{ D::qty($t->quantity) }}</bdi> {{ $t->unit_name }} · تحويل #{{ $t->transfer_id }}</span><em>استلام</em></a>
                        @empty<p class="inventory-task-empty">لا توجد تحويلات تنتظر الاستلام.</p>@endforelse
                    </div>
                    @if($canViewBalanceSummary)
                    <div>
                        <h3 class="inventory-task-title">أصناف تحت الحد الأدنى <span class="inventory-count">{{ $stats['low'] }}</span></h3>
                        @forelse($lowItems as $item)
                            @if($canViewBalances)
                                <a href="{{ route('inventory.balances',['search'=>$item->sku,'warehouse'=>$item->warehouse_id]) }}" wire:navigate class="inventory-task"><strong>{{ $item->product_name }}</strong><span>{{ $wName($item->warehouse_id) }} · المتاح <bdi>{{ D::qty($item->available) }}</bdi> من <bdi>{{ D::qty($item->minimum) }}</bdi></span><em>عرض</em></a>
                            @else
                                <div class="inventory-task"><strong>{{ $item->product_name }}</strong><span>{{ $wName($item->warehouse_id) }} · المتاح <bdi>{{ D::qty($item->available) }}</bdi> من <bdi>{{ D::qty($item->minimum) }}</bdi></span></div>
                            @endif
                        @empty<p class="inventory-task-empty">كل الأصناف ذات حد أدنى فوق حدها.</p>@endforelse
                        @if($stats['low'] > 5 && $canViewBalances)<a class="inventory-more" href="{{ route('inventory.balances',['status'=>'low']) }}" wire:navigate>عرض كل الأصناف ({{ $stats['low'] }}) ←</a>@endif
                    </div>
                    @endif
                </div>
            </x-erp.section-card>

            <x-erp.section-card title="توزيع الأصناف حسب المستودع" icon="building-storefront" class="lg:col-span-2">
                <div class="space-y-4">
                    @forelse($distribution as $d)
                        @if($canViewBalances)
                            <a href="{{ route('inventory.balances',['warehouse'=>$d->warehouse_id]) }}" wire:navigate class="block"><div class="flex justify-between text-sm"><span>{{ $wName($d->warehouse_id) }}</span><strong>{{ $d->products }} صنف</strong></div><progress max="{{ max(1,$stats['products']) }}" value="{{ $d->products }}" class="inventory-progress" aria-label="{{ $wName($d->warehouse_id) }}"></progress></a>
                        @else
                            <div class="block"><div class="flex justify-between text-sm"><span>{{ $wName($d->warehouse_id) }}</span><strong>{{ $d->products }} صنف</strong></div><progress max="{{ max(1,$stats['products']) }}" value="{{ $d->products }}" class="inventory-progress" aria-label="{{ $wName($d->warehouse_id) }}"></progress></div>
                        @endif
                    @empty<p class="py-6 text-center text-slate-500">لا يوجد مخزون مرحّل بعد.</p>@endforelse
                </div>
                <p class="mt-5 border-t border-slate-100 pt-3 text-xs leading-6 text-slate-500">تقييم المخزون بالقيمة غير متاح هنا حتى اعتماد عقد التكلفة والمحاسبة؛ لا تُستخدم أسعار البيع لتقدير القيمة.</p>
            </x-erp.section-card>
        </div>

        <x-erp.section-card title="أحدث الحركات المرحّلة" icon="arrows-up-down">
            <div class="divide-y divide-slate-100">
                @forelse($recent as $m)
                    <div class="inventory-recent"><strong>{{ $m->product_name }}</strong><span class="inventory-dir" data-dir="{{ D::isPositive($m->quantity) ? 'in' : 'out' }}" dir="ltr">{{ D::isPositive($m->quantity) ? '+' : '' }}{{ D::qty($m->quantity) }}</span><span>{{ D::operation($m->operation) }}</span><span class="text-slate-500">{{ $wName($m->warehouse_id) }}</span><time class="text-slate-500">{{ \Carbon\CarbonImmutable::parse($m->posted_at)->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</time></div>
                @empty<p class="p-6 text-center text-slate-500">لا توجد حركات مرحّلة.</p>@endforelse
            </div>
            @if($recent->isNotEmpty() && $canViewMovements)<a class="inventory-more" href="{{ route('inventory.movements') }}" wire:navigate>عرض كل الحركات ←</a>@endif
        </x-erp.section-card>
    @else
    <x-ui.panel>
        <x-ui.filters id="inventory-filters" target="search,branch,warehouse,operation,status,category,dateFrom,dateTo" :reset="['search' => '', 'branch' => '', 'warehouse' => '', 'operation' => '', 'status' => '', 'category' => '', 'dateFrom' => '', 'dateTo' => '']">
            <x-slot:search><flux:input wire:model.live.debounce.300ms="search" label="البحث" icon="magnifying-glass" :placeholder="$screen==='reservations'?'اسم الصنف أو رمزه أو مرجع الطلب':'اسم الصنف أو رمزه'" clearable /></x-slot:search>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <flux:select wire:model.live="branch" label="الفرع"><option value="">كل الفروع المصرح بها</option>@foreach($branches as $b)<option value="{{ $b['branch_id'] }}">{{ $b['branch_name'] ?? 'فرع #'.$b['branch_id'] }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="warehouse" label="المستودع"><option value="">كل المستودعات المصرح بها</option>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</flux:select>
                @if($screen==='movements')
                    <flux:select wire:model.live="operation" label="نوع الحركة"><option value="">كل الحركات</option>@foreach(D::OPERATIONS as $v=>$label)<option value="{{ $v }}">{{ $label }}</option>@endforeach</flux:select>
                    <flux:input type="date" wire:model.live="dateFrom" label="من تاريخ" />
                    <flux:input type="date" wire:model.live="dateTo" label="إلى تاريخ" />
                @endif
                @if($isBalances)<flux:select wire:model.live="category" label="التصنيف"><option value="">كل التصنيفات</option>@foreach($categories as $c)<option value="{{ $c->category_id }}">{{ $c->category_name }}</option>@endforeach</flux:select>@endif
            </div>
        </x-ui.filters>

        @if($isBalances && $chips)
        <div class="inventory-chips" role="group" aria-label="تصفية سريعة لحالة الرصيد">
            @foreach(['' => 'الكل','low'=>'تحت الحد الأدنى','empty'=>'متاح صفر','reserved'=>'به حجز','unavailable'=>'به غير متاح'] as $key=>$label)
                <button type="button" wire:click="$set('status','{{ $key }}')" aria-pressed="{{ $status === (string) $key ? 'true' : 'false' }}" @class(['is-active'=>$status === (string) $key])>{{ $label }} <b>{{ $chips[$key] }}</b></button>
            @endforeach
        </div>
        @endif

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-slate-500" role="status">{{ $rows->total() }} نتيجة</p>
            @if($isBalances)<p class="inventory-formula">المعادلة: <strong>الفعلي − المحجوز − غير المتاح = المتاح للصرف</strong></p>@endif
        </div>

        <div class="mt-3" wire:loading.class="opacity-50" wire:target="search,branch,warehouse,operation,status,category,dateFrom,dateTo,gotoPage,nextPage,previousPage">
        <x-ui.responsive-table :label="$title">
            <table role="table" class="w-full text-start text-sm">
                <thead role="rowgroup"><tr role="row">
                @foreach(match($screen){'movements'=>['الحركة','الصنف','المستودع','النوع','الكمية','المصدر','وقت الترحيل'],'reservations'=>['الحجز','الصنف','المستودع','المطلوب','المحجوز','المنفذ','المحرر','المتبقي','الحالة','المصدر'],default=>['الصنف','المستودع','المتاح','الفعلي','المحجوز','غير متاح','الحد الأدنى','إجراءات']} as $h)<th role="columnheader">{{ $h }}</th>@endforeach
                </tr></thead>
                <tbody role="rowgroup">
                @forelse($rows as $r)
                    <tr role="row" wire:key="inventory-row-{{ $r->id }}">
                    @if($screen==='movements')
                        @php($in = D::isPositive($r->quantity))
                        @php($unit = $movementUnits->get($r->warehouse_id.':'.$r->product_id)?->unit_name)
                        <td role="cell" data-label="الحركة"><div>#{{ $r->id }}</div></td>
                        <td role="cell" data-label="الصنف"><div><strong>{{ $r->product_name }}</strong><small class="block text-slate-500"><bdi>{{ $r->sku }}</bdi></small></div></td>
                        <td role="cell" data-label="المستودع"><div>{{ $wName($r->warehouse_id) }}</div></td>
                        <td role="cell" data-label="النوع"><div>{{ D::operation($r->operation) }}</div></td>
                        <td role="cell" data-label="الكمية"><div class="flex flex-wrap items-center gap-1.5"><span class="inventory-badge !ms-0" data-tone="{{ $in ? 'success' : 'warning' }}">{{ $in ? 'وارد' : 'صادر' }}</span><span class="inventory-dir" data-dir="{{ $in ? 'in' : 'out' }}" dir="ltr">{{ $in ? '+' : '' }}{{ D::qty($r->quantity) }}</span>@if($unit)<small class="text-slate-500">{{ $unit }}</small>@endif</div></td>
                        <td role="cell" data-label="المصدر"><div>
                            @if($r->document_id && isset($docKinds[$r->document_id]))<a href="{{ route('inventory.document.show',[$docKinds[$r->document_id],$r->document_id]) }}" wire:navigate>{{ \App\Modules\Inventory\Application\InventoryDocuments::KINDS[$docKinds[$r->document_id]] ?? 'مستند' }} #{{ $r->document_id }}</a>
                            @else{{ D::source($r->source_module) }} #{{ $r->source_id }}@endif
                        </div></td>
                        <td role="cell" data-label="وقت الترحيل"><div><time>{{ \Carbon\CarbonImmutable::parse($r->posted_at)->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</time></div></td>
                    @elseif($screen==='reservations')
                        @php($left = bcsub(bcsub($r->reserved,$r->fulfilled,8),$r->released,8))
                        @php($pn = $productNames[$r->product_id] ?? null)
                        @php($short = bcsub($r->requested,$r->reserved,8))
                        <td role="cell" data-label="الحجز"><div>#{{ $r->id }}</div></td>
                        <td role="cell" data-label="الصنف"><div><strong>{{ $pn?->product_name ?? 'صنف #'.$r->product_id }}</strong>@if($pn)<small class="block text-slate-500"><bdi>{{ $pn->sku }}</bdi></small>@endif</div></td>
                        <td role="cell" data-label="المستودع"><div>{{ $wName($r->warehouse_id) }}</div></td>
                        @foreach(['requested','reserved','fulfilled','released'] as $f)<td role="cell" data-label="{{ ['requested'=>'المطلوب','reserved'=>'المحجوز','fulfilled'=>'المنفذ','released'=>'المحرر'][$f] }}"><div class="tabular-nums">{{ D::qty($r->$f) }}</div></td>@endforeach
                        <td role="cell" data-label="المتبقي"><div class="font-bold tabular-nums">{{ D::qty($left) }}</div></td>
                        <td role="cell" data-label="الحالة"><div>
                            @if(bccomp($left,'0',8)>0)<span class="inventory-badge" data-tone="info">محجوز</span>
                            @elseif(bccomp($r->fulfilled,'0',8)>0)<span class="inventory-badge" data-tone="success">منفّذ</span>
                            @else<span class="inventory-badge" data-tone="neutral">محرّر</span>@endif
                            @if(bccomp($short,'0',8)>0 && bccomp($left,'0',8)>0)<span class="inventory-badge" data-tone="warning">ينقص {{ D::qty($short) }}</span>@endif
                        </div></td>
                        <td role="cell" data-label="المصدر"><div>{{ D::source($r->source_module) }} #{{ $r->source_id }}</div></td>
                    @else
                        @php($isZero = bccomp($r->available,'0',8)===0)
                        @php($isLow = $r->minimum !== null && bccomp($r->available,$r->minimum,8)<0)
                        <td role="cell" data-label="الصنف"><div>@if($canViewProducts)<a class="font-semibold text-blue-700 hover:underline" href="{{ route('products.show',$r->product_id) }}" wire:navigate>{{ $r->product_name }}</a>@else<strong>{{ $r->product_name }}</strong>@endif<small class="block text-slate-500"><bdi>{{ $r->sku }}</bdi> · {{ $r->category_name ?: 'بدون تصنيف' }}</small></div></td>
                        <td role="cell" data-label="المستودع"><div>{{ $wName($r->warehouse_id) }}</div></td>
                        <td role="cell" data-label="المتاح"><div><strong @class(['tabular-nums','text-red-700'=>$isZero,'text-amber-700'=>$isLow && !$isZero])>{{ D::qty($r->available) }}</strong> <small class="text-slate-500">{{ $r->unit_name }}</small>@if($isZero)<span class="inventory-badge" data-tone="danger">منعدم</span>@elseif($isLow)<span class="inventory-badge" data-tone="warning">تحت الحد</span>@endif</div></td>
                        <td role="cell" data-label="الفعلي"><div class="tabular-nums">{{ D::qty($r->on_hand) }}</div></td>
                        <td role="cell" data-label="المحجوز"><div class="tabular-nums">{{ D::qty($r->reserved) }}</div></td>
                        <td role="cell" data-label="غير متاح"><div>@if(bccomp($r->unavailable,'0',8)>0)<span class="inventory-badge !ms-0" data-tone="warning">{{ D::qty($r->unavailable) }}</span>@else<span class="text-slate-400">—</span>@endif</div></td>
                        <td role="cell" data-label="الحد الأدنى"><div class="tabular-nums">{{ $r->minimum !== null ? D::qty($r->minimum) : 'غير محدد' }}</div></td>
                        <td role="cell" data-label="إجراءات"><div class="flex flex-wrap gap-1">
                            @if($access->allows(auth()->id(),'movement_view',$r->warehouse_id))<flux:button size="sm" variant="ghost" icon="arrows-up-down" :href="route('inventory.movements',['search'=>$r->sku,'warehouse'=>$r->warehouse_id])" wire:navigate>الحركات</flux:button>@endif
                            @if($access->allows(auth()->id(),'reorder_manage',$r->warehouse_id))<flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editMinimum({{ $r->id }})">الحد الأدنى</flux:button>@endif
                            @if($access->allows(auth()->id(),'reservation_view',$r->warehouse_id) && bccomp($r->reserved,'0',8)>0)<flux:button size="sm" variant="ghost" icon="bookmark-square" :href="route('inventory.reservations',['search'=>$r->sku,'warehouse'=>$r->warehouse_id])" wire:navigate>الحجوزات</flux:button>@endif
                            @if(($access->allows(auth()->id(),'isolation_manage',$r->warehouse_id) || $access->allows(auth()->id(),'isolation_release',$r->warehouse_id)) && bccomp($r->unavailable,'0',8)>0)<flux:button size="sm" variant="ghost" icon="no-symbol" :href="route('inventory.isolations',['search'=>$r->sku,'warehouse'=>$r->warehouse_id])" wire:navigate>المعزول</flux:button>@endif
                        </div></td>
                    @endif
                    </tr>
                @empty
                    <tr role="row"><td colspan="{{ $colspan }}"><x-ui.empty-state :title="$hasFilters?'لا توجد نتائج مطابقة':'لا توجد سجلات ضمن النطاق'" :description="$hasFilters?'جرّب تغيير البحث أو امسح الفلاتر لعرض كل السجلات المتاحة.':'المستندات المسودة لا تغيّر المخزون؛ تظهر السجلات بعد الترحيل.'" icon="archive-box" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </x-ui.responsive-table>
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    </x-ui.panel>
    @endif

    @if($isBalances)
    <details class="rounded-xl border border-blue-100 bg-white"><summary class="cursor-pointer p-4 text-sm font-semibold text-blue-800">مطابقة الأرصدة مع دفتر الحركات والحجوزات (للمراجعة)</summary>
        <div class="space-y-3 p-4 pt-0">
            <div class="flex flex-wrap gap-2">
                @foreach($warehouses as $w)
                    @if($access->allows(auth()->id(),'balance_view',$w->id) && $access->allows(auth()->id(),'movement_view',$w->id) && $access->allows(auth()->id(),'reservation_view',$w->id))
                        <flux:button size="sm" wire:click="reconcileWarehouse({{ $w->id }})" wire:loading.attr="disabled" wire:target="reconcileWarehouse">مطابقة {{ $w->name }}</flux:button>
                    @endif
                @endforeach
            </div>
            @if($reconciliation)
                <p role="status" class="text-sm {{ $reconciliation['reconciled'] ? 'text-emerald-800' : 'text-red-700' }}">{{ $wName($reconciliation['warehouse_id']) }}: فُحص {{ $reconciliation['products_checked'] }} صنف، {{ $reconciliation['reconciled'] ? 'الأرصدة مطابقة.' : 'توجد فروق تحتاج مراجعة.' }}</p>
                @if($reconciliation['discrepancies'])
                <x-ui.responsive-table label="فروق المطابقة"><table role="table" class="w-full text-start text-sm"><thead role="rowgroup"><tr role="row"><th role="columnheader">الصنف</th><th role="columnheader">الرصيد</th><th role="columnheader">دفتر الحركات</th><th role="columnheader">المحجوز</th><th role="columnheader">الحجز المتبقي</th><th role="columnheader">غير المتاح</th><th role="columnheader">المعزول</th></tr></thead><tbody role="rowgroup">
                    @foreach($reconciliation['discrepancies'] as $d)
                        @php($rp = $reconciliationProducts[$d['product_id']] ?? null)
                        <tr role="row"><td role="cell" data-label="الصنف"><div><strong>{{ $rp?->product_name ?? 'صنف #'.$d['product_id'] }}</strong>@if($rp?->sku)<small class="block text-slate-500"><bdi>{{ $rp->sku }}</bdi></small>@endif</div></td><td role="cell" data-label="الرصيد"><div>{{ D::qty($d['on_hand']) }}</div></td><td role="cell" data-label="دفتر الحركات"><div>{{ D::qty($d['ledger_quantity']) }}</div></td><td role="cell" data-label="المحجوز"><div>{{ D::qty($d['reserved']) }}</div></td><td role="cell" data-label="الحجز المتبقي"><div>{{ D::qty($d['outstanding']) }}</div></td><td role="cell" data-label="غير المتاح"><div>{{ D::qty($d['unavailable']) }}</div></td><td role="cell" data-label="المعزول"><div>{{ D::qty($d['isolated']) }}</div></td></tr>
                    @endforeach
                </tbody></table></x-ui.responsive-table>
                @endif
            @endif
        </div>
    </details>
    @endif

    <flux:modal wire:model="editingMinimum"><form wire:submit="saveMinimum" class="space-y-4">
        <flux:heading size="lg">حد تنبيه المخزون</flux:heading>
        @if($editingBalance)
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
            <p class="font-semibold text-slate-900">{{ $editingBalance->product_name }} <small class="font-normal text-slate-500">(<bdi>{{ $editingBalance->sku }}</bdi>)</small></p>
            <p class="mt-1 text-xs text-slate-600">{{ $wName($editingBalance->warehouse_id) }} · الفعلي: <b class="tabular-nums">{{ D::qty($editingBalance->on_hand) }}</b> · المتاح: <b class="tabular-nums">{{ D::qty($editingBalance->available) }}</b> {{ $editingBalance->unit_name }}</p>
        </div>
        @endif
        <flux:input wire:model="minimum" label="الحد الأدنى بالوحدة الأساسية" inputmode="decimal" /><flux:text>اتركه فارغًا لإزالة الحد. لا توجد حدود افتراضية أو طلب شراء تلقائي.</flux:text><flux:error name="inventory" /><flux:button type="submit" variant="primary">حفظ الحد</flux:button>
    </form></flux:modal>
</div>
