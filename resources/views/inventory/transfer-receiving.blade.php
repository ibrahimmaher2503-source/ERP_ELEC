<x-erp.section-card title="استلام التحويل وتسوية الكميات">
    <div class="overflow-x-auto"><table class="w-full text-start text-sm"><thead class="bg-blue-50"><tr><th class="p-2">الصنف</th><th>المرسل</th><th>مستلم سليم</th><th>مستلم تالف</th><th>مفقود غير محلول</th><th>متبقٍ لم يصل</th></tr></thead><tbody>
    @foreach($transferQuantities as $entry)<tr><td class="p-2">{{ $lines->firstWhere('id',$entry['line_id'])?->product_name }}</td>@foreach(['quantity','accepted','damaged','missing','outstanding'] as $column)<td>{{ $entry[$column] }}</td>@endforeach</tr>@endforeach
    </tbody></table></div>
    <p class="mt-3 text-sm text-slate-600">التالف ضمن الرصيد الفعلي ومعزول عن المتاح. المفقود لا يضاف إلى المستودع ولا يشطب؛ يبقى التزام نقل حتى وصوله. التسوية المالية للفروقات تنتظر منتج المحاسبة.</p>
    @if($record->status==='dispatched' && $access->allows(auth()->id(),'transfer_receive',$record->destination_id))
    <form wire:submit="receivePartial" class="mt-4 space-y-4">
      @foreach($receiptLines as $i=>$entry)<fieldset wire:key="partial-receipt-{{ $entry['line_id'] }}" class="rounded-xl border border-blue-100 p-4"><legend>{{ $lines->firstWhere('id',$entry['line_id'])?->product_name }}</legend><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <flux:input label="سليم وصل الآن" wire:model="receiptLines.{{ $i }}.accepted" inputmode="decimal" />
      <flux:input label="تالف وصل الآن" wire:model="receiptLines.{{ $i }}.damaged" inputmode="decimal" />
      <flux:input label="بلاغ فقد جديد" wire:model="receiptLines.{{ $i }}.missing" inputmode="decimal" />
      <flux:input label="من الواصل الآن: استرداد فقد سابق" wire:model="receiptLines.{{ $i }}.recovered_missing" inputmode="decimal" />
      <flux:textarea label="مسلسلات السليم — كل رقم في سطر" wire:model="receiptLines.{{ $i }}.accepted_serials" rows="2" />
      <flux:textarea label="مسلسلات التالف — كل رقم في سطر" wire:model="receiptLines.{{ $i }}.damaged_serials" rows="2" />
      </div></fieldset>@endforeach
      <flux:textarea label="سبب الفرق أو استرداد المفقود" wire:model="receiptReason" rows="2" />
      <flux:textarea label="مرجع الأدلة المتاحة (اختياري)" wire:model="receiptEvidence" rows="2" />
      <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:confirm="ترحيل الكميات المستلمة وتسجيل الفروقات؟ لا يمكن تعديل الحدث بعد تسجيله.">تسجيل دفعة الاستلام</flux:button>
    </form>
    @endif
    <ol class="mt-4 divide-y divide-slate-100">@forelse($transferReceipts as $receipt)<li class="py-3 text-sm">دفعة #{{ $receipt->id }} · مستخدم #{{ $receipt->actor_id }} · {{ \Carbon\CarbonImmutable::parse($receipt->occurred_at)->timezone('Africa/Cairo')->format('Y-m-d H:i') }}<p>{{ $receipt->reason }}</p><p>{{ $receipt->evidence }}</p></li>@empty<li class="py-3 text-sm text-slate-500">لم تسجل دفعات استلام بعد.</li>@endforelse</ol>
</x-erp.section-card>
