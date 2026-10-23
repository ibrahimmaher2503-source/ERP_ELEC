<div class="quote-identity grid gap-3 md:grid-cols-3 xl:grid-cols-6">
 <x-ui.panel class="!p-3"><span>العميل</span><strong>{{ $record->customer_snapshot['name'] }}</strong></x-ui.panel>
 <x-ui.panel class="!p-3"><span>المشروع / المرجع</span><strong>{{ $record->project_name ?: $record->rfq_reference ?: 'غير محدد' }}</strong></x-ui.panel>
 <x-ui.panel class="!p-3"><span>تاريخ العرض</span><strong><bdi>{{ $record->quotation_date->format('Y/m/d') }}</bdi></strong></x-ui.panel>
 <x-ui.panel class="!p-3"><span>انتهاء الصلاحية</span><strong><bdi>{{ $record->valid_until?->format('Y/m/d') ?? 'غير محدد' }}</bdi></strong></x-ui.panel>
 <x-ui.panel class="!p-3"><span>حالة التحويل</span><strong>لم يتم التحويل</strong></x-ui.panel>
 <x-ui.panel class="!p-3"><span>حالة العرض</span><strong><x-sales.quotation-status :status="$record->status" /></strong></x-ui.panel>
</div>
