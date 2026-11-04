<div class="space-y-4">
    <x-page.heading title="مرتجعات المشتريات" description="المرتجع يرتبط باستلام فعلي ومرجع صرف مخزني وتسوية المورد" :breadcrumbs="['المشتريات', 'مرتجعات المشتريات']">
        <x-slot:actions>
            @if($canCreate && ! $formOpen)<flux:button variant="primary" icon="plus" wire:click="openForm" wire:loading.attr="disabled" wire:target="openForm">مرتجع جديد</flux:button>@endif
        </x-slot:actions>
    </x-page.heading>

    @if(session('status'))
        <flux:callout variant="success" class="erp-feedback--success" role="status">{{ session('status') }}</flux:callout>
    @endif
    @foreach(['form', 'return', 'inventory'] as $errorKey)
        @error($errorKey)
            <flux:callout variant="danger" role="alert">{{ $message }}</flux:callout>
        @enderror
    @endforeach

    <x-ui.panel class="erp-filters !p-4">
        <flux:select wire:model.live="status" label="الحالة" class="md:max-w-xs">
            <option value="">كل الحالات</option>
            @foreach($statuses as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
        </flux:select>
    </x-ui.panel>

    @if($formOpen)
        <x-erp.section-card title="مرتجع مشتريات جديد" icon="arrow-uturn-left">
            <form wire:submit="save" class="space-y-5" aria-label="نموذج مرتجع مشتريات جديد">
                <flux:select wire:model.live="receiptId" label="الاستلام المرحّل">
                    <option value="">اختر استلامًا مرحّلًا</option>
                    @foreach($sources as $source)<option value="{{ $source['id'] }}">{{ $source['label'] }}</option>@endforeach
                </flux:select>
                <flux:error name="receipt_id" />

                @if($sources === [])
                    <x-ui.empty-state title="لا يوجد استلام مرحّل مؤهل للمرتجع" description="يحتاج المرتجع استلامًا مرحّلًا بكمية مفوترة وغير مرتجعة." icon="truck" />
                @endif

                @if($receiptLines !== [])
                    <x-ui.responsive-table label="بنود الاستلام المختار">
                        <table class="w-full min-w-[720px] text-sm">
                            <thead class="bg-slate-50 text-xs text-slate-600">
                                <tr>
                                    <th scope="col" class="px-3 py-2 text-start">الصنف</th>
                                    <th scope="col" class="px-3 py-2 text-start">المستلم</th>
                                    <th scope="col" class="px-3 py-2 text-start">المتاح للإرجاع</th>
                                    <th scope="col" class="px-3 py-2 text-start">الكمية المرتجعة</th>
                                    <th scope="col" class="px-3 py-2 text-start">الأرقام المسلسلة</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($receiptLines as $index => $line)
                                    <tr wire:key="purchase-return-line-{{ $line['receipt_line_id'] }}" class="align-top">
                                        <td data-label="الصنف" class="px-3 py-2">{{ $line['description'] }}</td>
                                        <td data-label="المستلم" class="px-3 py-2 tabular-nums"><bdi>{{ $line['received'] }}</bdi></td>
                                        <td data-label="المتاح للإرجاع" class="px-3 py-2 tabular-nums"><bdi>{{ $line['returnable'] }}</bdi></td>
                                        <td data-label="الكمية المرتجعة" class="px-3 py-2">
                                            @if($line['serials'] !== [])
                                                <span class="tabular-nums">{{ count($lines[$index]['serials'] ?? []) }}</span>
                                                <span class="text-xs text-slate-500">حسب الأرقام المختارة</span>
                                            @else
                                                <flux:input wire:model="lines.{{ $index }}.quantity" type="text" inputmode="decimal" dir="ltr" aria-label="كمية {{ $line['description'] }}" />
                                            @endif
                                            <flux:error name="lines.{{ $index }}.quantity" />
                                        </td>
                                        <td data-label="الأرقام المسلسلة" class="px-3 py-2">
                                            @if($line['serials'] !== [])
                                                <fieldset class="grid gap-1">
                                                    <legend class="mb-1 text-xs text-slate-600">اختر الأرقام المسلسلة المرتجعة</legend>
                                                    @foreach($line['serials'] as $serial)
                                                        <label class="inline-flex items-center gap-2">
                                                            <input type="checkbox" value="{{ $serial }}" wire:model.live="lines.{{ $index }}.serials" class="rounded border-slate-300 text-blue-600">
                                                            <bdi class="tabular-nums">{{ $serial }}</bdi>
                                                        </label>
                                                    @endforeach
                                                </fieldset>
                                                <flux:error name="lines.{{ $index }}.serials" />
                                            @else
                                                <span class="text-xs text-slate-500">بدون أرقام مسلسلة</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.responsive-table>
                @endif
                <flux:error name="lines" />

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="space-y-1">
                        <flux:select wire:model="reasonCode" label="سبب المرتجع">
                            <option value="">اختر السبب</option>
                            @foreach($reasons as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                        </flux:select>
                        <flux:error name="reason_code" />
                    </div>
                    <div class="space-y-1 md:col-span-2">
                        <flux:textarea wire:model="reason" label="تفاصيل المرتجع" rows="3" required />
                        <flux:error name="reason" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <flux:button type="button" wire:click="closeForm" wire:loading.attr="disabled">إلغاء</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">حفظ كمسودة</span>
                        <span wire:loading wire:target="save">جارٍ الحفظ…</span>
                    </flux:button>
                </div>
            </form>
            <p class="mt-4 text-xs leading-6 text-slate-500">الكمية المتاحة هي المفوتر غير المرتجع. البضاعة غير المفوترة تحتاج إصدار الفاتورة أولًا.</p>
        </x-erp.section-card>
    @endif

    <x-erp.section-card title="قائمة مرتجعات المشتريات" icon="arrow-uturn-left" class="!p-0">
        <x-ui.responsive-table label="قائمة مرتجعات المشتريات">
            <table class="w-full min-w-[900px] text-sm">
                <thead class="bg-slate-50 text-xs text-slate-600">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-start">رقم المرتجع</th>
                        <th scope="col" class="px-4 py-3 text-start">المصدر</th>
                        <th scope="col" class="px-4 py-3 text-start">المورد</th>
                        <th scope="col" class="px-4 py-3 text-start">الحالة</th>
                        <th scope="col" class="px-4 py-3 text-start">السبب</th>
                        <th scope="col" class="px-4 py-3 text-start">التاريخ</th>
                        <th scope="col" class="px-4 py-3 text-start">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($rows as $row)
                        @php
                            $notes = json_decode($row['credit_notes'] ?? 'null', true) ?: [];
                            $badge = match ($row['status']) { 'posted' => 'green', 'rejected' => 'red', 'posting' => 'amber', default => 'zinc' };
                            $isOwnDraft = (int) $row['created_by'] === (int) auth()->id();
                        @endphp
                        <tr wire:key="purchase-return-{{ $row['id'] }}" class="align-top hover:bg-slate-50/70">
                            <td data-label="رقم المرتجع" class="px-4 py-3 font-semibold">
                                <bdi>{{ $row['official_number'] ?: '#'.$row['id'] }}</bdi>
                                @if($notes !== [])<div class="mt-1 text-xs font-normal text-slate-600">إشعار دائن: <bdi>{{ implode('، ', array_column($notes, 'number')) }}</bdi></div>@endif
                            </td>
                            <td data-label="المصدر" class="px-4 py-3">استلام <bdi>{{ $row['receipt_number'] }}</bdi></td>
                            <td data-label="المورد" class="px-4 py-3">{{ $row['supplier_name'] }}</td>
                            <td data-label="الحالة" class="px-4 py-3">
                                <flux:badge size="sm" :color="$badge">{{ $statuses[$row['status']] ?? $row['status'] }}</flux:badge>
                                @if($row['status'] === 'rejected' && $row['rejection_reason'])<div class="mt-1 text-xs text-slate-500">{{ $row['rejection_reason'] }}</div>@endif
                            </td>
                            <td data-label="السبب" class="px-4 py-3">{{ $reasons[$row['reason_code']] ?? $row['reason_code'] }}</td>
                            <td data-label="التاريخ" class="px-4 py-3 tabular-nums"><bdi>{{ \Carbon\Carbon::parse($row['created_at'], 'UTC')->timezone('Africa/Cairo')->format('Y/m/d') }}</bdi></td>
                            <td data-label="الإجراءات" class="px-4 py-3">
                                @if($row['status'] === 'draft' && $canApprove)
                                    @if($isOwnDraft)
                                        <span class="text-xs text-slate-500">يعتمده مستخدم آخر</span>
                                    @elseif((int) $rejectingId === (int) $row['id'])
                                        <div class="grid min-w-56 gap-2">
                                            <flux:input wire:model="rejectReason" label="سبب الرفض" />
                                            <flux:error name="rejectReason" />
                                            <div class="flex flex-wrap gap-2">
                                                <flux:button size="sm" variant="danger" wire:click="reject({{ $row['id'] }})" wire:loading.attr="disabled">تأكيد الرفض</flux:button>
                                                <flux:button size="sm" variant="ghost" wire:click="cancelReject" wire:loading.attr="disabled">إلغاء</flux:button>
                                            </div>
                                        </div>
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            <flux:button size="sm" variant="primary" wire:click="approve({{ $row['id'] }})" wire:confirm="سيتم ترحيل حركة المخزون وإشعار التصحيح ولا يمكن التراجع. هل تريد المتابعة؟" wire:loading.attr="disabled">اعتماد وترحيل</flux:button>
                                            <flux:button size="sm" variant="ghost" wire:click="startReject({{ $row['id'] }})" wire:loading.attr="disabled">رفض</flux:button>
                                        </div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-8">
                                <x-ui.empty-state title="لا توجد مرتجعات مشتريات" description="غيّر فلتر الحالة أو أنشئ مرتجعًا من استلام مرحّل." icon="arrow-uturn-left">
                                    @if($canCreate && ! $formOpen)<flux:button variant="primary" wire:click="openForm">مرتجع جديد</flux:button>@endif
                                </x-ui.empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.responsive-table>
    </x-erp.section-card>
</div>
