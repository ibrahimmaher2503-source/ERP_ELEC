<div data-erp-archetype="{{ $editing ? 'form' : 'details' }}" class="workshop-ui space-y-5">
    <x-page.heading :title="$i?'استلام معدة '.$i->number:'استلام معدة جديد'" description="01 · تسجيل المعدة وحالتها، ثم تأكيد العهدة وإحالتها للإصلاح" :breadcrumbs="['الورش','استلام المعدات']"><x-slot:actions><flux:button :href="route('workshops.intakes')" wire:navigate icon="arrow-right">سجل المعدات</flux:button></x-slot:actions></x-page.heading>
    @include('workshops.feedback')
    @if($i)<div class="flex flex-wrap items-center gap-3">@include('workshops.status',['status'=>$i->status])<span class="text-sm text-slate-500">{{ $i->customer_name }} · الإصدار {{ $version }}</span></div>@endif
    @if($editing)
    <form wire:submit="save" class="space-y-5">
        <x-ui.panel>
            <div class="workshop-section-heading"><div><p class="workshop-eyebrow">بيانات الاستلام</p><h2>المالك والفرع</h2><p>عهدة العميل مستقلة عن مخزون الشركة. إصلاح معدات الشركة يحتاج مصدر مخزون فعلي.</p></div></div>
            <div class="grid gap-4 sm:grid-cols-2"><flux:select label="الفرع" wire:model.live="form.branch_id" :disabled="(bool)$i"><option value="">اختر الفرع</option>@foreach($branches as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</flux:select><div class="self-end pb-3"><flux:checkbox label="معدة مملوكة للعميل" wire:model.live="form.customer_owned" :disabled="(bool)$i"/></div></div>
            @if($form['customer_owned'])
                <div class="mt-5 grid gap-4 sm:grid-cols-2"><flux:select label="العميل" wire:model.live="form.customer_id" :disabled="(bool)$i" search-model="customerSearch"><option value="">اختر العميل</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->code }} · {{ $c->name }}</option>@endforeach</flux:select><flux:select label="الموقع" wire:model="form.site_id"><option value="">غير محدد</option>@foreach($context['sites'] as $s)<option value="{{ $s['id'] }}">{{ $s['label'] }}</option>@endforeach</flux:select><flux:select label="جهة الاتصال" wire:model="form.contact_id"><option value="">غير محددة</option>@foreach($context['contacts'] as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach</flux:select></div>
            @else
                <div class="mt-5 space-y-4">
                    @if($i)<p class="workshop-hint">مصدر المعدة محفوظ: {{ $i->company_serial_id ? 'مسلسل مخزون #'.$i->company_serial_id : 'استلام مخزون #'.$i->company_movement_id }}. هوية المصدر لا تتغير بعد إنشاء المسودة.</p>@else

                    <flux:select label="المعدة من المخزون" wire:model.live="companyEquipment" search-model="productSearch"><option value="">اختر مسلسلًا متاحًا أو استلامًا فعليًا</option>@foreach($equipmentOptions as $equipment)<option value="{{ $equipment['value'] }}">{{ $equipment['label'] }}</option>@endforeach</flux:select>
                    @if(!$equipmentAvailable)<p class="workshop-hint">اختيار معدات الشركة يحتاج صلاحية عزل المخزون ومستودعًا مسموحًا في هذا الفرع.</p>@elseif($form['branch_id']&&!$equipmentOptions)<p class="workshop-hint">لا توجد معدات مطابقة في نطاقك. غيّر البحث أو راجع مصدر المعدة في المخزون.</p>@endif
                    @endif
                    <p class="text-sm text-slate-500">تتحقق إتاحة المعدة وتعزل عند تأكيد الاستلام. للمعدة الكمية، أدخل كودها الفعلي المميز في الرقم المسلسل.</p>
                </div>
            @endif
        </x-ui.panel>
        <x-ui.panel>
            <div class="workshop-section-heading"><div><p class="workshop-eyebrow">هوية المعدة</p><h2>ما الذي استلمته الورشة؟</h2></div></div>
            <flux:textarea label="وصف المعدة" wire:model="form.equipment_description"/>
            <div class="mt-4 grid gap-4 sm:grid-cols-2"><flux:input label="الموديل" wire:model="form.model"/><flux:input label="الرقم المسلسل" wire:model="form.serial_number"/></div>
            @if($form['customer_owned'])<details class="mt-5"><summary class="cursor-pointer text-sm font-semibold text-blue-700">ربط بصنف أو مشروع (اختياري)</summary><div class="mt-4 grid gap-4 sm:grid-cols-2"><flux:select label="الصنف المرجعي" wire:model="form.product_id" search-model="productSearch"><option value="">غير محدد</option>@foreach($products as $p)<option value="{{ $p['id'] }}">{{ $p['sku'] }} · {{ $p['name'] }}</option>@endforeach</flux:select><flux:select label="المشروع" wire:model="form.project_id" :disabled="(bool)$i"><option value="">بدون مشروع</option>@foreach($projects as $p)<option value="{{ $p['id'] }}">{{ $p['code'] }} · {{ $p['name'] }}</option>@endforeach</flux:select></div></details>@endif
        </x-ui.panel>
        <x-ui.panel>
            <div class="workshop-section-heading"><div><p class="workshop-eyebrow">حالة العهدة</p><h2>العطل والملحقات عند الوصول</h2></div></div>
            <div class="grid gap-4 sm:grid-cols-2"><flux:textarea label="العطل المبلغ" wire:model="form.reported_fault"/><flux:textarea label="الحالة عند الوصول" wire:model="form.arrival_condition"/><flux:textarea label="الملحقات المستلمة" wire:model="form.accessories"/><flux:input label="موقع العهدة" wire:model="form.location"/></div>
            @if($form['customer_owned'])<details class="mt-5"><summary class="cursor-pointer text-sm font-semibold text-blue-700">إحالة من الصيانة الميدانية (إن وجدت)</summary><p class="my-3 text-sm text-slate-500">تستخدم مراجع الإحالة الأصلية لأمر الصيانة والزيارة. لا تكتب رقمًا بديلًا عند عدم وجود إحالة.</p><div class="grid gap-4 sm:grid-cols-2"><flux:input label="مرجع أمر الصيانة الميدانية" wire:model="form.maintenance_work_order_id" :disabled="(bool)$i"/><flux:input label="مرجع الزيارة المحيلة" wire:model="form.maintenance_visit_id" :disabled="(bool)$i"/></div></details>@endif
        </x-ui.panel>
        <div class="workshop-form-actions"><p class="text-sm text-slate-500">حفظ المسودة لا يؤكد الاستلام الفعلي. بعد الحفظ، سجّل وقت الاستلام وإقرار العهدة.</p><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ $i?'حفظ تعديلات الاستلام':'حفظ مسودة الاستلام والمتابعة' }}</flux:button></div>
    </form>
    @else
        <x-ui.panel><div class="workshop-section-heading"><div><h2>المعدة المستلمة</h2><p>{{ $i->customer_owned?'عهدة عميل':'معدة شركة من مصدر مخزون فعلي' }}</p></div></div><dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">@foreach(['customer_name'=>'العميل / المالك','equipment_description'=>'المعدة','model'=>'الموديل','serial_number'=>'المسلسل','accessories'=>'الملحقات','reported_fault'=>'العطل','arrival_condition'=>'الحالة عند الوصول','location'=>'العهدة الحالية'] as $field=>$label)<div><dt>{{ $label }}</dt><dd>{{ $i->$field ?: 'غير محدد' }}</dd></div>@endforeach</dl></x-ui.panel>
    @endif
    @if($i&&$i->status==='draft'&&$access->allows(auth()->id(),$i->branch_id,'custody'))
        <x-ui.panel><form wire:submit="receive" class="space-y-4"><div class="workshop-section-heading"><div><h2>تأكيد الاستلام الفعلي</h2><p>يثبت وقت دخول المعدة للورشة وإقرار صاحب العهدة. احفظ أي تعديلات على المسودة أولًا.</p></div></div><flux:input type="datetime-local" label="وقت الاستلام بالقاهرة" wire:model="receivedAt"/><flux:textarea label="دليل إقرار العميل" wire:model="acknowledgment"/><flux:button type="submit" variant="primary" wire:key="workshop-receive" wire:loading.attr="disabled">تأكيد استلام العهدة</flux:button></form></x-ui.panel>
    @endif
    @if($i&&$i->status==='received'&&$access->allows(auth()->id(),$i->branch_id,'job_manage'))
        <x-ui.panel><form wire:submit="createJob" class="space-y-5"><div class="workshop-section-heading"><div><p class="workshop-eyebrow">الخطوة التالية</p><h2>إحالة المعدة للفحص والإصلاح</h2><p>اختر الورشة ومسار الإصلاح. الفني يعيّن في أمر الإصلاح التالي.</p></div></div><div class="grid gap-4 sm:grid-cols-3"><flux:select label="الورشة" wire:model="workshopId"><option value="">اختر الورشة</option>@foreach($workshops as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</flux:select><flux:select label="الأولوية" wire:model="priority">@foreach(['low'=>'منخفضة','normal'=>'عادية','high'=>'عالية','urgent'=>'عاجلة'] as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</flux:select>
        @if($i->customer_owned)<flux:select label="نوع الفوترة" wire:model.live="billingMode"><option value="billable">إصلاح مدفوع</option><option value="partial">مدفوع جزئيًا</option><option value="warranty">إصلاح ضمان</option><option value="nonbillable">مجاملة معتمدة</option><option value="contract">ضمن عقد خدمة</option></flux:select>@else<p class="workshop-hint">إصلاح داخلي لمعدة الشركة، دون فاتورة عميل.</p>@endif</div>
        @if(!$workshops->count())<p class="workshop-hint">لا توجد ورشة نشطة لهذا الفرع. راجع إعداد الورش قبل إنشاء أمر الإصلاح.</p>@endif
        @if($i->customer_owned&&$billingMode==='contract')<div class="grid gap-4 sm:grid-cols-2"><flux:select label="العقد المغطي" wire:model="contractId"><option value="">اختر عقد العميل النشط</option>@foreach($contracts as $contract)<option value="{{ $contract['id'] }}">{{ $contract['title'] }} · {{ $contract['starts_on'] }} / {{ $contract['ends_on'] }}</option>@endforeach</flux:select><flux:input type="date" label="تاريخ تنفيذ الخدمة" wire:model="serviceDate"/></div><p class="workshop-hint">{{ !$contractsAvailable?'عرض العقود يحتاج صلاحية استخدام التغطية ونطاق فرع العقد.':(!$contracts?'لا يوجد عقد نشط لهذا العميل في هذا الفرع.':'اختيار العقد لا يثبت التغطية؛ يتحقق النظام من المعدة والموقع والتاريخ والاستحقاق عند إنشاء الأمر.') }}</p>@endif
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">إنشاء أمر الإصلاح والمتابعة</flux:button></form></x-ui.panel>
    @endif
    @include('workshops.intake-history')
</div>
