@php
    $compact = $compact ?? false;
    $workflowProgress = $progress ?? app(\App\Modules\Sales\Application\SalesOrderProgress::class)->read(auth()->id(), $record);
    $reservationLabel = ['not_requested' => 'لم يطلب حجز', 'reserved' => 'محجوز', 'backorder' => 'طلب مؤجل', 'fulfilled' => 'نفذ الحجز', 'restricted' => 'الحجز محجوب بالنطاق'][$workflowProgress['reservation']];
    $deliveryLabel = ['not_started' => 'لم يبدأ التسليم', 'partial' => 'تسليم جزئي', 'fulfilled' => 'مسلم بالكامل'][$workflowProgress['delivery']];
@endphp
<div @class(['flex flex-wrap items-center gap-1.5', 'text-[11px]' => $compact, 'text-xs' => !$compact])>
    @include('sales.orders.partials.status', ['status' => $record->status])
    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600"><flux:icon name="cube" class="size-3.5" />{{ $reservationLabel }}</span>
    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600"><flux:icon name="truck" class="size-3.5" />{{ $deliveryLabel }}</span>
    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600"><flux:icon name="document-text" class="size-3.5" />{{ ['restricted' => 'الفواتير محجوبة بالصلاحية', 'none' => 'لا توجد فاتورة', 'requested' => 'فاتورة قيد الإعداد', 'invoiced' => 'صدرت فاتورة'][$workflowProgress['invoicing']] }}</span>
</div>
