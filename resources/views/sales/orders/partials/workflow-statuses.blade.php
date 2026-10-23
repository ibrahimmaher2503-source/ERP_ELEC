@php($compact = $compact ?? false)
<div @class(['flex flex-wrap items-center gap-1.5', 'text-[11px]' => $compact, 'text-xs' => ! $compact])>
    @include('sales.orders.partials.status', ['status' => $record->status])
    <span title="حالة الحجز: غير مطلوب" aria-label="حالة الحجز: غير مطلوب" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600"><flux:icon name="cube" class="size-3.5" />الحجز غير مطلوب</span>
    <span title="حالة التسليم: لم يبدأ" aria-label="حالة التسليم: لم يبدأ" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600"><flux:icon name="truck" class="size-3.5" />التسليم لم يبدأ</span>
    <span title="حالة الفوترة: غير مطلوبة" aria-label="حالة الفوترة: غير مطلوبة" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600"><flux:icon name="document-text" class="size-3.5" />الفوترة غير مطلوبة</span>
</div>
