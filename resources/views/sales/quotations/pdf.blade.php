<div class="quotation-page space-y-4">
    @include('sales.quotations.partials.detail-header', ['heading' => 'معاينة عرض السعر (PDF)'])
    @include('sales.orders.partials.feedback')
    <x-ui.panel>
        @if($officialIssue)
            <h2 class="mb-4 font-bold">الإصدار الرسمي {{ $record->number }}</h2>
            <p class="mb-4">الملف المحفوظ ثابت ويعرض بيانات الإصدار الأصلي.</p>
            @if($can('document_view'))<flux:button :href="route('quotations.official-pdf', $record->id)" icon="arrow-down-tray">تنزيل PDF الرسمي</flux:button>@endif
        @else
            <h2 class="mb-4 font-bold">إصدار عرض السعر الرسمي</h2>
            <p class="mb-4 text-sm">يتحقق الإصدار من السعر والسياسة التجارية وقواعد الضريبة وهوية الشركة. يحفظ رقمًا ولقطة مبالغ وملف PDF ثابتًا.</p>
            <ul class="mb-4 list-inside list-disc space-y-2 text-sm">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
            <flux:error name="readiness" />
            <flux:error name="form" />
            @if($can('issue'))<flux:button wire:click="operation('issue')" variant="primary" wire:loading.attr="disabled" icon="document-check">إصدار عرض السعر الرسمي</flux:button>@endif
        @endif
    </x-ui.panel>
</div>
