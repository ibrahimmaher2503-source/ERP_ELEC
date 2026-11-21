@php
    use App\Modules\Sales\Presentation\SalesOrderDisplay as D;
    $linesById = $record->lines->keyBy('id');
@endphp
<div class="space-y-5">
    @if($record->status === 'approved')
        <x-erp.section-card title="التخصيص والتسليم بالوحدة الأساسية" icon="truck" class="!p-0">
            <div class="border-b border-slate-200 bg-slate-50/70 px-5 py-3 text-xs text-slate-600">
                الطلب المؤجل يحتاج مخزونًا متاحًا قبل تخصيصه. إنشاء طلب التسليم لا يصرف المخزون؛ التأكيد يرحّل الصرف بآلية FIFO وقيد التكلفة.
            </div>
            <x-ui.responsive-table label="تقدم الحجز والتسليم للبنود">
                <table role="table" class="w-full min-w-[820px] text-sm">
                    <thead role="rowgroup" class="text-end text-xs text-slate-600">
                        <tr role="row">
                            <th role="columnheader" scope="col" class="px-4 py-3">الصنف</th>
                            <th role="columnheader" scope="col" class="px-4 py-3">مستودع الحجز</th>
                            <th role="columnheader" scope="col" class="px-4 py-3">الطلب</th>
                            <th role="columnheader" scope="col" class="px-4 py-3">المسلَّم</th>
                            <th role="columnheader" scope="col" class="px-4 py-3">الحجز المتبقي</th>
                            <th role="columnheader" scope="col" class="px-4 py-3">المؤجل</th>
                            <th role="columnheader" scope="col" class="px-4 py-3">كمية التسليم</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="divide-y divide-slate-100">
                        @foreach($record->lines as $line)
                            @php
                                $state = $progress['lines'][$line->id] ?? null;
                                $reqNum = (string) ($state['base_quantity'] ?? '0');
                                $delNum = (string) ($state['delivered'] ?? '0');
                                $pct = $state && bccomp($reqNum, '0', 8) > 0
                                    ? min(100, (int) floor((float) bcdiv(bcmul($delNum, '100', 4), $reqNum, 2)))
                                    : 0;
                                $wId = (int) ($state['warehouse_id'] ?? 0);
                            @endphp
                            <tr role="row" wire:key="delivery-line-{{ $line->id }}" class="hover:bg-slate-50/70">
                                <td role="cell" data-label="الصنف" class="px-4 py-3">
                                    <span class="font-medium text-slate-900">{{ $line->description }}</span>
                                    <span class="mt-0.5 block text-[11px] text-slate-500"><bdi>{{ $line->product_snapshot['sku'] ?? '—' }}</bdi></span>
                                </td>
                                <td role="cell" data-label="مستودع الحجز" class="px-4 py-3 text-slate-600">{{ $wId ? ($warehouseNames[$wId] ?? '#'.$wId) : '—' }}</td>
                                <td role="cell" data-label="الطلب" class="px-4 py-3 tabular-nums"><bdi>{{ $state ? D::qty((string) $state['base_quantity']) : '—' }}</bdi></td>
                                <td role="cell" data-label="المسلَّم" class="px-4 py-3 tabular-nums">
                                    <div><bdi>{{ $state ? D::qty((string) $state['delivered']) : '—' }}</bdi></div>
                                    <div class="orders-progress mt-1.5" aria-hidden="true">
                                        <div class="orders-progress-bar {{ $pct >= 100 ? 'is-done' : '' }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                </td>
                                <td role="cell" data-label="الحجز المتبقي" class="px-4 py-3 tabular-nums">
                                    @if($state && $state['outstanding'] !== null)
                                        <bdi>{{ D::qty((string) $state['outstanding']) }}</bdi>
                                    @else
                                        <span class="text-xs text-slate-500">يلزم نطاق عرض الحجز</span>
                                    @endif
                                </td>
                                <td role="cell" data-label="المؤجل" class="px-4 py-3 tabular-nums">
                                    @if($state && $state['backorder'] !== null)
                                        @if(bccomp((string) $state['backorder'], '0', 8) > 0)
                                            <span class="orders-badge" data-size="sm" data-tone="warning"><bdi>{{ D::qty((string) $state['backorder']) }}</bdi></span>
                                        @else
                                            <span>0</span>
                                        @endif
                                    @else
                                        <span class="text-xs text-slate-500">يلزم نطاق عرض الحجز</span>
                                    @endif
                                </td>
                                <td role="cell" data-label="كمية التسليم" class="px-4 py-3">
                                    @if($can('delivery_create') && $state && $state['outstanding'] !== null && bccomp((string) $state['outstanding'], '0', 8) > 0)
                                        <flux:input wire:model="deliveryQuantities.{{ $line->id }}" :aria-label="'كمية تسليم '.$line->description" inputmode="decimal" />
                                    @elseif($state && $state['outstanding'] !== null && bccomp((string) $state['outstanding'], '0', 8) === 0)
                                        <span class="text-xs text-slate-500">لا يوجد متاح</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.responsive-table>

            @if($can('delivery_create'))
                <div class="space-y-4 border-t border-slate-200 p-5">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-64 flex-1">
                            <flux:input wire:model="deliveryReference" label="مرجع التسليم والجهة المستلمة" placeholder="مثال: إذن استلام موقع رقم 14 — م. طارق" />
                        </div>
                        <flux:button type="button" wire:click="fillAvailableDeliveries" wire:loading.attr="disabled">تعبئة كامل الحجز المتاح</flux:button>
                        <flux:button type="button" wire:click="createDelivery" wire:loading.attr="disabled" variant="primary" icon="plus">إنشاء طلب تسليم</flux:button>
                    </div>

                    @php $hasBackorder = collect($record->lines)->contains(fn ($l) => ($progress['lines'][$l->id]['backorder'] ?? null) !== null && bccomp((string) $progress['lines'][$l->id]['backorder'], '0', 8) > 0); @endphp
                    @if($hasBackorder)
                        <div class="border-t border-slate-200 pt-4">
                            <h3 class="mb-3 text-sm font-bold text-slate-800">تخصيص المخزون المتاح للطلبات المؤجلة</h3>
                            <div class="grid gap-3 md:grid-cols-2">
                                @foreach($record->lines as $line)
                                    @php $state = $progress['lines'][$line->id] ?? null; @endphp
                                    @if($state && $state['backorder'] !== null && bccomp((string) $state['backorder'], '0', 8) > 0)
                                        <div class="flex items-end gap-2 rounded-lg border border-amber-200 bg-amber-50/40 p-3">
                                            <div class="flex-1">
                                                <flux:input wire:model="backorderQuantities.{{ $line->id }}" :label="'تخصيص المؤجل: '.$line->description" inputmode="decimal" />
                                            </div>
                                            <flux:button type="button" wire:click="allocateBackorder({{ $line->id }})" wire:loading.attr="disabled">تخصيص المتاح</flux:button>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </x-erp.section-card>

        <x-erp.section-card :title="'طلبات التسليم ('.$deliveryInstructions->count().')'" icon="clipboard-document-list">
            <div class="space-y-3">
                @forelse($deliveryInstructions as $instruction)
                    @php
                        $instLines = $deliveryLinesByInstruction->get($instruction->id, collect());
                        $isConfirmed = $instruction->status === 'confirmed';
                    @endphp
                    <div wire:key="delivery-instruction-{{ $instruction->id }}" class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="text-slate-900">طلب تسليم #{{ $instruction->id }}</strong>
                                    <span class="orders-badge" data-size="sm" data-tone="{{ $isConfirmed ? 'success' : 'warning' }}">
                                        {{ $isConfirmed ? 'تسليم مؤكد ومُرحّل' : 'مسودة تسليم' }}
                                    </span>
                                    <span class="text-xs text-slate-500"><bdi>{{ \Carbon\CarbonImmutable::parse($instruction->created_at)->timezone('Africa/Cairo')->format('Y/m/d H:i') }}</bdi></span>
                                </div>
                                <p class="mt-1 text-sm text-slate-700">{{ $instruction->reference }}</p>
                                @if($instLines->isNotEmpty())
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-600">
                                        @foreach($instLines as $il)
                                            @php $ol = $linesById->get((int) $il->line_id); @endphp
                                            <span class="rounded bg-slate-100 px-2 py-0.5">{{ $ol?->description ?? ('بند #'.$il->line_id) }}: <strong class="tabular-nums"><bdi>{{ D::qty((string) $il->quantity) }}</bdi></strong></span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if($instruction->status === 'draft' && $can('delivery_confirm'))
                                    <flux:button wire:click="confirmDelivery({{ $instruction->id }})" wire:confirm="سيتم تأكيد التسليم وصرف المخزون بآلية FIFO وترحيل قيد التكلفة ولا يمكن التراجع. هل تريد المتابعة؟" wire:loading.attr="disabled" variant="primary" size="sm" icon="check">تأكيد التسليم والترحيل</flux:button>
                                @endif
                                @if($isConfirmed && $can('export'))
                                    <flux:button :href="route('sales-orders.delivery-pdf', ['order' => $record->id, 'instruction' => $instruction->id])" icon="arrow-down-tray" size="sm">إذن التسليم PDF</flux:button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state title="لا توجد طلبات تسليم بعد" description="حدد كميات التسليم المتاحة وأدخل مرجع الاستلام أعلاه لإنشاء طلب تسليم." icon="truck" />
                @endforelse
            </div>
        </x-erp.section-card>
    @else
        <x-ui.empty-state title="لا يوجد تسليم مؤهل" description="اعتمد أمر البيع وسجّل حجز المخزون من تبويب المراجعة أولًا." icon="truck" />
    @endif
</div>
