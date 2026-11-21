<x-erp.section-card title="استلام التحويل وتسوية الكميات" icon="truck">
    <x-ui.responsive-table label="ملخص كميات التحويل">
        <table role="table" class="w-full text-start text-sm">
            <thead role="rowgroup"><tr role="row"><th role="columnheader">الصنف</th><th role="columnheader">المرسل</th><th role="columnheader">مستلم سليم</th><th role="columnheader">مستلم تالف</th><th role="columnheader">مفقود غير محلول</th><th role="columnheader">متبقٍ لم يصل</th></tr></thead>
            <tbody role="rowgroup">
            @foreach($transferQuantities as $entry)
                <tr role="row" wire:key="transfer-qty-{{ $entry['line_id'] }}">
                    <td role="cell" data-label="الصنف"><div>{{ $lines->firstWhere('id',$entry['line_id'])?->product_name }}</div></td>
                    @foreach(['quantity'=>'المرسل','accepted'=>'مستلم سليم','damaged'=>'مستلم تالف','missing'=>'مفقود غير محلول','outstanding'=>'متبقٍ لم يصل'] as $column=>$colLabel)
                        <td role="cell" data-label="{{ $colLabel }}"><div class="tabular-nums">{{ \App\Modules\Inventory\Presentation\InventoryDisplay::qty($entry[$column]) }}</div></td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-ui.responsive-table>
    <p class="mt-3 text-sm text-slate-600">التالف ضمن الرصيد الفعلي ومعزول عن المتاح. المفقود لا يضاف إلى المستودع ولا يشطب؛ يبقى التزام نقل حتى وصوله. التسوية المالية للفروقات تنتظر منتج المحاسبة.</p>
    @if($record->status==='dispatched' && $access->allows(auth()->id(),'transfer_receive',$record->destination_id) && $access->allows(auth()->id(),'transfer_receive',$record->warehouse_id))
    <h3 class="mt-5 text-sm font-bold text-slate-800">استلام جزئي أو تسجيل تالف / مفقود</h3>
    <form wire:submit="receivePartial" class="mt-3 space-y-4">
      @foreach($receiptLines as $i=>$entry)
      @php($lineSerials = json_decode($lines->firstWhere('id',$entry['line_id'])?->serials ?? '[]', true))
      <fieldset wire:key="partial-receipt-{{ $entry['line_id'] }}" class="rounded-xl border border-blue-100 p-4"><legend class="px-2 text-sm font-semibold text-slate-800">{{ $lines->firstWhere('id',$entry['line_id'])?->product_name }}</legend><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <flux:input label="سليم وصل الآن" wire:model="receiptLines.{{ $i }}.accepted" inputmode="decimal" />
      <flux:input label="تالف وصل الآن" wire:model="receiptLines.{{ $i }}.damaged" inputmode="decimal" />
      <flux:input label="بلاغ فقد جديد" wire:model="receiptLines.{{ $i }}.missing" inputmode="decimal" />
      <flux:input label="من الواصل الآن: استرداد فقد سابق" wire:model="receiptLines.{{ $i }}.recovered_missing" inputmode="decimal" />
      @if($lineSerials !== [])
      <flux:textarea label="مسلسلات السليم — كل رقم في سطر" wire:model="receiptLines.{{ $i }}.accepted_serials" rows="2" />
      <flux:textarea label="مسلسلات التالف — كل رقم في سطر" wire:model="receiptLines.{{ $i }}.damaged_serials" rows="2" />
      @endif
      </div></fieldset>@endforeach
      <flux:textarea label="سبب الفرق أو استرداد المفقود" wire:model="receiptReason" rows="2" />
      <flux:textarea label="مرجع الأدلة المتاحة (اختياري)" wire:model="receiptEvidence" rows="2" />
      <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:confirm="ترحيل الكميات المستلمة وتسجيل الفروقات؟ لا يمكن تعديل الحدث بعد تسجيله.">تسجيل دفعة الاستلام</flux:button>
    </form>
    @endif
    <ol class="mt-4 divide-y divide-slate-100">@forelse($transferReceipts as $receipt)<li class="py-3 text-sm">دفعة #{{ $receipt->id }} · <x-erp.user-name :id="$receipt->actor_id" /> · {{ \Carbon\CarbonImmutable::parse($receipt->occurred_at)->timezone('Africa/Cairo')->format('Y-m-d H:i') }}@if($receipt->reason)<p class="mt-1 text-slate-700">{{ $receipt->reason }}</p>@endif @if($receipt->evidence)<p class="text-xs text-slate-500">{{ $receipt->evidence }}</p>@endif</li>@empty<li class="py-3 text-sm text-slate-500">لم تسجل دفعات استلام بعد.</li>@endforelse</ol>
</x-erp.section-card>
