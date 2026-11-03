<div data-erp-archetype="list" class="quotation-page space-y-4">
 <x-page.heading title="عروض الأسعار" description="إدارة عروض الأسعار للعملاء ومتابعة الموافقات والإصدارات والتحويل إلى أوامر بيع" :breadcrumbs="['المبيعات','عروض الأسعار']">
  <x-slot:actions>@if($can('create'))<flux:button variant="primary" :href="route('quotations.create')" wire:navigate icon="plus">إنشاء عرض جديد</flux:button>@endif</x-slot:actions><x-slot:secondaryActions>@if($can('import')&&$can('create'))<flux:button :href="route('quotations.create',['import'=>1])" wire:navigate icon="arrow-up-tray">استيراد Excel</flux:button>@endif@if($can('export'))<flux:button wire:click="export" :disabled="! $filtersValid" icon="arrow-down-tray">تصدير Excel</flux:button>@endif</x-slot:secondaryActions>
 </x-page.heading>
 @include('sales.quotations.partials.feedback')
 <div class="erp-kpi-summary quote-stats grid grid-cols-2 gap-3 xl:grid-cols-5">
  <x-erp.stat-card label="إجمالي عروض الأسعار" :value="$stats['total']" icon="calendar-days" />
  <x-erp.stat-card label="عروض بانتظار الموافقة" :value="$stats['pending_approval']" icon="clock" tone="orange" />
  <x-erp.stat-card label="عروض مقبولة" :value="$stats['accepted']" icon="check-circle" tone="emerald" />
  <x-erp.stat-card label="عروض مرفوضة" :value="$stats['rejected']" icon="x-circle" />
  <x-erp.stat-card label="عروض محولة إلى أوامر بيع" :value="$stats['converted'] ?? 'غير متاح'" icon="shopping-cart" />
 </div>
 <x-ui.panel class="!p-3">
  <x-ui.filters id="quotation-filters" target="search,customer,project,status,conversion,from,to,clearFilters" :reset="['search' => '', 'customer' => '', 'project' => '', 'status' => '', 'conversion' => '', 'representative' => '', 'from' => '', 'to' => '']" clear="clearFilters"><x-slot:search><flux:input wire:model.live.debounce.350ms="search" label="البحث" icon="magnifying-glass" placeholder="رقم العرض أو اسم العميل أو المشروع…" /></x-slot:search><div class="quote-filters">

   <flux:select wire:model.live="customer" label="العميل"><option value="">الكل</option>@foreach($customerOptions as $c)<option value="{{ $c->customer_id }}">{{ $c->customer_snapshot['name'] }}</option>@endforeach</flux:select>
   <flux:input wire:model.live.debounce.350ms="project" label="المشروع / المرجع" placeholder="المشروع" />
   <flux:select  wire:model.live="status" label="حالة العرض"><option value="">الكل</option>@foreach(['draft'=>'مسودة','pending_approval'=>'بانتظار الموافقة','issued'=>'صادر','sent'=>'مرسل','accepted'=>'مقبول','rejected'=>'مرفوض','expired'=>'منتهي الصلاحية','cancelled'=>'ملغي'] as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</flux:select>
   <flux:select wire:model.live="conversion" label="حالة التحويل"><option value="">الكل</option><option value="not_converted">غير محول</option><option value="partial">محول جزئيًا</option><option value="full">محول بالكامل</option></flux:select>
   <flux:input wire:model.live="from" type="date" label="من تاريخ" />
   <flux:input wire:model.live="to" type="date" label="إلى تاريخ" />

  </div></x-ui.filters>
 </x-ui.panel>
    @if($filtersValid)
 <x-ui.panel class="!p-0 overflow-hidden">
  <div class="flex items-center justify-between gap-4 p-3 text-sm"><span>{{ $records->total() }} نتيجة</span><div class="flex items-center gap-2"><span>عرض</span><flux:select wire:model.live="perPage" aria-label="عدد النتائج"><option>10</option><option>25</option><option>50</option></flux:select></div></div>
  <div class="overflow-x-auto" wire:loading.class="opacity-50">
   <div class="erp-mobile-table-tools"><button wire:click="sortBy('number')">رقم العرض ↕</button><button wire:click="sortBy('quotation_date')">تاريخ العرض ↕</button></div><x-ui.responsive-table label="السجلات المعروضة"><table role="table" class="quote-table min-w-[1180px] w-full"><thead role="rowgroup"><tr role="row"><th role="columnheader">#</th><th role="columnheader"><span class="erp-mobile-column-label">رقم العرض ↕</span><button wire:click="sortBy('number')">رقم العرض ↕</button></th><th role="columnheader">العميل</th><th role="columnheader">المشروع / المرجع</th><th role="columnheader"><span class="erp-mobile-column-label">تاريخ العرض ↕</span><button wire:click="sortBy('quotation_date')">تاريخ العرض ↕</button></th><th role="columnheader">ساري حتى</th><th role="columnheader">حالة العرض</th><th role="columnheader">حالة التحويل</th><th role="columnheader">الصافي قبل الضريبة (ج.م)</th><th role="columnheader">الضريبة</th><th role="columnheader">الإجمالي (ج.م)</th><th role="columnheader">مسؤول المبيعات</th><th role="columnheader">الإجراءات</th></tr></thead>
   <tbody role="rowgroup">@forelse($records as $r)<tr role="row" wire:key="quotation-{{ $r->id }}"><td role="cell" data-label="الصف"><div>{{ $records->firstItem()+$loop->index }}</div></td><td role="cell" data-label="رقم العرض"><div><a class="quote-link font-semibold" href="{{ route('quotations.show',$r->id) }}" wire:navigate><bdi>{{ $r->label() }}</bdi></a></div></td><td role="cell" data-label="العميل"><div>{{ $r->customer_snapshot['name'] }}</div></td><td role="cell" data-label="المشروع / المرجع"><div>{{ $r->project_name ?: $r->rfq_reference ?: '—' }}</div></td><td role="cell" data-label="تاريخ العرض"><div><bdi>{{ $r->quotation_date->format('Y/m/d') }}</bdi></div></td><td role="cell" data-label="ساري حتى"><div><bdi>{{ $r->valid_until?->format('Y/m/d') ?? 'غير محدد' }}</bdi></div></td><td role="cell" data-label="حالة العرض"><div><x-sales.quotation-status :status="$r->status" /></div></td><td role="cell" data-label="حالة التحويل"><div><span class="quote-status">غير محول</span></div></td><td role="cell" data-label="الصافي قبل الضريبة (ج.م)"><div><bdi>{{ \App\Modules\Sales\Application\DraftAmounts::display($r->calculation['net']??null) }}</bdi></div></td><td role="cell" data-label="الضريبة" class="text-slate-400"><div>غير محسوبة</div></td><td role="cell" data-label="الإجمالي (ج.م)" class="text-slate-400"><div>غير محسوب</div></td><td role="cell" data-label="مسؤول المبيعات"><div>{{ $r->created_by ? 'مستخدم #'.$r->created_by : 'بيانات تجريبية' }}</div></td><td role="cell" data-label="الإجراءات"><div><div class="flex items-center gap-1"><flux:button size="xs" variant="ghost" icon="eye" :href="route('quotations.show',$r->id)" aria-label="عرض التفاصيل" wire:navigate />@if($r->editable()&&$can('edit'))<flux:button size="xs" variant="ghost" icon="pencil-square" :href="route('quotations.edit',$r->id)" aria-label="تعديل المسودة" wire:navigate />@endif</div></div></td></tr>
   @empty<tr role="row"><td colspan="13"><x-ui.empty-state title="لا توجد عروض أسعار" description="أنشئ عرض سعر جديدًا واختر العميل والأصناف من الدليل، ثم احفظ المسودة يدويًا." icon="document-text">@if($can('create'))<flux:button variant="primary" :href="route('quotations.create')" wire:navigate>إنشاء عرض سعر</flux:button>@endif</x-ui.empty-state></td></tr>@endforelse</tbody></table></x-ui.responsive-table>
  </div><div class="p-4">{{ $records->links() }}</div>
 </x-ui.panel>
    @endif
</div>
