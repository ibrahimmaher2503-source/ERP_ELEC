<div data-erp-archetype="form" class="quotation-page space-y-4">
    @include('sales.quotations.partials.detail-header', ['heading' => 'قبول العميل'])
    @include('sales.quotations.partials.identity')
    @include('sales.orders.partials.feedback')
    @if($acceptanceRecord)
        <x-ui.panel>
            <h2 class="font-bold">قبول مسجل للإصدار {{ $record->number }}</h2>
            <p>{{ $acceptanceRecord->accepted_on }} · {{ $acceptanceRecord->reference }}</p>
            <p class="text-sm text-slate-500">الإثبات المكتوب محفوظ بصورة ثابتة ومرتبط بهذا الإصدار.</p>
            @if($can('document_view'))<flux:button class="mt-3" :href="route('quotations.acceptance-evidence', $record->id)" icon="arrow-down-tray">تنزيل إثبات القبول</flux:button>@endif
        </x-ui.panel>
    @elseif($officialIssue && $can('accept'))
        <form wire:submit="accept" class="space-y-4">
            <x-ui.panel>
                <h2 class="mb-4 font-bold">قبول الإصدار {{ $record->number }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:select wire:model="acceptance.evidence_type" label="نوع الإثبات">
                        <option value="purchase_order">أمر شراء من العميل</option>
                        <option value="email">بريد إلكتروني</option>
                        <option value="signed_acceptance">قبول كتابي موقع</option>
                    </flux:select>
                    <flux:input wire:model="acceptance.accepted_on" type="date" label="تاريخ القبول" />
                    <flux:input wire:model="acceptance.reference" label="مرجع قبول العميل" />
                    <flux:input wire:model="acceptanceFile" type="file" label="الإثبات المكتوب، PDF أو صورة" accept="application/pdf,image/png,image/jpeg" />
                </div>
                <flux:error name="file" />
                <flux:error name="readiness" />
            </x-ui.panel>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="accept,acceptanceFile">تسجيل القبول المكتوب</flux:button>
        </form>
    @else
        <x-ui.empty-state title="لم يسجل قبول" description="أصدر عرض السعر الرسمي أولًا. يلزم إثبات مكتوب وصلاحية تسجيل القبول." icon="document-check" />
    @endif
</div>
