@use('App\Modules\Inventory\Presentation\InventoryDisplay', 'D')
@php
    $editable = ! $record || ($record->status === 'draft' && $record->created_by === auth()->id());
    $isCreator = $record && $record->created_by === auth()->id();
    $wName = fn ($id) => $warehouses->firstWhere('id', $id)?->name ?? 'مستودع #'.$id;
    $steps = match ($kind) {
        'transfer' => ['draft', 'submitted', 'approved', 'dispatched', 'received'],
        'count' => ['draft', 'submitted', 'posted'],
        default => ['draft', 'submitted', 'approved', 'posted'],
    };
    $current = $record ? array_search($record->status, $steps, true) : 0;
    $actions = $record ? match ($record->status) {
        'draft' => ['submit' => 'إرسال للاعتماد', 'cancel' => 'إلغاء المسودة'],
        'submitted' => ['approve' => 'اعتماد المستند', 'cancel' => 'إلغاء'],
        'approved' => $kind === 'transfer' ? ['dispatch' => 'إرسال التحويل'] : ($kind === 'count' ? [] : ['post' => 'ترحيل للمخزون']),
        'dispatched' => ['receive' => 'تأكيد وصول كامل المتبقي بحالة سليمة'],
        default => [],
    } : [];
    $allowed = [];
    $canSelfApproveInventory = app(\App\Modules\Organization\Application\Contracts\OrganizationApi::class)->allowsSelfApproval(auth()->id(), 'inventory');
    foreach ($actions as $action => $label) {
        $permission = match ($action) { 'submit' => $kind === 'count' ? 'submit' : 'create', 'cancel' => 'create', default => $action };
        $permCode = $docs->permission($kind, $permission);
        $hasWarehouses = $access->allows(auth()->id(), $permCode, $record->warehouse_id)
            && (! $record->destination_id || $access->allows(auth()->id(), $permCode, $record->destination_id));
        if ($hasWarehouses && ! ($action === 'approve' && $isCreator && ! $canSelfApproveInventory)) {
            $allowed[$action] = ($action === 'approve' && $isCreator) ? $label.' (ذاتي موثّق)' : $label;
        }
    }
    $qtyLabel = $kind === 'count' ? 'الكمية المعدودة' : ($kind === 'adjustment' ? 'فرق التسوية (+/-)' : 'الكمية');
    $showReceiving = $record && $kind === 'transfer' && (in_array($record->status, ['dispatched', 'received'], true) || $transferReceipts->isNotEmpty());
    [$statusLabel, $tone] = $record ? D::documentStatus($kind, $record->status) : ['مسودة جديدة', 'neutral'];
    $hasLineSerials = $lines->contains(fn ($l) => json_decode($l->serials ?? '[]', true) !== []);
@endphp
<div data-erp-archetype="{{ request()->routeIs('*.create', '*.edit') ? 'form' : 'details' }}" class="inventory-ui space-y-5">
    <x-page.heading :title="$record ? $title.' #'.$record->id : $title.' — مستند جديد'" description="المسودة لا تغيّر المخزون. الاعتماد يحتاج مستخدمًا مختلفًا عن المنشئ." :breadcrumbs="['المخزون',$title]">
        <x-slot:actions><flux:button icon="arrow-right" :href="route(['opening'=>'inventory.receipts','receipt'=>'inventory.receipts','issue'=>'inventory.issues','transfer'=>'inventory.transfers','adjustment'=>'inventory.adjustments','count'=>'inventory.counts'][$kind])" wire:navigate>العودة للقائمة</flux:button></x-slot:actions>
    </x-page.heading>
    <x-ui.validation-summary />
    <flux:error name="inventory" />
    @if(session('status'))<flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>@endif
    @if($kind==='receipt')<flux:callout variant="warning">استلام المشتريات والمرتجعات ينتظر عقد أهلية مصدره. يمكن حفظ مسودة فقط.</flux:callout>@endif

    @if($record)
    <x-ui.panel class="inventory-flow">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3"><span class="inventory-badge !ms-0 !text-sm" data-tone="{{ $tone }}">{{ $statusLabel }}</span><span class="text-sm text-slate-500">أنشأه <x-erp.user-name :id="$record->created_by" /> · إصدار {{ $record->version }}</span></div>
            <div class="flex flex-wrap gap-2">
                @foreach($allowed as $action => $label)
                    <flux:button :variant="$action==='cancel'?'danger':'primary'" wire:click="applyAction('{{ $action }}')" wire:confirm="{{ $action==='receive' ? 'تأكيد وصول كامل الكمية المتبقية بحالة سليمة (ويشمل استرداد أي مفقود سابق كواصل سليم)؟ لا يمكن التراجع بعد الترحيل.' : 'تنفيذ الإجراء على المستند المحفوظ؟' }}" wire:loading.attr="disabled">{{ $label }}</flux:button>
                @endforeach
                @if($record->linked_document_id)<flux:button :href="route('inventory.document.show',['adjustment',$record->linked_document_id])" wire:navigate>فتح تسوية فروق الجرد #{{ $record->linked_document_id }}</flux:button>@endif
            </div>
        </div>
        @if($record->status === 'cancelled')
            <p class="mt-4 text-sm text-red-700">المستند ملغي ولا يمكن تنفيذ أي إجراء عليه.</p>
        @else
            <ol class="inventory-steps" aria-label="مراحل المستند">
                @foreach($steps as $i => $step)
                    <li @class(['is-done'=>$current !== false && $i < $current, 'is-current'=>$i === $current]) @if($i === $current) aria-current="step" @endif><span>{{ $i + 1 }}</span>{{ D::documentStatus($kind, $step)[0] }}</li>
                @endforeach
            </ol>
        @endif
        @if($record->status === 'submitted' && $isCreator)
            @if($canSelfApproveInventory)
                <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">اعتماد ذاتي موثّق — أنت منشئ هذا المستند، وتتيح السياسة المفعّلة لدورك الاعتماد مع تسجيل ذلك في سجل التدقيق (<bdi>self_approved: true</bdi>).</p>
            @else
                <p class="mt-3 text-sm text-slate-600">أنت منشئ هذا المستند، لذلك يحتاج الاعتماد إلى مستخدم آخر بصلاحية الاعتماد.</p>
            @endif
        @elseif($record->status === 'approved' && $kind === 'count')<p class="mt-3 text-sm text-slate-600">اعتماد الجرد ينشئ مسودة تسوية مستقلة بالفروقات.</p>@endif
        @if($kind==='count' && in_array($record->status,['draft','submitted'],true))<p class="mt-3 text-sm text-slate-500">أي حركة أو حجز بعد لقطة الجرد يمنع اعتماده حتى إعادة العدّ.</p>@endif
    </x-ui.panel>
    @endif

    @if($editable)
        @if($kind==='count')
        <x-erp.section-card title="تحديد صنف الجرد بالباركود" icon="qr-code">
            <div class="flex flex-wrap items-end gap-3">
                <flux:input wire:model="barcode" wire:keydown.enter.prevent="addByBarcode" label="باركود الصنف" maxlength="100" autocomplete="off" />
                <flux:button type="button" wire:click="addByBarcode" wire:loading.attr="disabled">تحديد الصنف</flux:button>
            </div>
            <flux:error name="barcode" />
            <p class="mt-3 text-xs text-slate-500">اختر المستودع أولًا. الباركود يحدد الصنف فقط؛ أدخل العدد الفعلي يدويًا. تكرار المسح لا يضاعف الكمية.</p>
        </x-erp.section-card>
        @endif

        @if(! $record)
        <flux:callout icon="information-circle" class="!bg-blue-50"><flux:callout.heading>خطوات سريعة</flux:callout.heading><flux:callout.text>١) اختر المستودع · ٢) ابحث عن الصنف وأدخل الكمية · ٣) احفظ المسودة · ٤) أرسلها للاعتماد ثم رحّلها. الاعتماد من مستخدم غير المنشئ، والمسودة لا تغيّر الرصيد.</flux:callout.text></flux:callout>
        @endif

        <form wire:submit="save" class="space-y-5">
            <x-erp.section-card title="بيانات المستند" icon="document-text">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:select label="{{ $kind==='transfer' ? 'من مستودع' : 'المستودع' }}" wire:model.live="form.warehouse_id" :disabled="$record !== null" required><option value="">اختر المستودع</option>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</flux:select>
                    @if($kind==='transfer')<flux:select label="إلى مستودع" wire:model="form.destination_id" required><option value="">اختر المستودع</option>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</flux:select>@endif
                    <flux:input label="السبب / ملاحظات المستند" wire:model="form.reason" required maxlength="500" placeholder="مثال: رصيد افتتاحي، أو سبب العملية" />
                </div>
                <flux:error name="reason" /><flux:error name="warehouse_id" />
            </x-erp.section-card>

            <x-erp.section-card title="الأصناف والكميات بالوحدة الأساسية" icon="cube">
                <div class="mb-4"><flux:input wire:model.live.debounce.300ms="productSearch" icon="magnifying-glass" label="ابحث عن صنف" placeholder="اكتب اسم الصنف أو رمزه ليظهر في القوائم أدناه" clearable /><p class="mt-1 text-xs text-slate-500" wire:loading.remove wire:target="productSearch">{{ count($products) }} صنف ظاهر في القائمة@if($productSearch !== '') · نتائج البحث عن «{{ $productSearch }}»@endif</p><p class="mt-1 text-xs text-slate-500" wire:loading wire:target="productSearch">جارٍ البحث…</p></div>
                <div class="space-y-3">
                    @foreach($form['lines'] as $i=>$line)
                        @php($picked = collect($products)->firstWhere('id',(int)($line['product_id']?:0)))
                        @php($balance = $availability[(int)($line['product_id']?:0)] ?? null)
                        @php($savedLine = $lines->firstWhere('product_id',(int)($line['product_id']?:0)))
                        <div class="inventory-line" wire:key="line-{{ $i }}">
                            <div class="inventory-line-grid">
                                <flux:select label="الصنف" wire:model.live="form.lines.{{ $i }}.product_id" required search-model="productSearch"><option value="">اختر الصنف…</option>@if($line['product_id'] && !collect($products)->contains('id',(int)$line['product_id']))<option value="{{ $line['product_id'] }}">{{ $savedLine?->product_name ?? '#'.$line['product_id'] }}</option>@endif @foreach($products as $p)<option value="{{ $p['id'] }}">{{ $p['sku'] }} · {{ $p['name'] }} ({{ $p['unit_name'] }})</option>@endforeach</flux:select>
                                <flux:input label="{{ $qtyLabel }}" wire:model="form.lines.{{ $i }}.quantity" inputmode="decimal" required />
                                <flux:button icon="trash" variant="danger" wire:click="removeLine({{ $i }})" aria-label="حذف السطر" />
                            </div>
                            @if($kind==='adjustment')
                            <div class="inventory-line-grid mt-3"><flux:input label="تكلفة الوحدة للفرق الموجب ج.م" wire:model="form.lines.{{ $i }}.unit_cost" inputmode="decimal" /><flux:input label="مرجع تقييم الفرق الموجب" wire:model="form.lines.{{ $i }}.cost_reference" /></div>
                            @endif
                            @if(($picked['tracking']??'')==='serial')<div class="mt-3"><flux:textarea label="الأرقام المسلسلة — رقم في كل سطر" wire:model="form.lines.{{ $i }}.serials" rows="2" /></div>@endif
                            @if($line['product_id'])
                            <p class="inventory-line-meta">
                                @if($balance)<span>الرصيد الحالي: <b>{{ D::qty($balance->on_hand) }}</b> · المتاح: <b>{{ D::qty($balance->available) }}</b> {{ $balance->unit_name }}</span>
                                @elseif($form['warehouse_id'] !== '' && $access->allows(auth()->id(),'balance_view',(int)$form['warehouse_id']))<span>لا يوجد رصيد لهذا الصنف في المستودع.</span>@endif
                                @if($kind==='count' && $savedLine?->recorded !== null)
                                    @php($counted = preg_match('/^-?\d+(\.\d+)?$/D', (string)($line['quantity'] ?? '')) ? bcsub((string)$line['quantity'], $savedLine->recorded, 8) : null)
                                    <span>الدفتري وقت الجرد: <b>{{ D::qty($savedLine->recorded) }}</b>@if($counted !== null) · الفارق: <b dir="ltr" class="{{ bccomp($counted,'0',8) === 0 ? '' : (bccomp($counted,'0',8) > 0 ? 'text-emerald-700' : 'text-amber-700') }}">{{ bccomp($counted,'0',8) > 0 ? '+' : '' }}{{ D::qty($counted) }}</b>@endif</span>
                                @endif
                            </p>
                            @endif
                            <flux:error name="lines.{{ $i }}.quantity" />
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap gap-3"><flux:button icon="plus" wire:click="addLine">إضافة صنف</flux:button><flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">حفظ المسودة</flux:button></div>
            </x-erp.section-card>
        </form>

        <details class="rounded-xl border border-blue-100 bg-white"><summary class="cursor-pointer p-4 text-sm font-semibold text-blue-800">استيراد البنود من ملف Excel (اختياري)</summary><div class="p-4 pt-0"><div class="flex flex-wrap items-end gap-3"><flux:button wire:click="template" icon="arrow-down-tray">تحميل القالب</flux:button><flux:input type="file" wire:model="importFile" accept=".xlsx" label="رفع القالب" /><flux:button wire:click="previewImport" icon="eye" wire:loading.attr="disabled">فحص ومعاينة</flux:button></div><flux:error name="importFile" /><p class="mt-3 text-xs text-slate-500">اختر المستودع أولًا. يُحذف الملف الأصلي بعد الفحص مباشرة. المعاينة لا تحفظ أو ترحل أي رصيد.</p>@if($importPreview)<div class="mt-3 max-h-60 overflow-auto"><table class="w-full text-sm"><thead class="bg-blue-50"><tr><th>السطر</th><th>رمز الصنف</th><th>الكمية</th><th>النتيجة</th></tr></thead><tbody>@foreach($importPreview as $l)<tr wire:key="import-{{ $l['row'] }}"><td class="p-2">{{ $l['row'] }}</td><td class="p-2">{{ $l['sku'] }}</td><td class="p-2">{{ $l['quantity']??'—' }}</td><td class="p-2">{{ $l['error']??'صالح' }}</td></tr>@endforeach</tbody></table></div><p class="mt-3 text-xs text-amber-700">تطبيق المعاينة يستبدل البنود الحالية في النموذج دون حفظ أو ترحيل.</p><flux:button class="mt-2" wire:click="applyImport" :disabled="collect($importPreview)->contains(fn($r)=>$r['error']!==null)">استخدام البنود في النموذج</flux:button>@endif</div></details>
    @elseif($record)
        <x-erp.section-card title="بيانات المستند" icon="document-text">
            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-xs text-slate-500">{{ $kind==='transfer' ? 'من مستودع' : 'المستودع' }}</dt><dd class="mt-1 font-semibold">{{ $wName($record->warehouse_id) }}</dd></div>
                @if($record->destination_id)<div><dt class="text-xs text-slate-500">إلى مستودع</dt><dd class="mt-1 font-semibold">{{ $wName($record->destination_id) }}</dd></div>@endif
                <div class="sm:col-span-2 lg:col-span-1"><dt class="text-xs text-slate-500">السبب / ملاحظات المستند</dt><dd class="mt-1">{{ $record->reason }}</dd></div>
                <div><dt class="text-xs text-slate-500">المنشئ</dt><dd class="mt-1"><x-erp.user-name :id="$record->created_by" /></dd></div>
                @if($record->approved_by)<div><dt class="text-xs text-slate-500">المعتمد</dt><dd class="mt-1"><x-erp.user-name :id="$record->approved_by" />@if($record->approved_at) · <time class="text-xs text-slate-500">{{ \Carbon\CarbonImmutable::parse($record->approved_at)->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</time>@endif</dd></div>@endif
                @if($record->posted_at)<div><dt class="text-xs text-slate-500">{{ $kind==='count' ? 'وقت الاعتماد والإغلاق' : 'وقت الترحيل' }}</dt><dd class="mt-1"><time>{{ \Carbon\CarbonImmutable::parse($record->posted_at)->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</time></dd></div>@endif
            </dl>
        </x-erp.section-card>

        <x-erp.section-card title="بنود المستند" icon="cube">
            <x-ui.responsive-table label="بنود المستند">
                <table role="table" class="w-full text-start text-sm">
                    <thead role="rowgroup"><tr role="row">
                        <th role="columnheader">الصنف</th>
                        <th role="columnheader">الوحدة</th>
                        <th role="columnheader">{{ $qtyLabel }}</th>
                        @if($kind==='count')<th role="columnheader">الدفتري وقت الجرد</th><th role="columnheader">الفارق</th>@endif
                        @if($kind==='adjustment')<th role="columnheader">التكلفة / المرجع</th>@endif
                        @if($hasLineSerials)<th role="columnheader">الأرقام المسلسلة</th>@endif
                    </tr></thead>
                    <tbody role="rowgroup">
                    @foreach($lines as $line)
                        @php($lineSerials = json_decode($line->serials ?? '[]', true))
                        <tr role="row" wire:key="readonly-line-{{ $line->id }}">
                            <td role="cell" data-label="الصنف"><div><strong>{{ $line->product_name }}</strong><small class="block text-slate-500"><bdi>{{ $line->sku }}</bdi></small></div></td>
                            <td role="cell" data-label="الوحدة"><div>{{ $line->unit_name }}</div></td>
                            <td role="cell" data-label="{{ $qtyLabel }}"><div class="font-semibold tabular-nums" dir="ltr">{{ D::qty($line->quantity) }}</div></td>
                            @if($kind==='count')
                                @php($diff = $line->recorded !== null ? bcsub($line->quantity, $line->recorded, 8) : null)
                                <td role="cell" data-label="الدفتري وقت الجرد"><div class="tabular-nums">{{ $line->recorded !== null ? D::qty($line->recorded) : '—' }}</div></td>
                                <td role="cell" data-label="الفارق"><div dir="ltr" class="font-semibold tabular-nums {{ $diff === null || bccomp($diff,'0',8) === 0 ? '' : (bccomp($diff,'0',8) > 0 ? 'text-emerald-700' : 'text-amber-700') }}">{{ $diff !== null ? (bccomp($diff,'0',8) > 0 ? '+' : '').D::qty($diff) : '—' }}</div></td>
                            @endif
                            @if($kind==='adjustment')
                                <td role="cell" data-label="التكلفة / المرجع"><div>@if($line->unit_cost !== null)<span class="tabular-nums">{{ D::qty($line->unit_cost) }} ج.م</span>@if($line->cost_reference)<small class="block text-slate-500">{{ $line->cost_reference }}</small>@endif @else<span class="text-slate-400">—</span>@endif</div></td>
                            @endif
                            @if($hasLineSerials)
                                <td role="cell" data-label="الأرقام المسلسلة"><div class="flex flex-wrap gap-1">@forelse($lineSerials as $serial)<span class="inventory-badge !ms-0" dir="ltr">{{ $serial }}</span>@empty<span class="text-slate-400">—</span>@endforelse</div></td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-ui.responsive-table>
        </x-erp.section-card>
    @endif

    @if($showReceiving)@include('inventory.transfer-receiving')@endif

    @if($record)<x-erp.section-card title="سجل المستند" icon="clock"><ol class="divide-y divide-slate-100">@forelse($history as $event)<li class="flex flex-wrap justify-between gap-3 py-3 text-sm"><strong>{{ ['saved'=>'حفظ المسودة','submit'=>'إرسال للاعتماد','approve'=>'اعتماد','post'=>'ترحيل','dispatch'=>'إرسال التحويل','receive'=>'استلام التحويل','cancel'=>'إلغاء'][$event['action']] ?? $event['action'] }}</strong><span><x-erp.user-name :id="$event['actor_id']" /></span><time>{{ \Carbon\CarbonImmutable::parse($event['occurred_at'])->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</time></li>@empty<li class="p-4 text-sm text-slate-500">لا توجد أحداث مسجلة لهذا المستند.</li>@endforelse</ol></x-erp.section-card>@endif
</div>
