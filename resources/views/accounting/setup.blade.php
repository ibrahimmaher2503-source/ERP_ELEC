<div data-erp-archetype="form" class="space-y-6">
    <x-page.heading title="إعداد التفعيل المالي" description="إعداد واحد للشركة · ج.م · لا يترحل أي رصيد قبل المصالحة والاعتماد المستقل" :breadcrumbs="['المحاسبة والمالية', 'إعداد التفعيل المالي']" />
    <nav class="erp-section-nav flex flex-wrap gap-2" aria-label="المحاسبة">@foreach($nav as $destination => $label)<flux:button :href="route('accounting.'.$destination)" :variant="$destination==='setup'?'primary':'outline'" wire:navigate>{{ $label }}</flux:button>@endforeach</nav>
    @if(session('financial_message'))<p role="status" class="rounded border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">{{ session('financial_message') }}</p>@endif
    <x-ui.validation-summary />
    <nav class="erp-form-nav" aria-label="خطوات تجهيز التشغيل المالي"><a href="#financial-configuration">التاريخ والحسابات</a><a href="#financial-taxes">الضريبة</a><a href="#financial-openings">الأرصدة والاعتماد</a></nav>
    <div class="rounded border p-4 {{ $setup['activated_at']?'border-emerald-200 bg-emerald-50':'border-amber-200 bg-amber-50' }}" role="status">
        <h2 class="font-semibold">{{ $setup['activated_at']?'الترحيل المالي مفعل':'الترحيل المالي مغلق' }}</h2>
        <p class="mt-1 text-sm">{{ $setup['activated_at']?'تاريخ التشغيل والافتتاح ثابتان. العمليات العادية قبل تاريخ التشغيل ممنوعة.':'لم يُحدد تاريخ التشغيل أو أرصدة الشركة تلقائيًا. يمكن متابعة إعداد النظام دون تفعيل الإنتاج.' }}</p>
        @if(!$setup['activated_at'])<ul class="mt-3 list-inside list-disc text-sm">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach @if(!$batch || $batch->status!=='approved')<li>حزمة الأرصدة الافتتاحية الحالية لم تُعتمد بعد.</li>@endif</ul>@endif
    </div>

    <x-ui.panel>
        <h2 id="financial-configuration" class="text-lg font-semibold">تاريخ التشغيل والحسابات المطلوبة</h2>
        <p class="mt-2 max-w-3xl text-sm text-slate-600">اختر التاريخ الفعلي المعتمد واربط الحسابات من الدليل. حفظ الإعداد لا يفعّل الترحيل. أي تغيير في الإعداد قبل التفعيل يلزم معه إعداد حزمة افتتاح واعتمادها بالنسخة الجديدة.</p>
        <form wire:submit="saveConfiguration" class="mt-5 space-y-5">
            <div class="max-w-sm"><flux:input wire:model="configuration.go_live_date" type="date" label="تاريخ التشغيل الفعلي للشركة" :disabled="(bool)$setup['activated_at']" /></div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach(\App\Modules\Accounting\Application\FinancialActivation::MAPPINGS as $purpose => [$label,$kind])
                <flux:select wire:model="configuration.mappings.{{ $purpose }}" :label="$label" :disabled="(bool)$setup['activated_at']">
                    <option value="">لم يُربط حساب بعد</option>
                    @foreach($accounts->where('kind',$kind) as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach
                </flux:select>
                @endforeach
            </div>
            @if(!$setup['activated_at'] && $a->allows(auth()->id(),'setup_manage'))<flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ إعداد التشغيل والربط</flux:button>@endif
        </form>
        <p class="mt-3 text-sm"><a class="underline" href="{{ route('accounting.accounts') }}" wire:navigate>دليل الحسابات</a> · <a class="underline" href="{{ route('accounting.periods') }}" wire:navigate>الفترات المالية</a></p>
    </x-ui.panel>

    <x-ui.panel>
        <h2 class="text-lg font-semibold">ربط الدفعات المقدمة من العملاء</h2>
        <p class="mt-2 text-sm text-slate-600">ربط اختياري مستقل يسمح بإثبات التحصيل غير المخصص كالتزام، ولا يغير الإيراد أو الذمم المدينة.</p>
        <form wire:submit="saveCustomerAdvanceMapping" class="mt-4 flex max-w-2xl items-end gap-3">
            <flux:select wire:model="customerAdvanceAccount" label="حساب الدفعات المقدمة" required :disabled="isset($configuration['mappings']['customer_advance'])">
                <option value="">اختر حساب التزام نشطاً</option>
                @foreach($accounts->where('kind','liability') as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach
            </flux:select>
            @if(!isset($configuration['mappings']['customer_advance']) && $a->allows(auth()->id(),'setup_manage'))<flux:button type="submit">حفظ الربط</flux:button>@endif
        </form>
    </x-ui.panel>

    <x-ui.panel>
        <h2 class="text-lg font-semibold">حسابات تكلفة عمل الصيانة</h2>
        <p class="mt-2 text-sm text-slate-600">روابط اختيارية مستقلة لتكلفة العمل المثبتة بمرجع معتمد. لا تُنشئ أجورًا أو ترحيلًا تلقائيًا.</p>
        @foreach(['maintenance_labor'=>['تكلفة عمل الصيانة','expense'],'maintenance_labor_accrual'=>['استحقاق تكلفة الصيانة','liability']] as $purpose=>[$label,$kind])
        <form wire:submit="saveMaintenanceMapping('{{ $purpose }}')" class="mt-4 flex flex-wrap items-end gap-3">
            <flux:select :label="$label" wire:model="maintenanceMappings.{{ $purpose }}" :disabled="isset($configuration['mappings'][$purpose])"><option value="">اختر حساب ترحيل مستقلًا</option>@foreach($accounts->where('kind',$kind) as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</flux:select>
            @if(!isset($configuration['mappings'][$purpose])&&$a->allows(auth()->id(),'setup_manage'))<flux:button type="submit">حفظ الربط</flux:button>@endif
        </form>
        @endforeach
    </x-ui.panel>

    <x-ui.panel>
        <h2 class="text-lg font-semibold">3. إعداد الضرائب</h2>
        <p class="mt-2 max-w-3xl text-sm text-slate-600">لا توجد نسبة عامة تلقائية. القاعدة خاصة بنوع المعاملة، ويمكن تخصيصها لصنف. الخصم التجاري المؤهل يسبق الضريبة. V1 يدعم ضريبة نسبية واحدة أو إعفاء صريح لكل بند؛ المعاملات المركبة أو الثابتة تحتاج قاعدة موثقة إضافية قبل استخدامها.</p>
        <div role="region" tabindex="0" aria-label="جدول تفصيلي؛ يمكن التمرير أفقياً لعرض جميع الأعمدة" class="mt-4 overflow-x-auto"><table><thead><tr><th>الكود</th><th>المعاملة</th><th>الصنف</th><th>النسبة</th><th>السريان</th><th>دقة مستند الضريبة</th><th>المرجع</th></tr></thead><tbody>
            @forelse($taxRules as $rule)<tr><td>{{ $rule->code }} · {{ $rule->name }}</td><td>{{ $rule->transaction_type==='sale'?'بيع':'شراء' }}@if($rule->transaction_type==='purchase')<p class="text-xs">{{ $rule->purchase_recoverable?'قابلة للاسترداد':'غير قابلة للاسترداد' }}</p>@endif</td><td>{{ $rule->product_id ?? 'كل الأصناف غير المخصصة' }}</td><td dir="ltr">{{ $rule->rate }}%</td><td>{{ $rule->withdrawn?'مسحوبة':$rule->starts_on.' / '.($rule->effective_end ?? 'مفتوح') }}</td><td>{{ $rule->tax_scale }}</td><td>{{ $rule->legal_reference }}</td></tr>
            @empty<tr><td colspan="7"><x-ui.empty-state title="لا توجد قواعد ضريبية" description="أدخل القواعد الفعلية بعد التحقق من المعاملة الضريبية لشركتك. لم تُزرع نسب إنتاج تجريبية." /></td></tr>@endforelse
        </tbody></table></div>
        @if($a->allows(auth()->id(),'tax_manage'))
        <div id="financial-taxes" class="erp-workflow-note mt-5"><div><strong>صيغة مصر: السعر العام للقيمة المضافة 14%</strong><p class="erp-form-hint">قالب للمعاملات الخاضعة للسعر العام. راجع الصنف وتاريخ السريان وقابلية خصم المدخلات. المعاملات الخاصة والإعفاءات لها قواعد مستقلة.</p><a class="text-xs underline" href="https://portal.eta.gov.eg/sites/default/files/2026-08/law.no_.149.of_.2026.pdf" target="_blank" rel="noopener noreferrer">مرجع مصلحة الضرائب: المادة 3 المعدلة بالقانون 149 لسنة 2026</a></div><flux:button type="button" wire:click="prepareEgyptVat" wire:loading.attr="disabled">تعبئة نموذج 14% دون حفظ</flux:button></div>
        <form wire:submit="addTaxRule" class="mt-5 space-y-4">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <flux:input wire:model="taxRule.code" label="كود الضريبة" required />
                <flux:input wire:model="taxRule.name" label="وصف المعاملة" required />
                <flux:select wire:model.live="taxRule.transaction_type" label="نوع المعاملة"><option value="sale">بيع</option><option value="purchase">شراء</option></flux:select>
                @if($taxRule['transaction_type']==='purchase')<flux:select wire:model="taxRule.purchase_recoverable" label="قابلية استرداد ضريبة الشراء، وفق المعاملة" required><option value="">حدد المعاملة الفعلية</option><option value="1">قابلة للاسترداد</option><option value="0">غير قابلة للاسترداد، تدخل في تكلفة المخزون</option></flux:select>@endif
                <flux:input wire:model="taxRule.product_id" label="رقم الصنف (فارغ = قاعدة عامة للمعاملة)" description="لا تعمم النسبة إذا كانت أصنافك تشمل معاملة خاصة أو إعفاء." inputmode="numeric" />
                <flux:select wire:model="taxRule.treatment" label="المعاملة"><option value="percentage">نسبة</option><option value="exempt">إعفاء بمرجع صريح</option></flux:select>
                <flux:input wire:model="taxRule.rate" label="النسبة الفعلية %" inputmode="decimal" required />
                <flux:input wire:model="taxRule.starts_on" type="date" label="ساري من" required />
                <flux:input wire:model="taxRule.ends_on" type="date" label="ساري حتى، اختياري" />
                <flux:select wire:model="taxRule.tax_scale" label="دقة مستند الضريبة وفق المرجع"><option value="5">5 خانات</option><option value="2">منزلتان</option></flux:select>
            </div>
            <flux:textarea wire:model="taxRule.legal_reference" rows="2" label="مرجع التحقق الفعلي من القاعدة ومتطلبات تقريب المعاملة" required />
            <flux:button type="submit" wire:loading.attr="disabled">حفظ القاعدة الضريبية</flux:button>
        </form>
        <form wire:submit="endTaxRule" class="mt-6 space-y-3 border-t pt-5"><h3 class="font-semibold">إنهاء قاعدة مع حفظ تاريخها</h3><div class="grid gap-3 md:grid-cols-2"><flux:select wire:model="taxEnd.rule_id" label="القاعدة" required><option value="">اختر القاعدة</option>@foreach($taxRules->whereNull('withdrawn') as $rule)<option value="{{ $rule->id }}">#{{ $rule->id }} · {{ $rule->code }} · {{ $rule->starts_on }}</option>@endforeach</flux:select><flux:input wire:model="taxEnd.ends_on" type="date" label="آخر يوم سريان" /></div><flux:textarea wire:model="taxEnd.reason" rows="2" label="سبب التغيير ومرجع التحقق" required />@if(!$setup['activated_at'])<flux:checkbox wire:model="taxEnd.withdraw" label="سحب قاعدة إعداد غير صحيحة قبل التفعيل" />@endif<flux:button type="submit" wire:loading.attr="disabled">تسجيل نهاية أو سحب القاعدة</flux:button></form>
        @if(!$setup['activated_at'])<form wire:submit="validateTax" class="mt-6 space-y-3 border-t pt-5">
            <flux:textarea wire:model="taxReference" rows="2" label="مرجع تحقق المعاملة الضريبية للشركة من المسؤول المختص" required />
            <flux:button type="submit" wire:confirm="تسجيل أن القواعد الفعلية ومتطلبات التقريب قد تحقق منها المختص؟" wire:loading.attr="disabled">تسجيل التحقق الضريبي</flux:button>
        </form>@endif
        @endif
        <p class="mt-4 text-sm {{ $setup['tax_validated']?'text-emerald-800':'text-amber-800' }}">{{ $setup['tax_validated']?'تم تسجيل التحقق الضريبي: '.$setup['tax_validation_reference']:'لم يُسجل التحقق من القواعد الفعلية. الحساب التجريبي لا يجيز الإصدار أو الترحيل الإنتاجي.' }}</p>
    </x-ui.panel>

    @if(!$setup['activated_at'] && $a->allows(auth()->id(),'opening_manage'))
    <x-ui.panel>
        <h2 id="financial-openings" class="text-lg font-semibold">الأرصدة الافتتاحية والاعتماد</h2>
        <p class="mt-2 max-w-3xl text-sm text-slate-600">GL هو القيد الكامل. الموردون والنقدية والبنوك وFIFO تفاصيل تُصالح معه، ولا تُضاف إلى GL مرة ثانية. لكل طبقة FIFO مرجع مستقل. لا تستورد المعاملات التاريخية التي كوّنت هذه الأرصدة.</p>
        <div class="mt-4 flex flex-wrap items-end gap-3">
            <flux:button wire:click="template" wire:loading.attr="disabled" icon="arrow-down-tray">قالب Excel فارغ</flux:button>
            <flux:input type="file" wire:model="workbook" accept=".xlsx" label="ملف Excel، حتى 5 MB و500 سطر" />
            <flux:button wire:click="importPreview" wire:loading.attr="disabled" wire:target="workbook,importPreview">استيراد ومعاينة</flux:button>
        </div>
        <p class="mt-2 text-xs text-slate-500">حقول المبالغ والتكاليف نصوص عشرية في Excel. تُرفض الصيغ. يُحذف الملف الأصلي بعد القراءة.</p>
        <form wire:submit="previewOpening" class="mt-5 space-y-5">
            <flux:textarea wire:model="evidence" rows="2" label="مرجع كشف الأرصدة الفعلي المعتمد للتحضير" required />
            <flux:checkbox wire:model="noBalances" label="أصرح صراحة بعدم وجود أرصدة افتتاحية، مع مرجع مؤيد واعتماد مستقل" />
            @foreach($lines as $i => $line)
            <fieldset wire:key="opening-line-{{ $creationKey }}-{{ $i }}" class="border-t pt-4">
                <legend class="px-2 text-sm font-semibold">بند {{ $i+1 }}</legend>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <flux:select wire:model.live="lines.{{ $i }}.kind" label="النوع"><option value="gl">قيد GL</option><option value="ap">تفاصيل مورد</option><option value="ar">تفاصيل عميل</option><option value="cash">تفاصيل نقدية</option><option value="bank">تفاصيل بنك</option><option value="inventory">طبقة FIFO</option></flux:select>
                    <flux:input wire:model="lines.{{ $i }}.reference" label="مرجع البند الفريد" required />
                    <flux:select wire:model="lines.{{ $i }}.branch_id" label="الفرع" required><option value="">اختر الفرع</option>@foreach($branches as $branch)<option value="{{ $branch['id'] }}">{{ $branch['name'] }}</option>@endforeach</flux:select>
                    @if($line['kind']==='gl')
                    <flux:select wire:model="lines.{{ $i }}.account_id" label="حساب GL" required><option value="">اختر الحساب</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach</flux:select>
                    <flux:input wire:model="lines.{{ $i }}.debit" label="مدين ج.م" inputmode="decimal" />
                    <flux:input wire:model="lines.{{ $i }}.credit" label="دائن ج.م" inputmode="decimal" />
                    @else
                    <flux:input wire:model="lines.{{ $i }}.amount" label="قيمة تفصيلية ج.م" inputmode="decimal" required />
                    @if($line['kind']==='ap')<flux:input wire:model="lines.{{ $i }}.supplier_id" label="رقم المورد" inputmode="numeric" required />@endif
                    @if($line['kind']==='ar')<flux:input wire:model="lines.{{ $i }}.customer_id" label="رقم العميل" inputmode="numeric" required />@endif
                    @if($line['kind']==='inventory')
                    <flux:input wire:model="lines.{{ $i }}.warehouse_id" label="رقم المستودع" inputmode="numeric" required />
                    <flux:input wire:model="lines.{{ $i }}.product_id" label="رقم الصنف المخزني" inputmode="numeric" required />
                    <flux:input wire:model="lines.{{ $i }}.quantity" label="الكمية بالوحدة الأساسية" inputmode="decimal" required />
                    <flux:input wire:model="lines.{{ $i }}.unit_cost" label="تكلفة الوحدة، حتى 16 خانة عشرية" inputmode="decimal" required />
                    <flux:textarea wire:model="lines.{{ $i }}.serial_text" label="للصنف المسلسل: رقم فعلي لكل وحدة، كل رقم بسطر" rows="3" />
                    @endif
                    @endif
                </div>
                <flux:button wire:click="removeLine({{ $i }})" class="mt-3" size="sm" icon="trash">حذف البند {{ $i+1 }}</flux:button>
            </fieldset>
            @endforeach
            <div class="flex flex-wrap gap-3"><flux:button wire:click="addLine" icon="plus">إضافة بند</flux:button><flux:button type="submit" wire:loading.attr="disabled">معاينة ومصالحة</flux:button></div>
        </form>
        @if($previewReconciliation)
        <div class="mt-5 space-y-3 border-t pt-4" aria-live="polite"><h3 class="font-semibold">معاينة {{ count($preview) }} بند، لم يُحفظ أو يُرحل شيء بعد</h3>
            @foreach($previewReconciliation['errors'] as $message)<p class="text-sm text-amber-800">{{ $message }}</p>@endforeach
            @if($previewReconciliation['balanced'])<p class="text-sm text-emerald-800">قيم GL والتفاصيل متطابقة في المعاينة.</p>@endif
            <flux:button wire:click="saveOpening" variant="primary" wire:loading.attr="disabled">حفظ حزمة افتتاح للمراجعة</flux:button>
        </div>
        @endif
    </x-ui.panel>
    @endif

    <x-ui.panel>
        <h2 class="text-lg font-semibold">5. المصالحة و6. اعتماد الافتتاح</h2>
        @if(!$batch)<div class="mt-4"><x-ui.empty-state title="لا توجد أرصدة افتتاحية مُدخلة" description="لم تُزوّد الشركة بيانات فعلية. أدخل أو استورد كشف الافتتاح ثم اعرض المصالحة وأرسله لمراجع مستقل." /></div>
        @else
        <p class="mt-3 text-sm">حزمة #{{ $batch->id }} · {{ ['draft'=>'مسودة','submitted'=>'بانتظار الاعتماد المستقل','approved'=>'معتمدة','posted'=>'مرحلة'][$batch->status] }} · نسخة الإعداد {{ $batch->configuration_version }} · المرجع: {{ $batch->evidence_reference }}</p>
        @if($batch->configuration_version!==$setup['version'])<p class="mt-3 text-amber-800">تغيرت نسخة الإعداد. يلزم إعداد واعتماد حزمة افتتاح جديدة.</p>@endif
        @if($batch->no_balances)<p class="mt-3 text-sm">تتضمن الحزمة تصريحًا صريحًا بعدم وجود أرصدة افتتاحية.</p>@endif
        <div role="region" tabindex="0" aria-label="جدول تفصيلي؛ يمكن التمرير أفقياً لعرض جميع الأعمدة" class="mt-4 overflow-x-auto"><table><thead><tr><th>النوع</th><th>المرجع</th><th>الفرع</th><th>الحساب / المورد / الصنف</th><th>مدين</th><th>دائن</th><th>القيمة التفصيلية</th><th>الكمية</th><th>تكلفة الوحدة</th></tr></thead><tbody>
        @foreach($savedLines as $line)<tr><td>{{ $line['kind'] }}</td><td>{{ $line['reference'] }}</td><td>{{ $line['branch_id'] }}</td><td>{{ $line['account_id'] ?? $line['supplier_id'] ?? $line['product_id'] ?? '—' }}@if($line['serials'] ?? [])<p class="mt-2 whitespace-pre-line text-xs">أرقام فعلية: {{ implode(', ', $line['serials']) }}</p>@endif</td><td>{{ $line['debit'] }}</td><td>{{ $line['credit'] }}</td><td>{{ $line['amount'] }}</td><td>{{ $line['quantity'] ?? '—' }}</td><td>{{ $line['unit_cost'] ?? '—' }}</td></tr>@endforeach
        </tbody></table></div>
        <div class="mt-4 space-y-2">@foreach($reconciliation['errors'] as $message)<p class="text-sm text-amber-800">{{ $message }}</p>@endforeach @if($reconciliation['balanced'])<p class="text-sm text-emerald-800">GL متوازن، وتفاصيل الموردين والنقدية والبنوك وFIFO متطابقة لكل فرع.</p>@endif</div>
        <div class="mt-4 flex flex-wrap gap-3">
            @if($batch->status==='draft' && $batch->created_by===auth()->id() && $a->allows(auth()->id(),'opening_manage'))<flux:button wire:click="advance({{ $batch->id }},{{ $batch->version }},'submit')" wire:loading.attr="disabled">إرسال حزمة الافتتاح للاعتماد</flux:button>@endif
            @if($batch->status==='submitted' && $batch->created_by!==auth()->id() && $setup['configured_by']!==auth()->id() && $a->allows(auth()->id(),'opening_approve'))<flux:button wire:click="advance({{ $batch->id }},{{ $batch->version }},'approve')" wire:confirm="اعتماد الأرصدة الفعلية ومصالحتها بهذه النسخة؟" wire:loading.attr="disabled">اعتماد مستقل للأرصدة الافتتاحية</flux:button>@endif
        </div>
        @endif
    </x-ui.panel>

    <x-ui.panel>
        <h2 class="text-lg font-semibold">7. تفعيل الترحيل المالي</h2>
        <p class="mt-2 max-w-3xl text-sm text-slate-600">التفعيل يرحل الافتتاح المعتمد في GL والموردين والخزينة وFIFO معًا. إذا فشل أي جزء، تُلغى العملية كاملة. لا يمحو النظام تاريخًا سابقًا ولا يولد أرقامًا تعويضية أو أرصدة بديلة.</p>
        @if(!$setup['activated_at'] && $batch?->status==='approved' && $batch->configuration_version===$setup['version'] && $a->allows(auth()->id(),'financial_activate'))
        <flux:button class="mt-4" variant="primary" wire:click="activate({{ $batch->id }},{{ $batch->version }})" wire:confirm="ترحيل الأرصدة الفعلية المعتمدة وتثبيت تاريخ التشغيل وتفعيل الترحيل؟ هذه عملية مالية غير قابلة للمحو." wire:loading.attr="disabled">ترحيل الافتتاح وتفعيل المالية</flux:button>
        @else<p class="mt-4 text-sm text-slate-600">{{ $setup['activated_at']?'تم التفعيل ببيانات الشركة المعتمدة.':'التفعيل غير متاح حتى اكتمال الإعداد والمصالحة والاعتماد والصلاحية.' }}</p>@endif
    </x-ui.panel>
    <p class="text-xs text-slate-500" wire:loading role="status">جارٍ تنفيذ الطلب، انتظر اكتمال العملية.</p>
</div>
