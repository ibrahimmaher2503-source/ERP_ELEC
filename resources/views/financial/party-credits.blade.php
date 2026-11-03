<div class="space-y-5" data-erp-archetype="detail">
    <x-page.heading title="رد الأرصدة الدائنة" description="رد فعلي مرتبط برصيد إشعار دائن مرحّل، مع الاحتفاظ بالمصدر والتاريخ والقيد" />
    @if(session('status'))<flux:callout role="status" variant="success">{{ session('status') }}</flux:callout>@endif
    <x-ui.validation-summary />
    <x-ui.panel><div class="grid gap-4 sm:grid-cols-3">
        <flux:select label="نوع الطرف" wire:model.live="kind"><option value="customer">عميل — صرف من الخزينة</option><option value="supplier">مورد — استرداد إلى الخزينة</option></flux:select>
        <flux:select label="الفرع" wire:model.live="branch"><option value="">اختر الفرع</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
        <flux:select label="الرصيد الدائن الفعلي" wire:model.live="creditId"><option value="">اختر الرصيد</option>@foreach($credits as $c)@if((int)$c->branch_id===(int)$branch)<option value="{{ $c->id }}">#{{ $c->id }} · طرف #{{ $c->party_id }} · متبقٍ {{ $c->remaining_amount }} EGP</option>@endif@endforeach</flux:select>
    </div></x-ui.panel>
    @if($selected)<x-ui.panel><flux:heading level="2">الرصيد #{{ $selected->id }}</flux:heading><flux:text class="mt-2">إشعار #{{ $selected->credit_note_id }} · الأصل {{ $selected->original_amount }} EGP · المتبقي {{ $selected->remaining_amount }} EGP</flux:text>
        @if($canRefund && bccomp($selected->remaining_amount,'0',2)>0)<form wire:submit="refund" class="mt-5 grid gap-4 sm:grid-cols-2">
            <flux:select label="حساب البنك أو النقدية" wire:model="accountId" required><option value="">اختر الحساب</option>@foreach($accounts as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }}</option>@endforeach</flux:select>
            <flux:input label="المبلغ EGP" inputmode="decimal" wire:model="amount" required /><flux:input type="date" label="تاريخ الرد الفعلي" wire:model="date" required /><flux:input label="سبب ومرجع الرد" wire:model="reference" required />
            <div class="sm:col-span-2"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:confirm="تنفيذ رد فعلي وترحيل قيد الخزينة المرتبط؟">{{ $kind==='customer'?'صرف للعميل':'استرداد من المورد' }}</flux:button></div>
        </form>@endif
    </x-ui.panel><x-ui.panel><flux:heading level="2">الردود المرحلة</flux:heading><div class="mt-4 space-y-3">@forelse($refunds as $r)<p>{{ $r->refund_date }} · {{ $r->amount }} EGP · حركة #{{ $r->movement_id }} @if($canRefund)<flux:button :href="route('treasury.movements.pdf',$r->movement_id)" size="sm">سند PDF</flux:button>@endif</p>@empty<flux:text>لم يُنفذ رد من هذا الرصيد.</flux:text>@endforelse</div></x-ui.panel>
    @else<x-ui.empty-state title="اختر رصيدًا دائنًا من نطاقك" description="يظهر الرصيد فقط من إشعار مرحّل يتجاوز الجزء غير المسدد من الفاتورة الأصلية." />@endif
</div>
