<div class="quotation-page space-y-4">
 <x-page.heading title="عروض الأسعار" description="إدارة عروض الأسعار للعملاء ومتابعة الموافقات والإصدارات والتحويل إلى أوامر بيع" :breadcrumbs="['المبيعات','عروض الأسعار']">
  <x-slot:actions>
   @if($can('import')&&$can('create'))<flux:button :href="route('quotations.create',['import'=>1])" wire:navigate icon="arrow-up-tray">استيراد Excel</flux:button>@endif
   @if($can('export'))<flux:button wire:click="export" icon="arrow-down-tray">تصدير Excel</flux:button>@endif
   @if($can('create'))<flux:button variant="primary" :href="route('quotations.create')" wire:navigate icon="plus">إنشاء عرض جديد</flux:button>@endif
  </x-slot:actions>
 </x-page.heading>
 @include('sales.quotations.partials.feedback')
 <div class="quote-stats grid grid-cols-2 gap-3 xl:grid-cols-5">
  <x-erp.stat-card label="إجمالي عروض الأسعار" :value="$stats['total']" icon="calendar-days" />
  <x-erp.stat-card label="عروض بانتظار الموافقة" :value="$stats['pending_approval']" icon="clock" tone="orange" />
  <x-erp.stat-card label="عروض مقبولة" :value="$stats['accepted']" icon="check-circle" tone="emerald" />
  <x-erp.stat-card label="عروض مرفوضة" :value="$stats['rejected']" icon="x-circle" />
  <x-erp.stat-card label="عروض محولة إلى أوامر بيع" :value="$stats['converted'] ?? 'غير متاح'" icon="shopping-cart" />
 </div>
 <x-ui.panel class="!p-3">
  <div class="quote-filters">
   <flux:input wire:model.live.debounce.350ms="search" label="البحث" icon="magnifying-glass" placeholder="رقم العرض أو اسم العميل أو المشروع…" />
   <flux:select wire:model.live="customer" label="العميل"><option value="">الكل</option>@foreach($customerOptions as $c)<option value="{{ $c->customer_id }}">{{ $c->customer_snapshot['name'] }}</option>@endforeach</flux:select>
   <flux:input wire:model.live.debounce.350ms="project" label="المشروع / المرجع" placeholder="المشروع" />
   <flux:select wire:model.live="status" label="حالة العرض"><option value="">الكل</option>@foreach(['draft'=>'مسودة','pending_approval'=>'بانتظار الموافقة','issued'=>'صادر','sent'=>'مرسل','accepted'=>'مقبول','rejected'=>'مرفوض','expired'=>'منتهي الصلاحية','cancelled'=>'ملغي'] as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</flux:select>
   <flux:select wire:model.live="conversion" label="حالة التحويل"><option value="">الكل</option><option value="not_converted">غير محول</option><option value="partial">محول جزئيًا</option><option value="full">محول بالكامل</option></flux:select>
   <flux:input wire:model.live="from" type="date" label="من تاريخ" />
   <flux:input wire:model.live="to" type="date" label="إلى تاريخ" />
   <flux:button wire:click="clearFilters" icon="arrow-path" class="self-end">إعادة تعيين</flux:button>
  </div>
 </x-ui.panel>
 <x-ui.panel class="!p-0 overflow-hidden">
  <div class="flex items-center justify-between gap-4 p-3 text-sm"><span>{{ $records->total() }} نتيجة</span><div class="flex items-center gap-2"><span>عرض</span><flux:select wire:model.live="perPage" aria-label="عدد النتائج"><option>10</option><option>25</option><option>50</option></flux:select></div></div>
  <div class="overflow-x-auto" wire:loading.class="opacity-50">
   <table class="quote-table min-w-[1180px] w-full"><thead><tr><th>#</th><th><button wire:click="sortBy('number')">رقم العرض ↕</button></th><th>العميل</th><th>المشروع / المرجع</th><th><button wire:click="sortBy('quotation_date')">تاريخ العرض ↕</button></th><th>ساري حتى</th><th>حالة العرض</th><th>حالة التحويل</th><th>الصافي قبل الضريبة (ج.م)</th><th>الضريبة</th><th>الإجمالي (ج.م)</th><th>مسؤول المبيعات</th><th>الإجراءات</th></tr></thead>
   <tbody>@forelse($records as $r)<tr wire:key="quotation-{{ $r->id }}"><td>{{ $records->firstItem()+$loop->index }}</td><td><a class="quote-link font-semibold" href="{{ route('quotations.show',$r->id) }}" wire:navigate><bdi>{{ $r->label() }}</bdi></a></td><td>{{ $r->customer_snapshot['name'] }}</td><td>{{ $r->project_name ?: $r->rfq_reference ?: '—' }}</td><td><bdi>{{ $r->quotation_date->format('Y/m/d') }}</bdi></td><td><bdi>{{ $r->valid_until?->format('Y/m/d') ?? 'غير محدد' }}</bdi></td><td><x-sales.quotation-status :status="$r->status" /></td><td><span class="quote-status">غير محول</span></td><td><bdi>{{ \App\Modules\Sales\Application\DraftAmounts::display($r->calculation['net']??null) }}</bdi></td><td class="text-slate-400">غير محسوبة</td><td class="text-slate-400">غير محسوب</td><td>{{ $r->created_by ? 'مستخدم #'.$r->created_by : 'بيانات تجريبية' }}</td><td><div class="flex items-center gap-1"><flux:button size="xs" variant="ghost" icon="eye" :href="route('quotations.show',$r->id)" aria-label="عرض التفاصيل" wire:navigate />@if($r->editable()&&$can('edit'))<flux:button size="xs" variant="ghost" icon="pencil-square" :href="route('quotations.edit',$r->id)" aria-label="تعديل المسودة" wire:navigate />@endif</div></td></tr>
   @empty<tr><td colspan="13"><x-ui.empty-state title="لا توجد عروض أسعار" description="أنشئ عرض سعر جديدًا واختر العميل والأصناف من الدليل، ثم احفظ المسودة يدويًا." icon="document-text">@if($can('create'))<flux:button variant="primary" :href="route('quotations.create')" wire:navigate>إنشاء عرض سعر</flux:button>@endif</x-ui.empty-state></td></tr>@endforelse</tbody></table>
  </div><div class="p-4">{{ $records->links() }}</div>
 </x-ui.panel>
</div>
