<div data-erp-archetype="{{ request()->routeIs('*.dashboard') ? 'dashboard' : (request()->routeIs('*.reports', '*.aging', '*.statements') ? 'report' : (request()->routeIs('*.create', '*.edit') ? 'form' : 'list')) }}" class="financial-workspace space-y-5">
    <x-page.heading :title="$nav[$screen][0]" description="تشغيل الذمم من المستندات المصدرية المرحّلة فقط" :breadcrumbs="['الذمم المدينة والدائنة', $nav[$screen][0]]" />
    @if($access->branches(auth()->id(),'ar_view')!==[] || $access->branches(auth()->id(),'ap_view')!==[])<flux:button :href="route('financial.party-credits')" wire:navigate>رد الأرصدة الدائنة</flux:button>@endif
    <nav class="erp-module-nav flex flex-wrap gap-2" aria-label="صفحات الذمم والفواتير" x-data x-init="$nextTick(() => $el.querySelector('[aria-current]')?.scrollIntoView({ block: 'nearest', inline: 'center' }))">@foreach($nav as $destination=>$item)<flux:button :href="route('financial.'.$destination)" :variant="$screen===$destination?'primary':'outline'" :aria-current="$screen===$destination?'page':null" size="sm" wire:navigate>{{ $item[0] }}</flux:button>@endforeach</nav>
    @if(session('financial_message'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('financial_message') }}</flux:callout>@endif
    <x-ui.validation-summary />
    <x-ui.panel>
        <x-ui.filters id="financial-filters" target="search,branch" :reset="['search' => '', 'branch' => '']"><x-slot:search><flux:input wire:model.live.debounce.300ms="search" label="بحث" placeholder="اسم الطرف أو رقم المستند"/></x-slot:search>
        <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_15rem]">

            <flux:select wire:model.live="branch" label="الفرع"><option value="">كل الفروع المصرح بها</option>@foreach($branches as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }}</option>@endforeach</flux:select>
        </div>
        </x-ui.filters>
    </x-ui.panel>
    @if(in_array($screen,['ap','ar','aging'],true))
        <div class="erp-kpi-summary grid grid-cols-2 gap-3"><x-erp.stat-card label="القيمة الأصلية" :value="$totals['original'].' ج.م'" icon="document-text"/><x-erp.stat-card label="الرصيد المتبقي" :value="$totals['remaining'].' ج.م'" icon="calculator"/></div>
    @endif
    @if($screen==='supplier-invoices' && $access->branches(auth()->id(),'supplier_invoice_manage')!==[])
        <x-ui.panel><h2 class="mb-3 font-semibold">فاتورة مورد من استلام مرحّل</h2><p class="mb-4 text-sm text-slate-600">أدخل معرفات المصدر الظاهرة في أمر الشراء والاستلام. المطابقة والكمية والسعر والضريبة يعاد التحقق منها داخل M05 وM11.</p>
            <form wire:submit="createSupplier" class="grid gap-3 md:grid-cols-3 xl:grid-cols-4">
                <flux:input wire:model="form.supplier_id" type="number" min="1" label="معرف المورد" required/><flux:input wire:model="form.supplier_invoice_number" label="رقم فاتورة المورد" required/>
                <flux:input wire:model="form.invoice_date" type="date" label="تاريخ الفاتورة" required/><flux:input wire:model="form.due_date" type="date" label="تاريخ الاستحقاق" required/>
                <flux:select wire:model="form.tax_mode" label="معالجة السعر"><option value="exclusive">غير شامل الضريبة</option><option value="inclusive">شامل الضريبة</option></flux:select>
                <flux:input wire:model="form.order_line_id" type="number" min="1" label="بند أمر الشراء" required/><flux:input wire:model="form.receipt_line_id" type="number" min="1" label="بند الاستلام المرحّل" required/>
                <flux:input wire:model="form.quantity" inputmode="decimal" label="الكمية المطابقة" required/><flux:input wire:model="form.unit_price" inputmode="decimal" label="سعر الفاتورة" required/><flux:input wire:model="form.discount_amount" inputmode="decimal" label="خصم البند" required/>
                <div class="md:col-span-3 xl:col-span-4"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">إنشاء المسودة والمطابقة المصدرية</flux:button></div>
            </form>
        </x-ui.panel>
    @elseif($screen==='customer-invoices' && $access->branches(auth()->id(),'customer_invoice_manage')!==[])
        <x-ui.panel><h2 class="mb-3 font-semibold">فاتورة عميل من تسليم مؤكد</h2><form wire:submit="createCustomer" class="grid gap-3 md:grid-cols-3">
            <flux:input wire:model="form.customer_id" type="number" min="1" label="معرف العميل" required/><flux:input wire:model="form.delivery_line_id" type="number" min="1" label="بند التسليم المؤكد" required/><flux:input wire:model="form.quantity" inputmode="decimal" label="الكمية المفوترة" required/>
            <flux:input wire:model="form.invoice_date" type="date" label="تاريخ الفاتورة" required/><flux:input wire:model="form.due_date" type="date" label="تاريخ الاستحقاق" required/><div class="erp-form-actions"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">إنشاء مسودة الفاتورة</flux:button></div>
        </form></x-ui.panel>
    @elseif($screen==='credit-notes' && $access->branches(auth()->id(),'credit_note_manage')!==[])
        <x-ui.panel><h2 class="mb-3 font-semibold">إشعار مرتبط بفاتورة مرحّلة</h2><form wire:submit="createCredit" class="grid gap-3 md:grid-cols-3">
            <flux:select wire:model="form.kind" label="النوع"><option value="supplier">مورد</option><option value="customer">عميل</option></flux:select><flux:input wire:model="form.invoice_id" type="number" min="1" label="معرف الفاتورة الأصلية" required/>
            <flux:input wire:model="form.note_date" type="date" label="تاريخ الإشعار" required/><flux:input wire:model="form.amount" inputmode="decimal" label="القيمة ج.م" required/><flux:input wire:model="form.reason" label="سبب التصحيح" required/>
            <div class="erp-form-actions"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">إنشاء مسودة الإشعار</flux:button></div>
        </form></x-ui.panel>
    @endif
    <x-ui.panel>
        <div @class(['overflow-x-auto', 'financial-open-items-table'=>in_array($screen,['ap','ar','aging'],true)])><table>
            @if($screen==='supplier-invoices')
                <thead><tr><th>رقم المورد</th><th>المورد</th><th>التاريخ</th><th>الاستحقاق</th><th>الإجمالي</th><th>استلامات بانتظار الفوترة</th><th>فروق سعر الشراء</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                <tbody>@forelse($rows as $row)<tr><td dir="ltr">{{ $row->supplier_invoice_number }}</td><td>{{ $row->supplier_name }}</td><td>{{ $row->invoice_date }}</td><td>{{ $row->due_date }}</td><td>{{ $row->total_amount }}</td><td>{{ $row->grni_amount }}</td><td>{{ $row->ppv_amount }}</td><td>{{ ['draft'=>'مسودة','validated'=>'تم التحقق','matched'=>'تمت المطابقة','approved'=>'معتمد','posted'=>'مرحّل','cancelled'=>'ملغي'][$row->status] ?? $row->status }}</td><td class="flex gap-1">@if($row->status==='draft'&&$access->allows(auth()->id(),'supplier_invoice_manage',$row->branch_id))<flux:button wire:click="supplierStep({{ $row->id }},{{ $row->version }},'validate')" size="sm">تحقق</flux:button>@elseif($row->status==='validated')<flux:button wire:click="supplierStep({{ $row->id }},{{ $row->version }},'match')" size="sm">مطابقة</flux:button>@elseif($row->status==='matched'&&$access->allows(auth()->id(),'supplier_invoice_post',$row->branch_id))<flux:button wire:click="supplierStep({{ $row->id }},{{ $row->version }},'approve')" size="sm">اعتماد</flux:button>@elseif($row->status==='approved'&&$access->allows(auth()->id(),'supplier_invoice_post',$row->branch_id))<flux:button wire:click="supplierStep({{ $row->id }},{{ $row->version }},'post')" wire:confirm="ترحيل الفاتورة وإنشاء AP وقيد رسمي؟" size="sm" variant="primary">ترحيل</flux:button>@endif</td></tr>@empty<tr><td colspan="9"><x-ui.empty-state title="لا توجد فواتير موردين" description="تظهر هنا الفواتير المبنية على استلامات مخزون مرحّلة."/></td></tr>@endforelse</tbody>
            @elseif($screen==='customer-invoices')
                <thead><tr><th>الرقم الرسمي</th><th>العميل</th><th>التاريخ</th><th>الاستحقاق</th><th>الصافي</th><th>الضريبة</th><th>الإجمالي</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                <tbody>@forelse($rows as $row)<tr><td dir="ltr">{{ $row->official_number??'—' }}</td><td>{{ $row->customer_name }}</td><td>{{ $row->invoice_date }}</td><td>{{ $row->due_date }}</td><td>{{ $row->net_amount }}</td><td>{{ $row->tax_amount }}</td><td>{{ $row->total_amount }}</td><td>{{ ['draft'=>'مسودة','validated'=>'تم التحقق','matched'=>'تمت المطابقة','approved'=>'معتمد','posted'=>'مرحّل','cancelled'=>'ملغي'][$row->status] ?? $row->status }}</td><td>@if($row->status==='draft')<flux:button wire:click="customerStep({{ $row->id }},{{ $row->version }},'validate')" size="sm">تحقق</flux:button>@elseif($row->status==='validated'&&$access->allows(auth()->id(),'customer_invoice_post',$row->branch_id))<flux:button wire:click="customerStep({{ $row->id }},{{ $row->version }},'post')" wire:confirm="إصدار الفاتورة وترحيل AR؟" variant="primary" size="sm">إصدار وترحيل</flux:button>@endif</td></tr>@empty<tr><td colspan="9"><x-ui.empty-state title="لا توجد فواتير عملاء" description="تظهر هنا الفواتير المبنية على تسليمات مؤكدة."/></td></tr>@endforelse</tbody>
            @elseif($screen==='credit-notes')
                <thead><tr><th>النوع</th><th>الفاتورة الأصلية</th><th>التاريخ</th><th>السبب</th><th>الصافي</th><th>الضريبة</th><th>الإجمالي</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                <tbody>@forelse($rows as $row)<tr><td>{{ $row->kind === 'supplier' ? 'مورد' : ($row->kind === 'customer' ? 'عميل' : $row->kind) }}</td><td>#{{ $row->original_invoice_id }}</td><td>{{ $row->note_date }}</td><td>{{ $row->reason }}</td><td>{{ $row->net_amount }}</td><td>{{ $row->tax_amount }}</td><td>{{ $row->amount }}</td><td>{{ ['draft'=>'مسودة','validated'=>'تم التحقق','matched'=>'تمت المطابقة','approved'=>'معتمد','posted'=>'مرحّل','cancelled'=>'ملغي'][$row->status] ?? $row->status }}</td><td class="flex gap-1">@if($row->status==='draft'&&$access->allows(auth()->id(),'credit_note_approve',$row->branch_id)&&$row->created_by!==auth()->id())<flux:button wire:click="creditStep({{ $row->id }},'approve')" size="sm">اعتماد مستقل</flux:button>@elseif($row->status==='approved'&&$access->allows(auth()->id(),'credit_note_approve',$row->branch_id))<flux:button wire:click="creditStep({{ $row->id }},'post')" wire:confirm="ترحيل الإشعار وأثره الضريبي والذمة؟" variant="primary" size="sm">ترحيل</flux:button>@elseif($row->status==='posted')<flux:button :href="route('financial.documents',['credit-note',$row->id])" icon="arrow-down-tray" size="sm">PDF</flux:button>@endif</td></tr>@empty<tr><td colspan="9"><x-ui.empty-state title="لا توجد إشعارات" description="لا تنشأ التصحيحات إلا مرتبطة بفاتورة مرحّلة."/></td></tr>@endforelse</tbody>
            @else
                <thead><tr><th>النوع</th><th>رقم المصدر</th><th>تاريخ المستند</th><th>الاستحقاق</th><th>الأصلي</th><th>المسدد</th><th>المصحح</th><th>المتبقي</th><th>العمر</th></tr></thead>
                <tbody>@forelse($rows as $row)<tr><td>{{ $row->party_type === 'supplier' ? 'مورد' : ($row->party_type === 'customer' ? 'عميل' : $row->party_type) }}</td><td dir="ltr">{{ $row->source_number }}</td><td>{{ $row->document_date }}</td><td>{{ $row->due_date }}</td><td>{{ $row->original_amount }}</td><td>{{ $row->settled_amount }}</td><td>{{ $row->credited_amount }}</td><td>{{ $row->remaining_amount }}</td><td>{{ max(0,now('Africa/Cairo')->startOfDay()->diffInDays(\Carbon\CarbonImmutable::parse($row->due_date),false)*-1) }} يوم</td></tr>@empty<tr><td colspan="9"><x-ui.empty-state title="لا توجد أرصدة" description="لا توجد بنود ذمم ضمن النطاق المحدد."/></td></tr>@endforelse</tbody>
            @endif
        </table></div>
        @if(in_array($screen,['ap','ar','aging'],true))
            <div class="financial-mobile-items" aria-label="بنود الذمم">
                @forelse($rows as $row)
                    <article class="aging-card" data-aging-card wire:key="financial-card-{{ $row->id }}">
                        <header><span class="financial-party-type">{{ $row->party_type === 'supplier' ? 'مورد' : ($row->party_type === 'customer' ? 'عميل' : $row->party_type) }}</span><strong><bdi>{{ $row->source_number }}</bdi></strong></header>
                        <dl>
                            <div><dt>تاريخ المستند</dt><dd><bdi>{{ $row->document_date }}</bdi></dd></div>
                            <div><dt>الاستحقاق</dt><dd><bdi>{{ $row->due_date }}</bdi></dd></div>
                            <div><dt>القيمة الأصلية</dt><dd><bdi>{{ $row->original_amount }} ج.م</bdi></dd></div>
                            <div><dt>المسدد</dt><dd><bdi>{{ $row->settled_amount }} ج.م</bdi></dd></div>
                            <div><dt>المصحح</dt><dd><bdi>{{ $row->credited_amount }} ج.م</bdi></dd></div>
                            <div><dt>العمر</dt><dd>{{ max(0,now('Africa/Cairo')->startOfDay()->diffInDays(\Carbon\CarbonImmutable::parse($row->due_date),false)*-1) }} يوم</dd></div>
                        </dl>
                        <footer><span>الرصيد المتبقي</span><strong><bdi>{{ $row->remaining_amount }} ج.م</bdi></strong></footer>
                    </article>
                @empty
                    <x-ui.empty-state title="لا توجد أرصدة" description="لا توجد بنود ذمم ضمن النطاق المحدد." />
                @endforelse
            </div>
        @endif
    </x-ui.panel>
</div>
