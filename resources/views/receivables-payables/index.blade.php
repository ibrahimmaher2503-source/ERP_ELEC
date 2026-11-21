<div data-erp-archetype="{{ request()->routeIs('*.dashboard') ? 'dashboard' : (request()->routeIs('*.reports', '*.aging', '*.statements') ? 'report' : (request()->routeIs('*.create', '*.edit') ? 'form' : 'list')) }}" class="erp-finance financial-workspace space-y-5">
    <x-page.heading :title="$nav[$screen][0]" description="تشغيل الذمم من المستندات المصدرية المرحّلة فقط" :breadcrumbs="['المالية', $nav[$screen][0]]">
        <x-slot:actions>
            @if($access->branches(auth()->id(),'ar_view')!==[] || $access->branches(auth()->id(),'ap_view')!==[])
                <flux:button size="sm" :href="route('financial.party-credits')" wire:navigate>رد الأرصدة الدائنة</flux:button>
            @endif
        </x-slot:actions>
    </x-page.heading>
    <x-erp.finance-banner />
    <nav class="flex flex-wrap items-center gap-1.5 rounded-xl border border-slate-200 bg-white p-2 shadow-2xs" aria-label="شاشات الذمم والفواتير">
        @foreach($nav as $navKey => $navItem)
            @if(\Illuminate\Support\Facades\Route::has('financial.'.$navKey))
                <flux:button
                    size="sm"
                    :variant="$screen === $navKey ? 'primary' : 'ghost'"
                    :href="route('financial.'.$navKey)"
                    :aria-current="$screen === $navKey ? 'page' : null"
                    wire:navigate
                >{{ $navItem[0] }}</flux:button>
            @endif
        @endforeach
    </nav>
    @if(session('financial_message'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('financial_message') }}</flux:callout>@endif
    <x-ui.validation-summary />
    <x-ui.panel>
        <x-ui.filters id="financial-filters" target="search,branch,partyType,agingBucket,openStatus" :reset="['search' => '', 'branch' => '', 'partyType' => '', 'agingBucket' => '', 'openStatus' => 'open']">
            <x-slot:search><flux:input wire:model.live.debounce.300ms="search" label="بحث" placeholder="اسم الطرف أو رقم المستند المصدري…" clearable /></x-slot:search>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <flux:select wire:model.live="branch" label="الفرع"><option value="">كل الفروع المصرح بها</option>@foreach($branches as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }}</option>@endforeach</flux:select>
                @if($screen === 'aging')
                    <flux:select wire:model.live="partyType" label="نوع الطرف">
                        <option value="">الموردون والعملاء معاً</option>
                        <option value="supplier">ذمم الموردين فقط (AP)</option>
                        <option value="customer">ذمم العملاء فقط (AR)</option>
                    </flux:select>
                @endif
                @if(in_array($screen, ['ap', 'ar'], true))
                    <flux:select wire:model.live="openStatus" label="حالة الذمة">
                        <option value="open">البنود المفتوحة (ذات رصيد متبقٍ)</option>
                        <option value="all">كل البنود (المفتوحة والمسددة)</option>
                        <option value="settled">البنود المسددة بالكامل فقط</option>
                    </flux:select>
                @endif
                @if(in_array($screen, ['ap', 'ar', 'aging'], true))
                    <flux:select wire:model.live="agingBucket" label="شريحة العمر والـتأخير">
                        <option value="">كل شرائح الاستحقاق</option>
                        @foreach($agingBuckets as $bKey => $bMeta)
                            <option value="{{ $bKey }}">{{ $bMeta['label'] }} ({{ $bMeta['count'] }})</option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
        </x-ui.filters>
    </x-ui.panel>
    @if(in_array($screen,['ap','ar','aging'],true))
        <div class="erp-kpi-summary grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-erp.stat-card label="القيمة الأصلية للمستندات" :value="$totals['original'].' ج.م'" icon="document-text" tone="blue"/>
            <x-erp.stat-card label="إجمالي المسدد والمحصّل" :value="$totals['settled'].' ج.م'" icon="check-circle" tone="emerald"/>
            <x-erp.stat-card label="الرصيد المتبقي القائم" :value="$totals['remaining'].' ج.م'" icon="calculator" tone="purple"/>
            <x-erp.stat-card label="المتأخر عن تاريخ الاستحقاق" :value="$totals['overdue'].' ج.م'" icon="clock" :tone="bccomp($totals['overdue'], '0.00', 2) === 0 ? 'emerald' : 'red'"/>
        </div>
        <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-5" aria-label="توزيع شرائح أعمار الديون">
            @foreach($agingBuckets as $bKey => $bMeta)
                <button
                    type="button"
                    wire:click="$set('agingBucket', '{{ $agingBucket === $bKey ? '' : $bKey }}')"
                    class="flex flex-col justify-between rounded-xl border p-3 text-start transition {{ $agingBucket === $bKey ? 'border-blue-600 bg-blue-50/70 ring-1 ring-blue-600' : 'border-slate-200 bg-white hover:border-blue-300' }}"
                >
                    <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
                        <span class="font-medium">{{ $bMeta['label'] }}</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">{{ $bMeta['count'] }} بند</span>
                    </div>
                    <p class="mt-2 text-base font-bold tabular-nums text-slate-900" dir="ltr">{{ $bMeta['amount'] }} ج.م</p>
                </button>
            @endforeach
        </div>
    @endif
    @if($screen==='supplier-invoices' && $access->branches(auth()->id(),'supplier_invoice_manage')!==[])
        <details class="group rounded-xl border border-blue-200 bg-white" @if($errors->any() || ($form['supplier_id'] ?? '') !== '' || $supplierSearch !== '') open @endif><summary class="flex cursor-pointer list-none items-center gap-2 p-4 font-semibold text-blue-800"><flux:icon name="plus" class="size-4" /> فاتورة مورد جديدة<span class="ms-auto text-xs font-normal text-slate-500 group-open:hidden">اضغط للفتح</span></summary><div class="border-t border-blue-100"><x-ui.panel class="!border-0 !shadow-none"><h2 class="mb-1 font-semibold">فاتورة مورد جديدة</h2><p class="mb-4 text-xs text-slate-500">اختر المورد، ثم أمر الشراء المستلَم، ثم الأصناف المستلمة التي وصلت فاتورتها. يُقترح سعر الأمر ويمكنك تعديله لسعر الفاتورة.</p>
        <form wire:submit="createSupplier" class="space-y-4">
            @php $receiptOrders = collect($receipts)->groupBy('order_id'); @endphp
            <div class="grid gap-3 md:grid-cols-2">
                <div><flux:input wire:model.live.debounce.300ms="supplierSearch" icon="magnifying-glass" label="١) ابحث عن المورد" placeholder="اسم المورد أو كوده" clearable />
                    <flux:select wire:model.live="form.supplier_id" label="المورد" class="mt-2" required><option value="">اختر المورد ({{ count($suppliers) }} نتيجة)</option>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }} · {{ $s->code }}</option>@endforeach</flux:select></div>
                <div class="self-end"><flux:select wire:model.live="receiptChoice" label="٢) أمر الشراء المستلَم" :disabled="$form['supplier_id']===''"><option value="">{{ $form['supplier_id']==='' ? 'اختر المورد أولًا' : ($receiptOrders->isEmpty() ? 'لا توجد استلامات مرحّلة متبقية للفوترة' : 'اختر الأمر') }}</option>@foreach($receiptOrders as $orderId=>$group)<option value="{{ $orderId }}">أمر {{ $group->first()['order_number'] ?? '#'.$orderId }} · {{ $group->count() }} بند متبقٍ</option>@endforeach</flux:select></div>
            </div>
            @if($receiptChoice!=='' && $receiptOrders->has((int)$receiptChoice))
            <div><h3 class="mb-2 text-sm font-semibold text-slate-600">٣) اختر الأصناف والكميات والسعر</h3>
                <div class="overflow-x-auto rounded-lg border border-slate-200"><table class="w-full text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="w-10 p-3"></th><th class="p-3 text-start">الصنف</th><th class="p-3 text-start">تاريخ الاستلام</th><th class="p-3 text-start">المتبقي للفوترة</th><th class="w-36 p-3 text-start">الكمية</th><th class="w-36 p-3 text-start">سعر الفاتورة</th></tr></thead><tbody class="divide-y divide-slate-100">
                @foreach($receiptOrders[(int)$receiptChoice] as $line)<tr wire:key="rl-{{ $line['receipt_line_id'] }}"><td class="p-3"><flux:checkbox wire:model.live="receiptPicked.{{ $line['receipt_line_id'] }}.on" aria-label="اختيار البند" /></td><td class="p-3 font-medium">{{ $line['product_name'] }}</td><td class="p-3 text-slate-500"><bdi>{{ $line['receipt_date'] }}</bdi></td><td class="p-3 tabular-nums">{{ $line['remaining'] }}</td><td class="p-3"><flux:input wire:model="receiptPicked.{{ $line['receipt_line_id'] }}.qty" inputmode="decimal" size="sm" placeholder="{{ $line['remaining'] }}" :disabled="empty($receiptPicked[$line['receipt_line_id']]['on'])" /></td><td class="p-3"><flux:input wire:model="receiptPicked.{{ $line['receipt_line_id'] }}.price" inputmode="decimal" size="sm" placeholder="{{ rtrim(rtrim((string)$line['unit_price'],'0'),'.') }}" :disabled="empty($receiptPicked[$line['receipt_line_id']]['on'])" /></td></tr>@endforeach</tbody></table></div>
                <flux:error name="receiptPicked" /></div>
            <div class="grid gap-3 md:grid-cols-4"><flux:input wire:model="form.supplier_invoice_number" label="٤) رقم فاتورة المورد" required/><flux:input wire:model="form.invoice_date" type="date" label="تاريخ الفاتورة" required/><flux:input wire:model="form.due_date" type="date" label="تاريخ الاستحقاق" required/><flux:select wire:model="form.tax_mode" label="الأسعار"><option value="exclusive">غير شاملة الضريبة</option><option value="inclusive">شاملة الضريبة</option></flux:select></div>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">إنشاء مسودة الفاتورة ومطابقتها مع الاستلام</flux:button>
            @endif
        </form></x-ui.panel></div></details>
    @elseif($screen==='customer-invoices' && $access->branches(auth()->id(),'customer_invoice_manage')!==[])
        <details class="group rounded-xl border border-blue-200 bg-white" @if($errors->any() || ($form['customer_id'] ?? '') !== '' || $customerSearch !== '') open @endif><summary class="flex cursor-pointer list-none items-center gap-2 p-4 font-semibold text-blue-800"><flux:icon name="plus" class="size-4" /> فاتورة عميل جديدة<span class="ms-auto text-xs font-normal text-slate-500 group-open:hidden">اضغط للفتح</span></summary><div class="border-t border-blue-100"><x-ui.panel class="!border-0 !shadow-none"><h2 class="mb-1 font-semibold">فاتورة عميل جديدة</h2><p class="mb-4 text-xs text-slate-500">اختر العميل، ثم الأمر الذي سُلّم، ثم البنود والكميات التي تريد فوترتها. المسودة لا تُرحَّل قبل اعتمادها.</p>
        <form wire:submit="createCustomer" class="space-y-4">
            @php $orders = collect($invoiceable)->groupBy('order_id'); @endphp
            <div class="grid gap-3 md:grid-cols-2">
                <div><flux:input wire:model.live.debounce.300ms="customerSearch" icon="magnifying-glass" label="١) ابحث عن العميل" placeholder="اسم العميل أو كوده" clearable />
                    <flux:select wire:model.live="form.customer_id" label="العميل" class="mt-2" required><option value="">اختر العميل ({{ count($customers) }} نتيجة)</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }} · {{ $c->code }}</option>@endforeach</flux:select></div>
                <div class="self-end"><flux:select wire:model.live="orderChoice" label="٢) أمر البيع المسلَّم" :disabled="$form['customer_id']===''"><option value="">{{ $form['customer_id']==='' ? 'اختر العميل أولًا' : ($orders->isEmpty() ? 'لا توجد تسليمات مؤكدة متبقية للفوترة' : 'اختر الأمر') }}</option>@foreach($orders as $orderId=>$group)<option value="{{ $orderId }}">أمر {{ $group->first()['order_number'] ?? '#'.$orderId }} · {{ $group->count() }} بند متبقٍ</option>@endforeach</flux:select></div>
            </div>
            @if($orderChoice!=='' && $orders->has((int)$orderChoice))
            <div><h3 class="mb-2 text-sm font-semibold text-slate-600">٣) اختر البنود والكميات</h3>
                <div class="overflow-x-auto rounded-lg border border-slate-200"><table class="w-full text-sm"><thead class="bg-slate-50 text-slate-500"><tr><th class="w-10 p-3"></th><th class="p-3 text-start">البند</th><th class="p-3 text-start">التسليم</th><th class="p-3 text-start">المتبقي للفوترة</th><th class="w-40 p-3 text-start">الكمية المفوترة</th></tr></thead><tbody class="divide-y divide-slate-100">
                @foreach($orders[(int)$orderChoice] as $line)<tr wire:key="dl-{{ $line['delivery_line_id'] }}"><td class="p-3"><flux:checkbox wire:model.live="picked.{{ $line['delivery_line_id'] }}.on" aria-label="اختيار البند" /></td><td class="p-3 font-medium">{{ $line['description'] }}</td><td class="p-3 text-slate-500">{{ $line['delivery_reference'] }}</td><td class="p-3 tabular-nums">{{ $line['remaining'] }}</td><td class="p-3"><flux:input wire:model.live.debounce.300ms="picked.{{ $line['delivery_line_id'] }}.qty" inputmode="decimal" size="sm" placeholder="{{ $line['remaining'] }}" :disabled="empty($picked[$line['delivery_line_id']]['on'])" /></td></tr>@endforeach</tbody></table></div>
                <flux:error name="picked" /></div>
            @php
                $orderLinesGroup = $orders[(int) $orderChoice];
                $firstOrderLine = $orderLinesGroup->first();
                $orderTaxTreatment = $firstOrderLine['tax_treatment'] ?? 'rule';
                $selectedLines = $orderLinesGroup->filter(fn ($l) => ! empty($picked[$l['delivery_line_id']]['on']));
                $selectedQtyTotal = '0';
                $selectedNetTotal = '0.00';
                $hasAllLineNets = $selectedLines->isNotEmpty();
                foreach ($selectedLines as $sl) {
                    $q = trim((string) ($picked[$sl['delivery_line_id']]['qty'] ?? ''));
                    $q = $q !== '' ? $q : (string) $sl['remaining'];
                    if (preg_match('/^\d{1,16}(\.\d{1,8})?$/', $q)) {
                        $selectedQtyTotal = bcadd($selectedQtyTotal, $q, 8);
                        if (($sl['order_line_net'] ?? null) !== null && bccomp((string) ($sl['ordered_quantity'] ?? '0'), '0', 8) > 0) {
                            $lineEstNet = \App\Modules\Accounting\Domain\FinancialDecimal::round(bcdiv(bcmul((string) $sl['order_line_net'], $q, 12), (string) $sl['ordered_quantity'], 12));
                            $selectedNetTotal = bcadd($selectedNetTotal, $lineEstNet, 2);
                        } else {
                            $hasAllLineNets = false;
                        }
                    } else {
                        $hasAllLineNets = false;
                    }
                }
                $selectedQtyDisplay = rtrim(rtrim($selectedQtyTotal, '0'), '.') ?: '0';
                $selectedCustomerObj = collect($customers)->firstWhere('id', (int) $form['customer_id']);
                $selectedOrderNum = $firstOrderLine['order_number'] ?? '#'.$orderChoice;
                $activeInvTaxChoice = ($form['tax_treatment'] ?? 'inherit') === 'inherit' ? $orderTaxTreatment : $form['tax_treatment'];
                $previewInvTax = ! $hasAllLineNets ? null : match ($activeInvTaxChoice) {
                    'exempt_0' => '0.00',
                    'vat_14' => \App\Modules\Accounting\Domain\FinancialDecimal::round(bcmul($selectedNetTotal, '0.14', 10)),
                    default => null,
                };
                $previewInvTotal = $hasAllLineNets && $previewInvTax !== null ? bcadd($selectedNetTotal, $previewInvTax, 2) : null;
            @endphp
            <div class="grid gap-3 md:grid-cols-2">
                <flux:select wire:model.live="form.tax_treatment" label="المعاملة الضريبية للفاتورة">
                    @if($orderTaxTreatment === 'rule')
                        <option value="inherit">مطابقة أمر البيع (وفق القاعدة الضريبية)</option>
                    @endif
                    <option value="vat_14">خاضع لضريبة 14%</option>
                    <option value="exempt_0">بدون ضريبة (0%)</option>
                </flux:select>
                <div class="self-end text-xs text-slate-600">
                    معاملة أمر البيع الأصلية: <strong>{{ \App\Modules\Accounting\Application\TaxCalculator::treatmentLabel($orderTaxTreatment) }}</strong>
                </div>
            </div>
            @if($activeInvTaxChoice === 'exempt_0')
                <flux:textarea wire:model="form.tax_exempt_reason" label="سبب اختيار بدون ضريبة (إلزامي)" rows="2" placeholder="اكتب السبب الواضح لعدم احتساب الضريبة (5 أحرف على الأقل)…" required />
            @endif
            <div class="rounded-lg border border-blue-200 bg-blue-50/60 p-3 text-xs text-slate-700">
                <p class="font-semibold text-blue-900">ملخص ما قبل الحفظ</p>
                <div class="mt-1.5 flex flex-wrap gap-x-6 gap-y-1">
                    <span>العميل: <strong>{{ $selectedCustomerObj ? $selectedCustomerObj->name.' ('.$selectedCustomerObj->code.')' : 'العميل #'.$form['customer_id'] }}</strong></span>
                    <span>الأمر: <strong dir="ltr">{{ $selectedOrderNum }}</strong></span>
                    <span>البنود المختارة: <strong>{{ $selectedLines->count() }}</strong></span>
                    <span>إجمالي الكمية: <strong class="tabular-nums">{{ $selectedQtyDisplay }}</strong></span>
                    @if($hasAllLineNets)
                        <span>الصافي: <strong class="tabular-nums">{{ $selectedNetTotal }} ج.م</strong></span>
                    @endif
                    @if($previewInvTax !== null && $previewInvTotal !== null)
                        <span>الضريبة المقدرة: <strong class="tabular-nums">{{ $previewInvTax }} ج.م</strong></span>
                        <span>الإجمالي المقدر: <strong class="tabular-nums text-blue-950">{{ $previewInvTotal }} ج.م</strong></span>
                    @endif
                </div>
            </div>
            <div class="grid gap-3 md:grid-cols-3"><flux:input wire:model="form.invoice_date" type="date" label="تاريخ الفاتورة" required/><flux:input wire:model="form.due_date" type="date" label="تاريخ الاستحقاق" required/><div class="self-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">إنشاء مسودة الفاتورة</flux:button></div></div>
            @endif
        </form></x-ui.panel></div></details>
    @elseif($screen==='credit-notes' && $access->branches(auth()->id(),'credit_note_manage')!==[])
        <details class="group rounded-xl border border-blue-200 bg-white" @if($errors->any() || ($form['invoice_id'] ?? '') !== '') open @endif><summary class="flex cursor-pointer list-none items-center gap-2 p-4 font-semibold text-blue-800"><flux:icon name="plus" class="size-4" /> إشعار تعديل على فاتورة<span class="ms-auto text-xs font-normal text-slate-500 group-open:hidden">اضغط للفتح</span></summary><div class="border-t border-blue-100"><x-ui.panel class="!border-0 !shadow-none"><h2 class="mb-1 font-semibold">إشعار تعديل على فاتورة مرحّلة</h2><p class="mb-3 text-xs text-slate-500">اختر نوع الفاتورة ثم الفاتورة نفسها من القائمة، وأدخل القيمة وسبب التعديل.</p><form wire:submit="createCredit" class="grid gap-3 md:grid-cols-3">
            <flux:select wire:model.live="form.kind" label="١) الإشعار على فاتورة"><option value="supplier">مورد</option><option value="customer">عميل</option></flux:select><flux:select wire:model="form.invoice_id" label="٢) الفاتورة الأصلية" required><option value="">اختر الفاتورة ({{ count($creditInvoices) }})</option>@foreach($creditInvoices as $ci)<option value="{{ $ci->id }}">{{ $ci->party }} · {{ $ci->invoice_date }} · {{ $ci->total_amount }} ج.م · #{{ $ci->id }}</option>@endforeach</flux:select>
            <flux:input wire:model="form.note_date" type="date" label="تاريخ الإشعار" required/><flux:input wire:model="form.amount" inputmode="decimal" label="القيمة ج.م" required/><flux:input wire:model="form.reason" label="سبب التصحيح" required/>
            <div class="erp-form-actions"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">إنشاء مسودة الإشعار</flux:button></div>
        </form></x-ui.panel></div></details>
    @endif
    <x-ui.panel>
        <div @class(['overflow-x-auto', 'financial-open-items-table'=>in_array($screen,['ap','ar','aging'],true)])><table>
            @if($screen==='supplier-invoices')
                <thead><tr><th>رقم المورد</th><th>المورد</th><th>التاريخ</th><th>الاستحقاق</th><th>الإجمالي</th><th>استلامات بانتظار الفوترة</th><th>فروق سعر الشراء</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                <tbody>@forelse($rows as $row)<tr><td dir="ltr">{{ $row->supplier_invoice_number }}</td><td>{{ $row->supplier_name }}</td><td>{{ $row->invoice_date }}</td><td>{{ $row->due_date }}</td><td>{{ $row->total_amount }}</td><td>{{ $row->grni_amount }}</td><td>{{ $row->ppv_amount }}</td><td>{{ ['draft'=>'مسودة','validated'=>'تم التحقق','matched'=>'تمت المطابقة','approved'=>'معتمد','posted'=>'مرحّل','cancelled'=>'ملغي'][$row->status] ?? $row->status }}</td><td class="flex gap-1">@if($row->status==='draft'&&$access->allows(auth()->id(),'supplier_invoice_manage',$row->branch_id))<flux:button wire:click="supplierStep({{ $row->id }},{{ $row->version }},'validate')" size="sm">تحقق</flux:button>@elseif($row->status==='validated')<flux:button wire:click="supplierStep({{ $row->id }},{{ $row->version }},'match')" size="sm">مطابقة</flux:button>@elseif($row->status==='matched'&&$access->allows(auth()->id(),'supplier_invoice_post',$row->branch_id))<flux:button wire:click="supplierStep({{ $row->id }},{{ $row->version }},'approve')" size="sm">اعتماد</flux:button>@elseif($row->status==='approved'&&$access->allows(auth()->id(),'supplier_invoice_post',$row->branch_id))<flux:button wire:click="supplierStep({{ $row->id }},{{ $row->version }},'post')" wire:confirm="ترحيل الفاتورة وإنشاء AP وقيد رسمي؟" size="sm" variant="primary">ترحيل</flux:button>@endif</td></tr>@empty<tr><td colspan="9"><x-ui.empty-state title="لا توجد فواتير موردين" description="تظهر هنا الفواتير المبنية على استلامات مخزون مرحّلة."/></td></tr>@endforelse</tbody>
            @elseif($screen==='customer-invoices')
                <thead><tr><th>الرقم الرسمي</th><th>العميل</th><th>التاريخ</th><th>الاستحقاق</th><th>الصافي</th><th>الضريبة</th><th>الإجمالي</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                <tbody>@forelse($rows as $row)<tr><td dir="ltr">{{ $row->official_number??'—' }}</td><td>{{ $row->customer_name }}@if(($row->tax_treatment ?? 'rule') === 'exempt_0') <span class="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">بدون ضريبة</span>@elseif(($row->tax_treatment ?? 'rule') === 'vat_14') <span class="ms-1 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700">14%</span>@endif</td><td>{{ $row->invoice_date }}</td><td>{{ $row->due_date }}</td><td>{{ $row->net_amount }}</td><td>{{ $row->tax_amount }}</td><td>{{ $row->total_amount }}</td><td>{{ ['draft'=>'مسودة','validated'=>'تم التحقق','matched'=>'تمت المطابقة','approved'=>'معتمد','posted'=>'مرحّل','cancelled'=>'ملغي'][$row->status] ?? $row->status }}</td><td class="flex gap-1"><flux:button :href="route('financial.invoices.show', ['customer', $row->id])" wire:navigate size="sm">التفاصيل</flux:button>@if($row->status==='draft')<flux:button wire:click="customerStep({{ $row->id }},{{ $row->version }},'validate')" size="sm">تحقق</flux:button>@elseif($row->status==='validated'&&$access->allows(auth()->id(),'customer_invoice_post',$row->branch_id))<flux:button wire:click="customerStep({{ $row->id }},{{ $row->version }},'post')" wire:confirm="إصدار الفاتورة وترحيل AR؟" variant="primary" size="sm">إصدار وترحيل</flux:button>@endif</td></tr>@empty<tr><td colspan="9"><x-ui.empty-state title="لا توجد فواتير عملاء" description="تظهر هنا الفواتير المبنية على تسليمات مؤكدة."/></td></tr>@endforelse</tbody>
            @elseif($screen==='credit-notes')
                <thead><tr><th>النوع</th><th>الفاتورة الأصلية</th><th>التاريخ</th><th>السبب</th><th>الصافي</th><th>الضريبة</th><th>الإجمالي</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                <tbody>@forelse($rows as $row)<tr><td>{{ $row->kind === 'supplier' ? 'مورد' : ($row->kind === 'customer' ? 'عميل' : $row->kind) }}</td><td>#{{ $row->original_invoice_id }}</td><td>{{ $row->note_date }}</td><td>{{ $row->reason }}</td><td>{{ $row->net_amount }}</td><td>{{ $row->tax_amount }}</td><td>{{ $row->amount }}</td><td>{{ ['draft'=>'مسودة','validated'=>'تم التحقق','matched'=>'تمت المطابقة','approved'=>'معتمد','posted'=>'مرحّل','cancelled'=>'ملغي'][$row->status] ?? $row->status }}</td><td class="flex gap-1">@if($row->status==='draft'&&$access->allows(auth()->id(),'credit_note_approve',$row->branch_id)&&($row->created_by!==auth()->id() || $canSelfApproveCredit))<flux:button wire:click="creditStep({{ $row->id }},'approve')" size="sm">{{ $row->created_by===auth()->id() ? 'اعتماد (ذاتي موثّق)' : 'اعتماد مستقل' }}</flux:button>@elseif($row->status==='approved'&&$access->allows(auth()->id(),'credit_note_approve',$row->branch_id))<flux:button wire:click="creditStep({{ $row->id }},'post')" wire:confirm="ترحيل الإشعار وأثره الضريبي والذمة؟" variant="primary" size="sm">ترحيل</flux:button>@elseif($row->status==='posted')<flux:button :href="route('financial.documents',['credit-note',$row->id])" icon="arrow-down-tray" size="sm">PDF</flux:button>@endif</td></tr>@empty<tr><td colspan="9"><x-ui.empty-state title="لا توجد إشعارات" description="لا تنشأ التصحيحات إلا مرتبطة بفاتورة مرحّلة."/></td></tr>@endforelse</tbody>
            @else
                <thead><tr><th>النوع</th><th>رقم المصدر</th><th>تاريخ المستند</th><th>الاستحقاق</th><th>شريحة العمر</th><th>الأصلي</th><th>المسدد</th><th>المصحح</th><th>المتبقي</th><th>التأخير</th><th>المستند</th></tr></thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row->party_type === 'supplier' ? 'مورد' : ($row->party_type === 'customer' ? 'عميل' : $row->party_type) }}</td>
                            <td dir="ltr" class="font-medium">{{ $row->source_number }}</td>
                            <td class="tabular-nums">{{ $row->document_date }}</td>
                            <td class="tabular-nums">{{ $row->due_date }}</td>
                            <td>
                                <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs font-medium {{ ($row->days_overdue ?? 0) === 0 ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : (($row->days_overdue ?? 0) <= 30 ? 'border-blue-200 bg-blue-50 text-blue-700' : (($row->days_overdue ?? 0) <= 60 ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-red-200 bg-red-50 text-red-700')) }}">
                                    {{ $row->aging_label ?? '—' }}
                                </span>
                            </td>
                            <td class="tabular-nums">{{ $row->original_amount }}</td>
                            <td class="tabular-nums">{{ $row->settled_amount }}</td>
                            <td class="tabular-nums">{{ $row->credited_amount }}</td>
                            <td class="font-semibold tabular-nums">{{ $row->remaining_amount }}</td>
                            <td class="tabular-nums">{{ $row->days_overdue ?? max(0,now('Africa/Cairo')->startOfDay()->diffInDays(\Carbon\CarbonImmutable::parse($row->due_date),false)*-1) }} يوم</td>
                            <td>
                                @if(in_array($row->source_type ?? '', ['supplier_invoice', 'customer_invoice'], true))
                                    <flux:button size="xs" variant="ghost" :href="route('financial.invoices.show', [$row->source_type === 'supplier_invoice' ? 'supplier' : 'customer', $row->source_id])" wire:navigate>الفاتورة</flux:button>
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11"><x-ui.empty-state title="لا توجد أرصدة" description="لا توجد بنود ذمم ضمن النطاق المحدد."/></td></tr>
                    @endforelse
                </tbody>
                @if(count($rows) > 0)
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50 font-bold text-slate-900">
                            <td colspan="5" class="p-3">إجمالي الذمم المعروضة ({{ count($rows) }} بند)</td>
                            <td class="p-3 tabular-nums" dir="ltr">{{ $totals['original'] }} ج.م</td>
                            <td class="p-3 tabular-nums" dir="ltr">{{ $totals['settled'] }} ج.م</td>
                            <td class="p-3 tabular-nums" dir="ltr">{{ $totals['credited'] }} ج.م</td>
                            <td class="p-3 tabular-nums text-blue-950" dir="ltr">{{ $totals['remaining'] }} ج.م</td>
                            <td colspan="2" class="p-3 text-xs text-red-700">متأخر: <bdi dir="ltr">{{ $totals['overdue'] }} ج.م</bdi></td>
                        </tr>
                    </tfoot>
                @endif
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
