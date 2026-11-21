<div data-erp-archetype="form" class="orders-ui space-y-5">
    <x-page.heading title="إعدادات اعتماد أوامر البيع" description="تحديد دور مسؤول الاستثناءات التجارية وقوالب الاعتماد" :breadcrumbs="['المبيعات', 'أوامر البيع', 'إعدادات الاعتماد']">
        <x-slot:actions>
            <flux:button :href="route('sales-orders.index')" wire:navigate icon="arrow-right">العودة إلى أوامر البيع</flux:button>
        </x-slot:actions>
    </x-page.heading>

    @include('sales.orders.partials.feedback')

    <section class="orders-summary" aria-label="الوضع الحالي للإعدادات">
        <div>
            <span>دور مسؤول الاستثناءات الحالي</span>
            <strong>{{ $selectedRoleName ?? 'لم يُحدد بعد' }}</strong>
        </div>
        <div>
            <span>رقم نسخة الإعداد</span>
            <strong class="tabular-nums">{{ $currentPolicy?->version ?? '—' }}</strong>
        </div>
        <div>
            <span>آخر تحديث بواسطة</span>
            <strong>{{ $updatedByName ?? '—' }}</strong>
        </div>
        <div>
            <span>تاريخ التحديث</span>
            <strong class="tabular-nums"><bdi>{{ $currentPolicy?->updated_at ? \Carbon\CarbonImmutable::parse($currentPolicy->updated_at)->timezone('Africa/Cairo')->format('Y/m/d H:i') : '—' }}</bdi></strong>
        </div>
        <div>
            <span>الصلاحيات</span>
            <strong>يدوي ومقيد بالفرع</strong>
        </div>
    </section>

    <x-erp.section-card title="مسؤول الاستثناءات" icon="shield-check">
        <form wire:submit="save" class="max-w-2xl space-y-4">
            <p class="text-sm leading-7 text-slate-700">تُمنح صلاحية اعتماد الأوامر المطابقة من الأدوار والصلاحيات في M01. أي استثناء في الأسعار أو الخصومات أو الشروط يحتاج قرارًا إضافيًا من عضو في الدور المحدد هنا يمتلك صلاحية اعتماد الاستثناءات ونطاق الفرع. اختيار الدور لا يمنحه أي صلاحية تلقائيًا.</p>
            <flux:select label="دور مسؤول الاستثناءات" wire:model="role" required>
                <option value="">اختر الدور</option>
                @foreach($roles as $item)
                    <option value="{{ $item['id'] }}">{{ $item['name'] }} · {{ $item['code'] }}</option>
                @endforeach
            </flux:select>
            <p class="text-sm text-amber-800">لا توجد حدود مالية أو نسب خصم افتراضية. المطابقة تحتاج مصادر أسعار وقوالب شروط معتمدة.</p>
            <div class="flex flex-wrap items-center gap-2 pt-2">
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">حفظ الإعدادات</flux:button>
                <flux:button :href="route('organization.roles')" wire:navigate icon="shield-check">إدارة الصلاحيات</flux:button>
                @if($canQuotationPolicy)
                    <flux:button :href="route('quotations.policy')" wire:navigate icon="clipboard-document-list">سياسات وقوالب عروض الأسعار</flux:button>
                @endif
            </div>
        </form>
    </x-erp.section-card>
</div>
