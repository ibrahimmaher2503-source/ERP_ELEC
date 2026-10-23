<div class="space-y-5">
    <x-page.heading :title="$doc ? 'تفاصيل طلب الاحتياج #'.$doc->id : 'طلب احتياج جديد'" description="الكمية بوحدة الصنف الأساسية. لا ينتج عن الطلب حركة مخزون أو أمر شراء تلقائي." :breadcrumbs="['المشتريات','طلبات الاحتياج']"><x-slot:actions>@if($doc && $doc->status==='approved' && app(\App\Modules\Purchasing\Application\PurchasingAccess::class)->allows(auth()->id(),'rfq_create',$doc->branch_id))<flux:button variant="primary" icon="document-text" :href="route('purchasing.rfqs.create',['requisition'=>$doc->id])" wire:navigate>طلب عروض لهذا الاحتياج</flux:button>@endif<flux:button :href="route('purchasing.requisitions.index')" wire:navigate icon="arrow-right">العودة</flux:button></x-slot:actions></x-page.heading>
    @if($errors->any())<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if($doc)<div class="rounded-xl border border-blue-100 bg-blue-50 p-4 text-blue-900">الحالة: {{ ['draft'=>'مسودة','submitted'=>'بانتظار الاعتماد','approved'=>'معتمد','rejected'=>'مرفوض'][$doc->status] }} · النسخة {{ $doc->version }} · مرجع داخلي وليس رقمًا رسميًا</div>@endif
    <form wire:submit="save" class="space-y-5">
        <x-ui.panel><h2 class="mb-5 flex items-center gap-2 font-bold text-blue-900"><flux:icon.clipboard-document-list class="size-5" />بيانات الاحتياج</h2>
            <fieldset @disabled(!$editable) class="grid gap-4 md:grid-cols-3">
                <flux:select label="الفرع" wire:model="form.branch_id" required :disabled="(bool)$doc"><option value="">اختر الفرع</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select>
                <flux:input type="date" label="تاريخ الطلب" wire:model="form.request_date" required />
                <flux:input type="date" label="التاريخ المطلوب" wire:model="form.required_date" />
                <flux:input label="القسم الطالب" wire:model="form.department" />
                <div class="md:col-span-2"><flux:textarea rows="2" label="سبب الاحتياج" wire:model="form.purpose" required /></div>
                <div class="md:col-span-3"><flux:textarea rows="2" label="ملاحظات" wire:model="form.notes" /></div>
            </fieldset>
        </x-ui.panel>
        <x-ui.panel><div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 class="font-bold text-blue-900">أصناف طلب الاحتياج</h2>@if($editable)<flux:button type="button" icon="plus" wire:click="addLine">إضافة صنف</flux:button>@endif</div>
            @if($editable)<flux:input wire:model.live.debounce.300ms="productSearch" label="البحث في دليل الأصناف" placeholder="اسم الصنف أو SKU" />@endif
            <div class="mt-4 space-y-3">
            @foreach($form['lines'] as $i=>$line)<div wire:key="req-line-{{ $i }}" class="grid items-end gap-3 rounded-xl border border-blue-100 bg-slate-50 p-4 md:grid-cols-12">
                @if($editable)
                <div class="md:col-span-4"><flux:select label="الصنف — الوحدة الأساسية" wire:model="form.lines.{{ $i }}.product_id"><option value="">اختر الصنف</option>@php($selected = $doc?->lines->firstWhere('product_id',(int)$line['product_id']))@if($selected && !collect($products)->contains('id',$selected->product_id))<option value="{{ $selected->product_id }}">{{ $selected->sku }} — {{ $selected->product_name }}</option>@endif @foreach($products as $p)<option value="{{ $p['id'] }}">{{ $p['sku'] }} — {{ $p['name'] }}</option>@endforeach</flux:select></div>
                <div class="md:col-span-2"><flux:input label="الكمية" inputmode="decimal" wire:model="form.lines.{{ $i }}.quantity" /></div>
                <div class="md:col-span-2"><flux:input type="date" label="تاريخ الاحتياج" wire:model="form.lines.{{ $i }}.required_date" /></div>
                <div class="md:col-span-3"><flux:input label="ملاحظات البند" wire:model="form.lines.{{ $i }}.notes" /></div>
                <flux:button type="button" aria-label="حذف البند" icon="trash" variant="danger" wire:click="removeLine({{ $i }})" />
                @else
                <p class="md:col-span-5">{{ $doc->lines->firstWhere('product_id',(int)$line['product_id'])?->product_name }}</p><p class="md:col-span-2">{{ $line['quantity'] }} وحدة أساسية</p><p class="md:col-span-2">{{ $line['required_date'] }}</p><p class="md:col-span-3">{{ $line['notes'] }}</p>
                @endif
            </div>@endforeach
            </div>
            @if($editable)<div class="mt-5"><flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">حفظ المسودة</flux:button></div>@endif
        </x-ui.panel>
    </form>
    @if($doc)<x-ui.panel><h2 class="mb-4 font-bold text-teal-800">المراجعة والاعتماد</h2><div class="flex flex-wrap items-end gap-3">
        @if($doc->status === 'draft' && $doc->created_by === auth()->id() && app(\App\Modules\Purchasing\Application\PurchasingAccess::class)->allows(auth()->id(),'requisition_submit',$doc->branch_id))<flux:button variant="primary" wire:click="applyAction('submit')" wire:loading.attr="disabled">إرسال للمراجعة</flux:button>@endif
        @if($doc->status === 'submitted' && $doc->created_by !== auth()->id() && app(\App\Modules\Purchasing\Application\PurchasingAccess::class)->allows(auth()->id(),'requisition_approve',$doc->branch_id))<flux:input wire:model="reviewReason" label="سبب القرار (مطلوب للرفض)" /><flux:button variant="primary" wire:click="applyAction('approve')" wire:loading.attr="disabled">اعتماد الطلب</flux:button><flux:button variant="danger" wire:click="applyAction('reject')" wire:loading.attr="disabled">رفض</flux:button>@endif
        @if($doc->status === 'rejected' && $doc->created_by === auth()->id() && app(\App\Modules\Purchasing\Application\PurchasingAccess::class)->allows(auth()->id(),'requisition_update',$doc->branch_id))<flux:button wire:click="applyAction('reopen')">إعادة للمسودة</flux:button>@endif
        @if($doc->status === 'submitted' && $doc->created_by === auth()->id())<p class="text-sm text-amber-800">ينتظر اعتماد مستخدم آخر يملك الصلاحية ونطاق الفرع.</p>@endif
    </div><div class="mt-5 divide-y divide-slate-100">@forelse($history as $entry)<div class="py-3 text-sm">{{ ['submit'=>'إرسال للمراجعة','approve'=>'اعتماد','reject'=>'رفض','reopen'=>'إعادة للمسودة'][$entry->action] }} · مستخدم #{{ $entry->actor_id }} · {{ $entry->created_at }}<p>{{ $entry->reason }}</p></div>@empty<p class="text-sm text-slate-500">لا توجد قرارات مراجعة بعد.</p>@endforelse</div></x-ui.panel>@endif
</div>
