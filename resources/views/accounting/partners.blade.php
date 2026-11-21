@php
    $kindLabels = [
        'contribution' => 'مساهمة / إيداع شريك',
        'drawing' => 'مسحوبات شخصية للشريك',
        'expense_paid_by_partner' => 'مصروف شركة دفعه الشريك',
        'reversal' => 'قيد عكس حركة',
    ];
    $kindBadge = [
        'contribution' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
        'drawing' => 'bg-amber-50 text-amber-800 border-amber-200',
        'expense_paid_by_partner' => 'bg-blue-50 text-blue-800 border-blue-200',
        'reversal' => 'bg-slate-100 text-slate-700 border-slate-300',
    ];
@endphp

<div data-erp-archetype="list" class="erp-finance space-y-5">
    <x-page.heading
        title="حسابات جاري الشركاء"
        description="تتبع مساهمات الشركاء ومسحوباتهم الشخصية ومصروفات الشركة المدفوعة من مالهم الخاص، منفصلة تماماً عن إيرادات المبيعات."
        :breadcrumbs="['المالية', 'جاري الشركاء']"
    >
        <x-slot:secondaryActions>
            <flux:button :href="route('accounting.dashboard')" icon="calculator" wire:navigate>لوحة المحاسبة</flux:button>
            <flux:button :href="route('accounting.income-statement')" icon="chart-bar" wire:navigate>قائمة الدخل</flux:button>
        </x-slot:secondaryActions>
    </x-page.heading>

    <x-erp.finance-banner />

    @if(session('partner_message'))
        <flux:callout variant="success" icon="check-circle" role="status">
            <flux:callout.text>{{ session('partner_message') }}</flux:callout.text>
        </flux:callout>
    @endif

    <x-ui.validation-summary />

    {{-- Isolation Explanation & Summary Cards --}}
    <x-ui.panel class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-base font-bold text-[#172b4d]">ملخص أرصدة الشركاء</h2>
                <p class="text-xs text-slate-600">
                    إيداعات ومسحوبات الشركاء تُقيد على حسابات جاري الشركاء (حقوق الملكية) ولا تظهر أبداً ضمن إيرادات المبيعات. المصروف المدفوع من شريك يثبت المصروف الحقيقي للشركة ويرفع رصيد الشريك الدائن دون مساس بالنقدية.
                </p>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-3">
                <p class="text-xs text-slate-600">إجمالي المساهمات والإيداعات</p>
                <p class="mt-1 text-lg font-bold text-emerald-800 tabular-nums" dir="ltr">{{ $summary['totals']['contributions'] }} EGP</p>
            </div>
            <div class="rounded-lg border border-blue-200 bg-blue-50/50 p-3">
                <p class="text-xs text-slate-600">مصروفات شركة مدفوعة من الشركاء</p>
                <p class="mt-1 text-lg font-bold text-blue-800 tabular-nums" dir="ltr">{{ $summary['totals']['expenses_paid'] }} EGP</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-3">
                <p class="text-xs text-slate-600">إجمالي المسحوبات الشخصية</p>
                <p class="mt-1 text-lg font-bold text-amber-800 tabular-nums" dir="ltr">{{ $summary['totals']['drawings'] }} EGP</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs text-slate-600">صافي جاري الشركاء (دائن للشركاء − مدين)</p>
                <p class="mt-1 text-lg font-bold text-slate-900 tabular-nums" dir="ltr">{{ $summary['totals']['net_balance'] }} EGP</p>
            </div>
        </div>

        @if($summary['per_partner'] !== [])
            <div class="grid gap-3 md:grid-cols-3 pt-2">
                @foreach($summary['per_partner'] as $row)
                    @php($p = $row['partner'])
                    <div class="rounded-lg border border-slate-200 bg-white p-4 space-y-2">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="font-bold text-slate-900">{{ $p->name }}</p>
                                <p class="text-xs text-slate-500">
                                    كود: <bdi dir="ltr">{{ $p->code }}</bdi> · حساب: <bdi dir="ltr">{{ $p->account_code }}</bdi> · {{ $p->account_name }}
                                </p>
                            </div>
                            <span class="rounded px-2 py-0.5 text-xs {{ $p->active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $p->active ? 'نشط' : 'موقوف' }}
                            </span>
                        </div>
                        <dl class="space-y-1 text-xs border-t border-slate-100 pt-2">
                            <div class="flex justify-between"><dt class="text-slate-500">المساهمات</dt><dd class="font-semibold text-emerald-700 tabular-nums">{{ $row['contributions'] }} ج.م</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">مصروفات دفعها للشركة</dt><dd class="font-semibold text-blue-700 tabular-nums">{{ $row['expenses_paid'] }} ج.م</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">المسحوبات</dt><dd class="font-semibold text-amber-800 tabular-nums">{{ $row['drawings'] }} ج.م</dd></div>
                            <div class="flex justify-between border-t border-slate-100 pt-1"><dt class="font-bold text-slate-800">صافي الجاري</dt><dd class="font-bold text-slate-900 tabular-nums">{{ $row['net_balance'] }} ج.م</dd></div>
                        </dl>
                        @if($canManagePartners)
                            <div class="pt-1">
                                <flux:button type="button" size="xs" wire:click="selectPartner({{ $p->id }})">تعديل بيانات الشريك</flux:button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.panel>

    <div class="grid gap-5 lg:grid-cols-2">
        {{-- Register / Edit Partner --}}
        @if($canManagePartners)
            <x-ui.panel class="space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-bold text-[#172b4d]">{{ $editPartnerId ? 'تعديل بيانات شريك' : 'تعريف شريك وحسابه الجاري' }}</h2>
                    @if($editPartnerId)
                        <flux:button type="button" size="xs" wire:click="selectPartner(null)">شريك جديد</flux:button>
                    @endif
                </div>
                <form wire:submit="savePartner" class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input label="كود الشريك" wire:model="partnerForm.code" dir="ltr" placeholder="PARTNER-1" required />
                        <flux:input label="اسم الشريك" wire:model="partnerForm.name" required />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input label="نسبة الشراكة % (اختياري)" wire:model="partnerForm.ownership_percent" inputmode="decimal" dir="ltr" placeholder="33.33" />
                        <div class="space-y-1">
                            <flux:select label="حساب جاري الشريك (حقوق ملكية)" wire:model="partnerForm.current_account_id" required>
                                <option value="">اختر حساب جاري الشريك</option>
                                @foreach($equityAccounts as $acc)
                                    <option value="{{ $acc->id }}">{{ $acc->code }} · {{ $acc->name }}</option>
                                @endforeach
                            </flux:select>
                            @if($canManageAccounts)
                                <button type="button" wire:click="autoCreatePartnerAccount" class="text-xs font-semibold text-blue-700 hover:underline">
                                    + إنشاء حساب جاري باسم الشريك في الدليل
                                </button>
                            @endif
                        </div>
                    </div>
                    <flux:input label="ملاحظات (اختياري)" wire:model="partnerForm.notes" />
                    <flux:checkbox label="شريك نشط" wire:model="partnerForm.active" />
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        {{ $editPartnerId ? 'حفظ تعديلات الشريك' : 'حفظ الشريك' }}
                    </flux:button>
                </form>
            </x-ui.panel>
        @endif

        {{-- Record Partner Movement --}}
        <x-ui.panel class="space-y-4">
            <h2 class="text-base font-bold text-[#172b4d]">تسجيل حركة جاري شريك</h2>
            @if(! $activated)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>بانتظار تفعيل الترحيل المالي</flux:callout.heading>
                    <flux:callout.text>يمكنك تعريف الشركاء وربط حساباتهم الآن، أما تسجيل الحركات المالية فيبدأ بعد إتمام التفعيل المالي من <a href="{{ route('accounting.setup') }}" wire:navigate class="font-semibold underline">تجهيز النظام المالي</a>.</flux:callout.text>
                </flux:callout>
            @elseif($manageBranches === [])
                <p class="text-sm text-slate-600">تتطلب صلاحية إدارة حركات الشركاء (M11.partner_manage) مع نطاق فرع صالح.</p>
            @else
                <form wire:submit="recordMovement" class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:select label="الشريك" wire:model="movementForm.partner_id" required>
                            <option value="">اختر الشريك</option>
                            @foreach($summary['partners']->where('active', true) as $p)
                                <option value="{{ $p->id }}">{{ $p->code }} · {{ $p->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:select label="الفرع" wire:model.live="movementForm.branch_id" required>
                            <option value="">اختر الفرع</option>
                            @foreach($manageBranches as $b)
                                <option value="{{ $b['id'] }}">{{ $b['name'] }}</option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <flux:select label="نوع الحركة" wire:model.live="movementForm.kind" required>
                            <option value="contribution">مساهمة / إيداع بالخزينة أو البنك</option>
                            <option value="drawing">سحب شخصي / صرف للشريك</option>
                            <option value="expense_paid_by_partner">مصروف شركة دفعه الشريك من ماله</option>
                        </flux:select>
                        <flux:input type="date" label="تاريخ الحركة" wire:model="movementForm.movement_date" required />
                        <flux:input label="المبلغ ج.م" inputmode="decimal" dir="ltr" wire:model="movementForm.amount" required />
                    </div>

                    @if(in_array($movementForm['kind'] ?? 'contribution', ['contribution', 'drawing'], true))
                        <flux:select label="حساب الخزينة أو البنك المستلم/المنصرف منه" wire:model="movementForm.treasury_account_id" required>
                            <option value="">اختر حساب الخزينة أو البنك</option>
                            @foreach($treasuryAccounts as $ta)
                                <option value="{{ $ta['id'] }}">{{ $ta['code'] }} · {{ $ta['name'] }} ({{ $ta['kind'] === 'bank' ? 'بنك' : 'نقدية' }})</option>
                            @endforeach
                        </flux:select>
                        @if($treasuryAccounts === [])
                            <p class="text-xs text-amber-800">تأكد من توفر حساب خزينة أو بنك نشط وصلاحية حركات شركاء الخزينة (M13.partner_cash) على الفرع المختار.</p>
                        @endif
                    @else
                        <flux:select label="حساب المصروف المحمل على الشركة (غير رقابي)" wire:model="movementForm.expense_account_id" required>
                            <option value="">اختر حساب المصروف</option>
                            @foreach($expenseAccounts as $ea)
                                <option value="{{ $ea->id }}">{{ $ea->code }} · {{ $ea->name }}</option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:input label="البيان / المرجع" wire:model="movementForm.reference" required placeholder="مثال: سداد إيجار شهر نوفمبر من الشريك..." />
                    <flux:input label="ملاحظات إضافية (اختياري)" wire:model="movementForm.notes" />
                    <flux:button type="submit" variant="primary" wire:confirm="تسجيل حركة الشريك وترحيل أثرها المحاسبي المعتمد؟" wire:loading.attr="disabled">
                        ترحيل حركة الشريك
                    </flux:button>
                </form>
            @endif
        </x-ui.panel>
    </div>

    {{-- Filters & Ledger History --}}
    <x-ui.panel class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h2 class="text-base font-bold text-[#172b4d]">سجل حركات الشركاء (تاريخ ثابت لا يُحذف)</h2>
            <div class="grid gap-2 sm:grid-cols-4">
                <flux:select label="تصفية بالشريك" wire:model.live="filterPartner">
                    <option value="">كل الشركاء</option>
                    @foreach($summary['partners'] as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </flux:select>
                <flux:select label="الفرع" wire:model.live="filterBranch">
                    <option value="">كل الفروع</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </flux:select>
                <flux:input type="date" label="من تاريخ" wire:model.live="from" />
                <flux:input type="date" label="إلى تاريخ" wire:model.live="to" />
            </div>
        </div>

        <x-ui.responsive-table label="سجل حركات الشركاء">
            <table role="table">
                <thead role="rowgroup">
                    <tr role="row">
                        <th role="columnheader">#</th>
                        <th role="columnheader">التاريخ</th>
                        <th role="columnheader">الشريك</th>
                        <th role="columnheader">النوع</th>
                        <th role="columnheader">الحساب المقابل</th>
                        <th role="columnheader">المبلغ</th>
                        <th role="columnheader">البيان والفرع</th>
                        <th role="columnheader">القيد / السند</th>
                        <th role="columnheader">الإجراء</th>
                    </tr>
                </thead>
                <tbody role="rowgroup">
                    @forelse($summary['movements'] as $m)
                        <tr role="row">
                            <td role="cell" data-label="#"><div>{{ $m->id }}</div></td>
                            <td role="cell" data-label="التاريخ"><div dir="ltr">{{ $m->movement_date }}</div></td>
                            <td role="cell" data-label="الشريك"><div>{{ $m->partner_name }}</div></td>
                            <td role="cell" data-label="النوع">
                                <div>
                                    <span class="inline-block rounded border px-2 py-0.5 text-xs {{ $kindBadge[$m->kind] ?? '' }}">
                                        {{ $kindLabels[$m->kind] ?? $m->kind }}
                                    </span>
                                    @if($m->reversed_movement_id)
                                        <span class="block text-xs text-slate-500">عكس #{{ $m->reversed_movement_id }}</span>
                                    @elseif($m->is_reversed)
                                        <span class="block text-xs text-amber-700">تم عكسها بسجل لاحق</span>
                                    @endif
                                </div>
                            </td>
                            <td role="cell" data-label="الحساب المقابل">
                                <div>
                                    @if($m->treasury_name)
                                        {{ $m->treasury_name }}
                                    @elseif($m->expense_code)
                                        {{ $m->expense_code }} · {{ $m->expense_name }}
                                    @else
                                        —
                                    @endif
                                </div>
                            </td>
                            <td role="cell" data-label="المبلغ"><div class="font-semibold tabular-nums" dir="ltr">{{ $m->amount }} EGP</div></td>
                            <td role="cell" data-label="البيان والفرع">
                                <div>
                                    <span>{{ $m->reference }}</span>
                                    <span class="block text-xs text-slate-500">{{ $branchNames[$m->branch_id] ?? 'فرع #'.$m->branch_id }}</span>
                                </div>
                            </td>
                            <td role="cell" data-label="القيد / السند">
                                <div class="flex flex-wrap gap-1.5">
                                    <a href="{{ route('accounting.journals.pdf', $m->journal_id) }}" class="text-xs text-blue-700 hover:underline">قيد #{{ $m->journal_id }} PDF</a>
                                    @if($m->treasury_movement_id)
                                        <a href="{{ route('treasury.movements.show', $m->treasury_movement_id) }}" wire:navigate class="text-xs text-slate-700 hover:underline">خزينة #{{ $m->treasury_movement_id }}</a>
                                    @endif
                                </div>
                            </td>
                            <td role="cell" data-label="الإجراء">
                                <div>
                                    @if($m->kind !== 'reversal' && ! $m->is_reversed && $access->allows(auth()->id(), 'partner_manage', (int) $m->branch_id))
                                        <flux:button type="button" size="xs" wire:click="openReverseModal({{ $m->id }})">عكس الحركة</flux:button>
                                    @else
                                        —
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr role="row">
                            <td colspan="9">
                                <x-ui.empty-state title="لا توجد حركات للشركاء بعد" description="عرّف الشركاء وسجل إيداعاتهم أو مصروفاتهم أو مسحوباتهم لمتابعة حساباتهم الجارية مستقلة عن المبيعات." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.responsive-table>
    </x-ui.panel>

    {{-- Modal: Reverse Partner Movement --}}
    <flux:modal wire:model="showReverseModal" class="w-full max-w-md">
        <form wire:submit="reverseMovement" class="space-y-4">
            <x-ui.validation-summary />
            <flux:heading size="lg">عكس حركة شريك من المصدر</flux:heading>
            <p class="text-sm text-slate-600">يبقى السجل الأصلي محفوظاً دون حذف، ويُنشأ سجل عكس وقيد محاسبي مرتبط به.</p>
            <flux:input type="date" label="تاريخ العكس" wire:model="reverseDate" required />
            <flux:textarea label="سبب العكس (5 أحرف على الأقل)" wire:model="reverseReason" rows="2" required />
            <div class="flex flex-wrap gap-2">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">تأكيد العكس</flux:button>
                <flux:button type="button" wire:click="$set('showReverseModal', false)">إلغاء</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
