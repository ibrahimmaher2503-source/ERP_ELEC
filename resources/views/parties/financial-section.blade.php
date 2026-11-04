<div class="space-y-4" data-party-financial>
    @if(! $financial['authorized'])
        <x-ui.panel><x-ui.empty-state title="عرض البيانات المالية غير مسموح" description="عرض ملف الطرف الأساسي لا يمنح صلاحية عرض الذمم. يلزم تصريح مالي ونطاق فرع مناسب." icon="lock-closed" /></x-ui.panel>
    @else
        @php
            $summary = $financial['summary'];
            $statement = $financial['statement'];
            $money = fn ($value) => $value === null ? '—' : bcadd((string) $value, '0', 2);
            $statusLabels = ['draft'=>'مسودة','validated'=>'تم التحقق','matched'=>'مطابقة','approved'=>'معتمدة','issued'=>'مصدرة','posted'=>'مرحّلة','partially_paid'=>'مسددة جزئيًا','paid'=>'مسددة','partially_collected'=>'محصلة جزئيًا','collected'=>'محصلة','credited'=>'إشعار دائن كامل'];
        @endphp
        <x-ui.panel class="!p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><h2 class="party-section-title">{{ $supplier ? 'ملخص حساب المورد' : 'ملخص حساب العميل' }}</h2><p class="mt-2 text-xs text-slate-500">قراءة من الذمم والخزينة · جنيه مصري ج.م · الفروع المصرّح بها فقط · حتى {{ $financial['as_of'] }}</p></div>
                <flux:select wire:model.live="branch" label="نطاق الفرع" class="sm:max-w-60"><option value="">كل الفروع المصرّح بها</option>@foreach($financial['branches'] as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
            </div>
        </x-ui.panel>
        <div wire:loading role="status" class="party-unavailable">جارٍ تحديث البيانات المالية…</div>
        <x-ui.validation-summary />
        @if(bccomp($summary['unmapped_opening_payables'],'0',2)>0)
            <flux:callout variant="warning" heading="توجد أرصدة افتتاحية خارج دفتر التسويات">تسجيل افتتاحي معتمد بقيمة <bdi>{{ $summary['unmapped_opening_payables'] }}</bdi> ج.م لم يُنقل إلى بنود الذمم القابلة للتسوية. الملخص والكشف أدناه يعرضان دفتر الذمم التشغيلي فقط؛ لم يُدمج التسجيل في رصيد جديد ولم تُفترض تسويته.</flux:callout>
        @endif
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4" data-financial-summary>
            @foreach(['posted_invoices'=>'إجمالي الفواتير المرحّلة','credit_notes'=>'الإشعارات الدائنة المرحّلة','allocated_settlements'=>$supplier?'المدفوعات المخصصة':'التحصيلات المخصصة','outstanding'=>$supplier?'الذمم الدائنة المتبقية':'الذمم المدينة المتبقية','overdue'=>'المبلغ المتأخر','unapplied_credits'=>'رصيد إشعارات غير مطبق','net_outstanding'=>'صافي الذمة بعد رصيد الإشعارات'] as $key=>$label)
                <x-ui.panel class="!p-4"><p class="text-xs text-slate-500">{{ $label }}</p><p class="mt-2 text-lg font-bold tabular-nums" data-kpi="{{ $key }}"><bdi>{{ $money($summary[$key]) }}</bdi> <span class="text-xs font-normal">ج.م</span></p></x-ui.panel>
            @endforeach
            @if(! $supplier)<x-ui.panel class="!p-4"><p class="text-xs text-slate-500">دفعات عميل مقدمة غير مخصصة</p><p class="mt-2 text-lg font-bold tabular-nums" data-kpi="customer_advances"><bdi>{{ $summary['customer_advances'] }}</bdi> <span class="text-xs font-normal">ج.م</span></p></x-ui.panel>@endif
        </div>
        <p class="text-xs leading-6 text-slate-500">الفواتير المرحّلة: {{ $summary['posted_invoice_count'] }} · غير المرحّلة: {{ $summary['pending_invoice_count'] }} (مستبعدة من الأرصدة) · آخر فاتورة مرحّلة: {{ $summary['last_invoice_date'] ?: '—' }} · {{ $supplier?'آخر دفع':'آخر تحصيل' }}: {{ $summary['last_movement_date'] ?: 'غير متاح دون صلاحية الخزينة أو دون حركات' }}. الدفعات المقدمة منفصلة ولا تخفض الذمة قبل تخصيصها.</p>

        <x-erp.section-card :title="$supplier ? 'فواتير المورد' : 'فواتير العميل'" icon="document-text">
            <div class="overflow-x-auto"><table class="party-table w-full min-w-[760px] text-sm" data-financial-invoices><thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>الاستحقاق</th><th>الإجمالي ج.م</th><th>المخصص</th><th>الدائن المطبق</th><th>المتبقي</th><th>الحالة</th></tr></thead><tbody>
            @forelse($financial['invoices']['items'] as $invoice)
                <tr wire:key="invoice-{{ $invoice->id }}"><td>@if($invoice->can_open)<a class="text-blue-700 underline underline-offset-4" href="{{ route('financial.invoices.show', ['type'=>$supplier?'supplier':'customer','invoice'=>$invoice->id]) }}" wire:navigate>{{ $invoice->official_number ?: '#'.$invoice->id }}</a>@else{{ $invoice->official_number ?: '#'.$invoice->id }}@endif</td><td>{{ $invoice->invoice_date }}</td><td>{{ $invoice->due_date }}</td><td><bdi>{{ $invoice->total_amount }}</bdi></td><td><bdi>{{ $invoice->posted_at ? $money($invoice->settled_amount) : '—' }}</bdi></td><td><bdi>{{ $invoice->posted_at ? $money($invoice->credited_amount) : '—' }}</bdi></td><td><bdi>{{ $invoice->posted_at ? $money($invoice->remaining_amount) : 'غير مرحّلة' }}</bdi></td><td>{{ $statusLabels[$invoice->status] ?? $invoice->status }}</td></tr>
            @empty<tr><td colspan="8"><x-ui.empty-state title="لا توجد فواتير لهذا الطرف في النطاق المتاح" icon="document-text" /></td></tr>@endforelse
            </tbody></table></div>
            @include('parties.partials.financial-pagination', ['pagination'=>$financial['invoices'],'table'=>'invoices'])
        </x-erp.section-card>

        <x-erp.section-card :title="$supplier ? 'المدفوعات الفعلية' : 'التحصيلات الفعلية'" icon="banknotes">
            @if(! $financial['movements']['authorized'])<p class="party-unavailable">تفاصيل سندات الخزينة تتطلب صلاحية خزينة مستقلة في الفرع. مبالغ تخصيص الذمم أعلاه تخضع لصلاحية الذمم.</p>
            @else
                <div class="overflow-x-auto"><table class="party-table w-full min-w-[660px] text-sm" data-financial-movements><thead><tr><th>المرجع</th><th>تاريخ الحركة</th><th>المبلغ ج.م</th><th>المخصص</th><th>غير المخصص</th><th>مرجع الخزينة</th></tr></thead><tbody>
                @forelse($financial['movements']['rows']['items'] as $movement)<tr wire:key="movement-{{ $movement->id }}"><td>{{ $movement->reference }}</td><td>{{ $movement->movement_date }}</td><td><bdi>{{ $movement->amount }}</bdi></td><td><bdi>{{ $movement->allocated_amount }}</bdi></td><td><bdi>{{ $movement->unallocated_amount }}</bdi></td><td><a class="text-blue-700 underline underline-offset-4" href="{{ route('treasury.movements.show', $movement->id) }}" wire:navigate>حركة #{{ $movement->id }}</a></td></tr>@empty<tr><td colspan="6"><x-ui.empty-state title="لا توجد حركات خزينة لهذا الطرف" icon="banknotes" /></td></tr>@endforelse
                </tbody></table></div>
                @include('parties.partials.financial-pagination', ['pagination'=>$financial['movements']['rows'],'table'=>'movements'])
            @endif
        </x-erp.section-card>

        <x-erp.section-card title="أعمار الذمم المتبقية" icon="clock">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">@foreach(['current'=>'غير متأخر','1_30'=>'1–30 يومًا','31_60'=>'31–60 يومًا','61_90'=>'61–90 يومًا','over_90'=>'أكثر من 90 يومًا'] as $key=>$label)<div class="rounded-lg bg-blue-50 p-3"><p class="text-xs">{{ $label }}</p><p class="mt-2 font-bold"><bdi>{{ $financial['aging'][$key] ?? '0.00' }}</bdi> ج.م</p></div>@endforeach</div>
        </x-erp.section-card>

        <x-erp.section-card title="كشف حركة الذمم" icon="clipboard-document-list">
            <form wire:submit="applyPeriod" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><flux:input type="date" wire:model="from" label="من تاريخ الإثبات" /><flux:input type="date" wire:model="to" label="إلى تاريخ الإثبات" /><flux:button type="submit" class="self-end">تطبيق الفترة</flux:button></form><div class="mb-3 flex items-end gap-2">@if($canExport)<flux:button wire:click="exportStatement" wire:loading.attr="disabled" icon="arrow-down-tray">تصدير كشف الفترة</flux:button>@endif<flux:button x-on:click="window.print()" icon="printer">طباعة الصفحة</flux:button></div>
            <p class="mb-3 text-xs leading-6 text-slate-500">حسب تاريخ إثبات حركة الذمم بتوقيت القاهرة، وليس تاريخ مستند الفاتورة أو سند النقدية. الفواتير والأرصدة الافتتاحية تزيد الذمة، والإشعارات والتخصيصات تخفضها. لا يضاف سند الخزينة مرة ثانية؛ الدفعات المقدمة خارج هذا الكشف. الأرصدة تخص الفترة كاملة، والجدول يعرض الصفحة الحالية.</p>
            <div class="mb-3 flex flex-wrap justify-between gap-3 rounded-lg bg-blue-50 p-3 text-sm"><span>رصيد أول الفترة: <bdi class="font-bold" data-statement-opening>{{ $statement['opening'] }}</bdi> ج.م</span><span>رصيد آخر الفترة: <bdi class="font-bold" data-statement-closing>{{ $statement['closing'] }}</bdi> ج.م</span></div>
            <div class="overflow-x-auto"><table class="party-table w-full min-w-[660px] text-sm" data-financial-statement><thead><tr><th>تاريخ الإثبات</th><th>النوع</th><th>المرجع</th><th>زيادة الذمة</th><th>تخفيض الذمة</th><th>الرصيد ج.م</th></tr></thead><tbody>
            @forelse($statement['rows']['items'] as $entry)<tr wire:key="statement-{{ $entry->event_order }}-{{ $entry->id }}"><td>{{ $entry->date }}</td><td>{{ match($entry->kind) {'customer_invoice','supplier_invoice'=>'فاتورة مرحّلة','opening_payable'=>'رصيد افتتاحي','credit_note'=>'إشعار دائن','settlement'=>'تخصيص سداد',default=>$entry->kind} }}</td><td>{{ $entry->reference }}</td><td><bdi>{{ $money($entry->increase) }}</bdi></td><td><bdi>{{ $money($entry->decrease) }}</bdi></td><td><bdi>{{ $entry->balance }}</bdi></td></tr>@empty<tr><td colspan="6"><x-ui.empty-state title="لا توجد أحداث مالية خلال الفترة المحددة" icon="clipboard-document-list" /></td></tr>@endforelse
            </tbody></table></div>
            @include('parties.partials.financial-pagination', ['pagination'=>$statement['rows'],'table'=>'statement'])
        </x-erp.section-card>

        <div class="grid gap-4 lg:grid-cols-2"><x-erp.section-card :title="$supplier?'شروط الشراء والخصومات':'شروط البيع والخصومات'" icon="document-text"><p class="party-unavailable">الشروط الافتراضية للطرف وأيام السداد وسياسة الخصم: غير مُعدة. الشروط والخصومات الموجودة تخص مستندات البيع أو الشراء الأصلية، ولا تمثل سياسة دائمة معتمدة لهذا الطرف.</p></x-erp.section-card><x-erp.section-card title="الائتمان والضمانات" icon="shield-check"><p class="party-unavailable">حد الائتمان وحالته والمتاح منه والضمانات الخاصة بالطرف: غير مُفعّلة بعقد مالي معتمد. صافي الذمة المعروض ليس حدًا ائتمانيًا أو موافقة ائتمان.</p></x-erp.section-card></div>
    @endif
</div>
