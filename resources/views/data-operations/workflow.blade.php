@php
    $entityLabel = data_get($definition ?? [], 'label', $entityLabel ?? $entity ?? 'البيانات');
    $columns = data_get($definition ?? [], 'columns', $columns ?? []);
    $isBulk = ($run?->kind ?? null) === 'bulk';
    $summary = (array) ($run?->summary ?? []);
    $importMode = data_get($run ?? [], 'mode', $mode ?? 'create');
    $items = $run?->items ?? collect();
    $receiptCounts = $items->countBy('status');
    $createdCount = $items->where('status', 'succeeded')->where('result.operation', 'create')->count();
    $displaySummary = ($run?->status ?? null) === 'processing' ? [
        'total' => $items->count(), 'pending' => $receiptCounts->get('pending', 0),
        'succeeded' => $receiptCounts->get('succeeded', 0), 'failed' => $receiptCounts->get('failed', 0) + $receiptCounts->get('invalid', 0),
        'skipped' => $receiptCounts->get('skipped', 0), 'created' => $createdCount,
        'updated' => $receiptCounts->get('succeeded', 0) - $createdCount,
    ] : $summary;
    $statusLabels = [
        'preview' => 'معاينة', 'processing' => 'قيد التنفيذ', 'completed' => 'مكتمل', 'failed' => 'متعذر',
        'valid' => 'صالح', 'invalid' => 'يحتاج تصحيحًا', 'created' => 'سيُنشأ', 'updated' => 'سيُحدّث',
        'pending' => 'بانتظار التنفيذ', 'succeeded' => 'تم', 'skipped' => 'تم التجاوز',
    ];
@endphp

<div @class(['space-y-6','party-ui' => in_array($entity ?? '',['customers','suppliers']), 'supplier-ui'=>($entity ?? '') === 'suppliers']) dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" @if (($run?->status ?? null) === 'processing') wire:poll.3s @endif>
    <x-page.heading :title="$isBulk ? 'التعديل الجماعي · '.$entityLabel : 'استيراد '.$entityLabel" description="راجع الصفوف والنتائج قبل تطبيق أي تغيير." :breadcrumbs="[$entityLabel, $isBulk ? 'التعديل الجماعي' : 'الاستيراد']" />

    @if (session('status'))<flux:callout variant="success">{{ session('status') }}</flux:callout>@endif
    <flux:error name="upload" />
    <flux:error name="file" />

    @if (! $run && in_array($entity,['customers','suppliers']))
        @include('parties.partials.import')
    @elseif (! $run)
        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.panel>
                <div class="flex items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700"><flux:icon name="document-arrow-down" class="size-5" /></span>
                    <div><flux:heading size="lg">١. تنزيل القالب</flux:heading><p class="mt-1 text-sm text-slate-500">استخدم القالب الخاص بـ{{ $entityLabel }} حتى تبقى أسماء الحقول معتمدة.</p></div>
                </div>
                @if ($canTemplate)
                    <flux:button type="button" variant="primary" icon="arrow-down-tray" class="mt-5" wire:click="downloadTemplate">تحميل قالب Excel</flux:button>
                    <p class="mt-2 text-xs text-slate-500">إصدار القالب: {{ data_get($definition, 'version') }}</p>
                @else
                    <flux:callout class="mt-5">لا تتوفر صلاحية تنزيل القالب.</flux:callout>
                @endif
            </x-ui.panel>

            <x-ui.panel>
                <div class="flex items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg {{ $templateDownloaded ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}"><flux:icon name="arrow-up-tray" class="size-5" /></span>
                    <div><flux:heading size="lg">٢. رفع الملف ومعاينته</flux:heading><p class="mt-1 text-sm text-slate-500">تُعرض نتيجة كل صف قبل اعتماد الاستيراد.</p></div>
                </div>

                @if ($canImport)
                    <form wire:submit="previewImport" class="mt-5 space-y-4">
                        @if ($templateDownloaded)
                            <flux:input type="file" wire:model="upload" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" label="ملف XLSX" required />
                            <flux:select wire:model.live="mode" label="طريقة الاستيراد" required>
                                <option value="create">إنشاء سجلات جديدة فقط</option>
                                <option value="update">تحديث السجلات المطابقة فقط</option>
                            </flux:select>
                            <p class="text-xs text-slate-500">اختر وضعًا واحدًا لكل ملف. لن تُدمج عمليتا الإنشاء والتحديث تلقائيًا.</p>
                        @else
                            <flux:callout color="blue">نزّل القالب أولًا لفتح رفع الملف.</flux:callout>
                        @endif
                        <flux:button type="submit" variant="primary" icon="eye" :disabled="! $templateDownloaded || ! $upload" wire:loading.attr="disabled" wire:target="upload,previewImport">معاينة الملف</flux:button>
                        <flux:text size="sm" wire:loading wire:target="upload">جارٍ رفع الملف...</flux:text>
                    </form>
                @else
                    <flux:callout class="mt-5">لا تتوفر صلاحية استيراد {{ $entityLabel }}.</flux:callout>
                @endif
            </x-ui.panel>
        </div>
    @else
        <dl class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm sm:grid-cols-3">
            <div><dt class="text-slate-500">المستخدم المنفذ</dt><dd>{{ auth()->user()->name }} (#{{ $run->actor_id }})</dd></div>
            <div><dt class="text-slate-500">الملف / إصدار القالب</dt><dd>{{ $run->filename ?: 'تعديل جماعي' }} · {{ $run->template_version }}</dd></div>
            <div><dt class="text-slate-500">صفوف مكررة</dt><dd>{{ $summary['duplicates'] ?? 0 }}</dd></div>
            <div><dt class="text-slate-500">وقت المعاينة</dt><dd>{{ $run->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</dd></div>
            <div><dt class="text-slate-500">بدء التنفيذ</dt><dd>{{ $run->started_at?->timezone('Africa/Cairo')->format('Y-m-d H:i') ?: 'لم يبدأ' }}</dd></div>
            <div><dt class="text-slate-500">انتهاء التنفيذ</dt><dd>{{ $run->completed_at?->timezone('Africa/Cairo')->format('Y-m-d H:i') ?: '—' }}</dd></div>
        </dl>
        <x-ui.panel>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <flux:heading size="lg">{{ $isBulk ? 'معاينة التغييرات' : 'معاينة الاستيراد' }}</flux:heading>
                    <p class="mt-1 text-sm text-slate-500">{{ $isBulk ? 'تغييرات الأصناف المحددة فقط.' : 'وضع الملف: '.($importMode === 'update' ? 'تحديث المطابق فقط' : 'إنشاء الجديد فقط') }}</p>
                </div>
                <span @class(['rounded-full px-3 py-1 text-xs font-semibold', 'bg-blue-50 text-blue-700' => $run->status === 'preview', 'bg-amber-50 text-amber-800' => $run->status === 'processing', 'bg-emerald-50 text-emerald-800' => $run->status === 'completed', 'bg-rose-50 text-rose-700' => $run->status === 'failed'])>{{ $statusLabels[$run->status] ?? $run->status }}</span>
            </div>

            @if ($run->status === 'processing')
                <flux:callout color="blue" class="mt-4">يجري التنفيذ. ستتحدث الحالة والنتائج من إيصالات الخادم.</flux:callout>
            @elseif ($run->status === 'completed')
                <flux:callout variant="success" class="mt-4">اكتمل التنفيذ. الأعداد أدناه مأخوذة من نتائج المعالجة المسجلة.</flux:callout>
            @elseif ($run->status === 'failed')
                <flux:callout variant="danger" class="mt-4">تعذر إكمال العملية. راجع تفاصيل الصفوف والنتيجة المسجلة.</flux:callout>
            @else
                <flux:callout color="blue" class="mt-4">هذه معاينة فقط. لن تُحفظ التغييرات قبل التأكيد الصريح.</flux:callout>
            @endif

            @php($summaryKeys = $run->status === 'preview' ? ['total' => 'إجمالي الصفوف', 'valid' => 'صالحة', 'invalid' => 'تحتاج تصحيحًا', 'created' => 'ستُنشأ', 'updated' => 'ستُحدّث'] : ['total' => 'إجمالي الصفوف', 'pending' => 'بانتظار التنفيذ', 'succeeded' => 'ناجحة', 'failed' => 'فاشلة', 'skipped' => 'متجاوزة', 'created' => 'أُنشئت', 'updated' => 'حُدّثت'])
            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ($summaryKeys as $key => $label)
                    @if (array_key_exists($key, $displaySummary))
                        <x-erp.stat-card :label="$label" :value="$displaySummary[$key]" icon="rectangle-stack" tone="blue" />
                    @endif
                @endforeach
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                @if ($run->status === 'preview' && ($summary['valid'] ?? 0) > 0)
                    <flux:button type="button" variant="primary" icon="check" wire:click="execute" wire:confirm="سيتم تطبيق الصفوف الصالحة فقط. هل تريد المتابعة؟">تأكيد وتطبيق</flux:button>
                @endif
                @if (($summary['invalid'] ?? 0) > 0 || ($summary['failed'] ?? 0) > 0)
                    <flux:button type="button" variant="outline" icon="arrow-down-tray" wire:click="downloadErrors">تنزيل صفوف الأخطاء</flux:button>
                @endif
                @if ($run->status === 'completed')
                    <flux:button type="button" variant="outline" icon="arrow-down-tray" wire:click="downloadResult">تنزيل النتيجة</flux:button>
                @endif
            </div>
        </x-ui.panel>

        <x-ui.panel class="!p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-start text-sm">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="p-4 text-start">الصف</th>
                            <th class="p-4 text-start">المعرّف</th>
                            @foreach ($columns as $key => $column)
                                @php($columnKey = is_array($column) ? ($column['key'] ?? $key) : ($column->key ?? $key))
                                @php($columnLabel = is_array($column) ? ($column['label'] ?? $columnKey) : ($column->label ?? $columnKey))
                                <th class="p-4 text-start">{{ $columnLabel }}</th>
                            @endforeach
                            <th class="p-4 text-start">الحالة</th>
                            <th class="p-4 text-start">التفاصيل</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($items as $item)
                            <tr wire:key="operation-row-{{ $item->id ?? $item->row_number }}">
                                <td class="p-4 tabular-nums">{{ $item->row_number }}</td>
                                <td class="p-4"><bdi>{{ $item->identifier ?: '—' }}</bdi></td>
                                @foreach ($columns as $key => $column)
                                    @php($columnKey = is_array($column) ? ($column['key'] ?? $key) : ($column->key ?? $key))
                                    @php($before = data_get($item, 'before_values', data_get($item, 'before', [])))
                                    @php($after = data_get($item, 'after_values', data_get($item, 'after', [])))
                                    @php($before = data_get($before, $columnKey))
                                    @php($after = data_get($after, $columnKey))
                                    <td class="p-4">
                                        <span class="block text-xs text-slate-500">قبل: {{ $before === null || $before === '' ? '—' : (is_scalar($before) ? $before : json_encode($before, JSON_UNESCAPED_UNICODE)) }}</span>
                                        <span class="mt-1 block">بعد: {{ $after === null || $after === '' ? '—' : (is_scalar($after) ? $after : json_encode($after, JSON_UNESCAPED_UNICODE)) }}</span>
                                    </td>
                                @endforeach
                                <td class="p-4"><span @class(['rounded-full px-2 py-1 text-xs font-medium', 'bg-emerald-50 text-emerald-800' => in_array($item->status, ['valid', 'created', 'updated', 'succeeded'], true), 'bg-rose-50 text-rose-700' => in_array($item->status, ['invalid', 'failed'], true), 'bg-amber-50 text-amber-800' => $item->status === 'skipped', 'bg-slate-100 text-slate-700' => ! in_array($item->status, ['valid', 'created', 'updated', 'succeeded', 'invalid', 'failed', 'skipped'], true)])>{{ $statusLabels[$item->status] ?? $item->status }}</span></td>
                                <td class="max-w-72 whitespace-normal p-4 text-xs text-rose-700">{{ $item->error ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($columns) + 4 }}" class="p-8"><x-ui.empty-state title="لا توجد صفوف لعرضها" description="لم تُسجّل صفوف لهذه المعاينة أو العملية." icon="document-magnifying-glass" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.panel>
    @endif
</div>
