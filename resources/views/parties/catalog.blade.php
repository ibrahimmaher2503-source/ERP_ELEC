<div data-erp-archetype="list" class="party-ui space-y-5 {{ $ui->supplier ? 'supplier-ui supplier-catalog' : '' }}">
    <x-page.heading :title="$ui->plural" :description="$ui->supplier ? 'إدارة قاعدة بيانات الموردين وشركات التوريد' : 'إدارة قاعدة بيانات العملاء وجهات الاتصال'" :breadcrumbs="['الأطراف',$ui->plural]">
        <x-slot:actions>
            @if($canCreate)<flux:button variant="primary" icon="plus" :href="route($ui->route('create'))" wire:navigate>{{ $ui->supplier ? 'إضافة مورد جديد' : 'إضافة عميل' }}</flux:button>@endif
        </x-slot:actions>
    </x-page.heading>
    @if(session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif
    <x-ui.validation-summary />
    <div @class(['erp-kpi-summary party-stats grid grid-cols-2 gap-3', 'xl:grid-cols-5'=>$ui->supplier, 'xl:grid-cols-4'=>!$ui->supplier])>
        <x-erp.stat-card :label="'إجمالي '.$ui->plural" :value="$stats['total']" :icon="$ui->supplier ? 'cube' : 'user-group'" />
        <x-erp.stat-card :label="$ui->supplier ? 'الموردون النشطون' : 'العملاء النشطون'" :value="$stats['active']" icon="check-circle" tone="emerald" />
        <x-erp.stat-card :label="$ui->supplier ? 'موردون غير نشطين' : 'العملاء غير النشطين'" :value="$stats['inactive']" icon="pause-circle" tone="orange" />
        @if($ui->supplier)<x-erp.stat-card label="موردون مؤرشفون" :value="$stats['archived']" icon="archive-box" />@endif
        <x-erp.stat-card :label="$ui->supplier ? 'موردون جدد هذا الشهر' : 'العملاء الجدد هذا الشهر'" :value="$stats['new']" icon="user-plus" />
    </div>
    <div class="party-catalog-grid">
        <x-ui.panel class="!p-3 min-w-0">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-56 flex-1"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="$ui->supplier ? 'ابحث عن مورد بالاسم أو الكود أو السجل التجاري…' : 'البحث بالاسم أو الكود أو بيانات التواصل…'" :label="'البحث في '.$ui->plural" /></div>
                <div class="flex flex-wrap gap-2">
                    @if($canBulk)<flux:button wire:click="openBulk" icon="cog-6-tooth" :disabled="count($selected)===0">عمليات جماعية</flux:button>@endif
                    <div class="erp-toolbar-secondary">
                        @if($canImport)<flux:button :href="route($ui->route('import'))" wire:navigate icon="arrow-up-tray">استيراد Excel</flux:button>@endif
                        @if($canExport)<flux:dropdown><flux:button icon="arrow-down-tray" icon-trailing="chevron-down">تصدير Excel</flux:button><flux:menu><flux:menu.item wire:click="startExport('selected')" :disabled="count($selected) === 0">السجلات المحددة</flux:menu.item><flux:menu.item wire:click="startExport('filtered')">نتائج التصفية</flux:menu.item><flux:menu.item wire:click="startExport('all')">جميع السجلات المصرح بها</flux:menu.item></flux:menu></flux:dropdown>@endif
                    </div>
                    @if($canImport || $canExport)
                        <div class="erp-toolbar-overflow"><flux:dropdown><flux:button icon="ellipsis-horizontal">المزيد</flux:button><flux:menu>
                            @if($canImport)<flux:menu.item :href="route($ui->route('import'))" wire:navigate icon="arrow-up-tray">استيراد Excel</flux:menu.item>@endif
                            @if($canExport)
                                <flux:menu.item wire:click="startExport('selected')" :disabled="count($selected) === 0">تصدير السجلات المحددة</flux:menu.item>
                                <flux:menu.item wire:click="startExport('filtered')">تصدير نتائج التصفية</flux:menu.item>
                                <flux:menu.item wire:click="startExport('all')">تصدير جميع السجلات المصرح بها</flux:menu.item>
                            @endif
                        </flux:menu></flux:dropdown></div>
                    @endif
                </div>
            </div>
            @if($ui->supplier)
            <x-ui.filters id="supplier-filters" target="search,country,city,category_id,status,supplier_type,clearFilters" :reset="['search' => '', 'status' => '', 'entity_type' => '', 'segment' => '', 'city' => '', 'country' => '', 'supplier_type' => '', 'category_id' => '']" clear="clearFilters"><div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <flux:select wire:model.live="country" label="الدولة"><option value="">جميع الدول</option>@foreach($countries as $value)<option>{{ $value }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="city" label="المدينة"><option value="">جميع المدن</option>@foreach($cities as $value)<option>{{ $value }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="category_id" label="التصنيف"><option value="">جميع التصنيفات</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="status" label="الحالة"><option value="">جميع الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option><option value="archived">مؤرشف</option></flux:select>
                <flux:select wire:model.live="supplier_type" label="نوع المورد"><option value="">جميع أنواع الموردين</option>@foreach($ui->typeLabels() as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</flux:select>
            </div></x-ui.filters>
            @endif
            @if(count($selected))
            <div class="party-selection mb-3 flex flex-wrap items-center gap-3 rounded-md border p-3 text-sm"><span>تم تحديد <b>{{ count($selected) }}</b> من {{ $records->total() }} سجل</span>@if($canBulk)<flux:button size="sm" wire:click="openBulk" icon="pencil-square">تعديل جماعي</flux:button>@endif<flux:button size="sm" variant="ghost" wire:click="clearSelection">إلغاء التحديد</flux:button><span class="text-xs opacity-70">يبقى التحديد عبر الصفحات، ويطبق الإجراء على المحددين فقط.</span></div>
            @endif
            <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,status,entity_type,segment,category_id,supplier_type,city,country,sortBy">
                <div class="erp-mobile-table-tools"><flux:button size="sm" wire:click="selectVisible" aria-label="تبديل تحديد سجلات الصفحة الحالية">تحديد / إلغاء</flux:button><flux:button size="sm" wire:click="sortBy('name')" aria-label="ترتيب بالاسم">الاسم ↕</flux:button><flux:button size="sm" wire:click="sortBy('code')" aria-label="ترتيب بالكود">الكود ↕</flux:button></div><x-ui.responsive-table class="erp-catalog-records" :label="$ui->plural"><table role="table" class="party-table w-full min-w-[850px] text-sm">
                    <thead role="rowgroup"><tr role="row"><th role="columnheader"><span class="erp-mobile-column-label">التحديد</span><button type="button" wire:click="selectVisible" aria-label="تبديل تحديد الصفحة الحالية" class="size-5 rounded border border-blue-200">✓</button></th>@if($ui->supplier)<th role="columnheader">#</th>@endif<th role="columnheader">الشعار</th><th role="columnheader"><span class="erp-mobile-column-label">{{ 'اسم '.$ui->singular }}</span><button wire:click="sortBy('name')">{{ 'اسم '.$ui->singular }} ↕</button></th><th role="columnheader"><span class="erp-mobile-column-label">{{ 'كود '.$ui->singular }}</span><button wire:click="sortBy('code')">{{ 'كود '.$ui->singular }} ↕</button></th>@if($ui->supplier)<th role="columnheader">التصنيف الرئيسي</th><th role="columnheader">الدولة</th>@endif<th role="columnheader">المدينة</th><th role="columnheader">{{ $ui->supplier ? 'نوع المورد' : 'نوع العميل' }}</th><th role="columnheader">{{ $ui->supplier ? 'جهة الاتصال الرئيسية' : 'شريحة العميل' }}</th><th role="columnheader">الحالة</th><th role="columnheader">الإجراءات</th></tr></thead>
                    <tbody role="rowgroup">
                        @forelse($records as $record)
                        <tr role="row" wire:key="{{ $ui->entity }}-{{ $record->id }}">
                            <td role="cell" data-label="التحديد"><div><flux:checkbox wire:model.live="selected" value="{{ $record->id }}" aria-label="تحديد {{ $record->name }}" /></div></td>
                            @if($ui->supplier)<td role="cell" data-label="الصف"><div>{{ $records->firstItem()+$loop->index }}</div></td>@endif
                            <td role="cell" data-label="الشعار"><div><span class="party-monogram"><flux:icon :name="$ui->supplier ? 'cube' : ($record->entity_type === 'organization' ? 'building-office-2' : 'user')" class="size-6" /></span></div></td>
                            <td role="cell" data-label="الاسم"><div><a href="{{ route($ui->route('show'),$record->id) }}" wire:navigate class="font-semibold hover:underline">{{ $record->name }}</a>@if($record->trading_name)<small class="block opacity-65">{{ $record->trading_name }}</small>@endif</div></td>
                            <td role="cell" data-label="الكود"><div><bdi>{{ $record->code }}</bdi></div></td>
                            @if($ui->supplier)<td role="cell" data-label="التصنيف الرئيسي"><div>{{ $record->category?->name ?: '—' }}</div></td><td role="cell" data-label="الدولة"><div>{{ $record->country ?: '—' }}</div></td>@endif
                            <td role="cell" data-label="المدينة"><div>{{ $record->city ?: '—' }}</div></td><td role="cell" data-label="النوع"><div>{{ $ui->supplier ? $ui->typeLabels()[$record->supplier_type] : ($record->entity_type === 'organization' ? 'شركة / مؤسسة' : 'فرد') }}</div></td>
                            <td role="cell" data-label="جهة الاتصال / الشريحة"><div>@if($ui->supplier) @php($primary=$record->contacts->first())<span>{{ $primary?->name ?: '—' }}</span><small class="block"><bdi>{{ $primary?->mobile ?: $primary?->phone }}</bdi></small>@else{{ $record->segment ?: '—' }}@endif</div></td>
                            <td role="cell" data-label="الحالة"><div>@include('parties.partials.status',['status'=>$record->status])</div></td>
                            <td role="cell" data-label="الإجراءات"><div><div class="flex gap-1">@if($canUpdate && $ui->supplier)<flux:button size="sm" variant="ghost" icon="pencil-square" :href="route($ui->route('edit'),$record->id)" wire:navigate aria-label="تعديل {{ $record->name }}" />@endif<flux:dropdown><flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="إجراءات {{ $record->name }}" /><flux:menu><flux:menu.item :href="route($ui->route('show'),$record->id)" wire:navigate icon="eye">عرض التفاصيل</flux:menu.item>@if($canUpdate)<flux:menu.item :href="route($ui->route('edit'),$record->id)" wire:navigate icon="pencil-square">تعديل</flux:menu.item>@endif @if($canStatus)<flux:menu.item wire:click="changeStatus({{ $record->id }},{{ $record->version }},'{{ $record->status === 'active' ? 'inactive' : 'active' }}')" wire:confirm="تأكيد تغيير الحالة؟">{{ $record->status === 'active' ? 'إيقاف' : 'تفعيل' }}</flux:menu.item><flux:menu.item wire:click="changeStatus({{ $record->id }},{{ $record->version }},'archived')" wire:confirm="أرشفة السجل مع الاحتفاظ بتاريخه؟">أرشفة</flux:menu.item>@endif</flux:menu></flux:dropdown></div></div></td>
                        </tr>
                        @empty<tr role="row"><td colspan="12"><x-ui.empty-state :title="'لا يوجد سجلات في '.$ui->plural" description="أضف أول سجل أو غيّر معايير البحث والتصفية." :icon="$ui->supplier ? 'truck' : 'user-group'" /></td></tr>@endforelse
                    </tbody>
                </table></x-ui.responsive-table>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3"><span class="text-xs">{{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }} من {{ $records->total() }} نتيجة</span><flux:select wire:model.live="perPage" label="عدد الصفوف" class="!w-24"><option value="5">5</option><option value="10">10</option><option value="25">25</option><option value="50">50</option></flux:select><div class="flex-1">{{ $records->links() }}</div></div>
        </x-ui.panel>
        <aside class="party-catalog-aside space-y-4">
            @if($ui->supplier)
                <x-ui.panel class="!p-3 supplier-category-panel"><div class="flex items-center justify-between border-b border-slate-100 pb-4"><h2 class="party-section-title !border-0 !pb-0"><flux:icon.squares-2x2 class="size-5" />تصنيفات الموردين</h2>@if($canCategories)<flux:button size="sm" variant="ghost" icon="cog-6-tooth" :href="route('suppliers.categories')" wire:navigate aria-label="إدارة التصنيفات" />@endif</div><button type="button" wire:click="$set('category_id','')" @class(['supplier-category-row','is-current'=>$category_id===''])><flux:icon.cube class="size-5" /><span class="flex-1 text-start">جميع الموردين</span><span class="supplier-count">{{ $stats['total'] }}</span></button>@foreach($categories as $category)<button type="button" wire:click="$set('category_id','{{ $category->id }}')" @class(['supplier-category-row','is-current'=>(string)$category_id===(string)$category->id])><flux:icon.cube class="size-5" /><span class="flex-1 text-start">{{ $category->name }}</span><span class="supplier-count">{{ $category->suppliers_count }}</span></button>@endforeach</x-ui.panel>
            @else
                <x-ui.panel class="!p-4"><h2 class="party-section-title hidden sm:flex"><flux:icon.funnel class="size-5" /> تصفية العملاء</h2><x-ui.filters id="customer-filters" target="search,status,city,entity_type,segment,country,clearFilters" :reset="['search' => '', 'status' => '', 'entity_type' => '', 'segment' => '', 'city' => '', 'country' => '', 'supplier_type' => '', 'category_id' => '']" clear="clearFilters"><div class="mt-4 space-y-4"><flux:select wire:model.live="status" label="الحالة"><option value="">جميع الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option><option value="archived">مؤرشف</option></flux:select><flux:select wire:model.live="city" label="المدينة"><option value="">جميع المدن</option>@foreach($cities as $value)<option>{{ $value }}</option>@endforeach</flux:select><flux:select wire:model.live="entity_type" label="نوع العميل"><option value="">جميع الأنواع</option><option value="organization">شركة / مؤسسة</option><option value="individual">فرد</option></flux:select><flux:select wire:model.live="segment" label="شريحة العميل"><option value="">جميع الشرائح</option>@foreach($segments as $value)<option>{{ $value }}</option>@endforeach</flux:select><flux:select wire:model.live="country" label="الدولة"><option value="">جميع الدول</option>@foreach($countries as $value)<option>{{ $value }}</option>@endforeach</flux:select></div></x-ui.filters></x-ui.panel>
            @endif
        </aside>
    </div>
    <flux:modal wire:model.self="bulkOpen" class="md:w-[540px]">
        <form wire:submit="previewBulk" class="party-ui space-y-5"><div><flux:heading size="lg">{{ 'تعديل جماعي: '.$ui->plural }}</flux:heading><flux:text>تطبيق التغييرات على {{ count($selected) }} سجل محدد فقط.</flux:text></div>
            @foreach($bulkFields as $field=>$definition)<div class="grid grid-cols-2 items-end gap-3"><flux:select wire:model.live="changes.{{ $field }}.mode" :label="$definition['label']"><option value="keep">إبقاء القيمة</option><option value="set">تعيين قيمة</option>@if($definition['clear'])<option value="clear">مسح القيمة</option>@endif</flux:select>
            @if(($changes[$field]['mode'] ?? 'keep')==='set')
                @if($field==='status')<flux:select wire:model="changes.status.value" label="القيمة الجديدة"><option value="">اختر الحالة</option><option value="active">نشط</option><option value="inactive">غير نشط</option><option value="archived">مؤرشف</option></flux:select>
                @elseif($field==='category_id')<flux:select wire:model="changes.category_id.value" label="التصنيف الجديد"><option value="">اختر التصنيف</option>@foreach($categories->where('active',true) as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</flux:select>
                @elseif($field==='supplier_type')<flux:select wire:model="changes.supplier_type.value" label="نوع المورد"><option value="">اختر النوع</option>@foreach($ui->typeLabels() as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</flux:select>
                @else<flux:input wire:model="changes.{{ $field }}.value" label="القيمة الجديدة" />@endif
            @endif</div>@endforeach
            <flux:callout color="blue">راجع القيم الحالية والجديدة في المعاينة قبل تأكيد التنفيذ.</flux:callout><div class="flex gap-2"><flux:button type="submit" variant="primary" icon="eye">معاينة التغييرات</flux:button><flux:button wire:click="$set('bulkOpen',false)">إلغاء</flux:button></div>
        </form>
    </flux:modal>
</div>
