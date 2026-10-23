<div class="space-y-4">
    <x-page.heading title="إعدادات اعتماد أوامر البيع" description="صلاحيات ديناميكية وموافقة إضافية على الاستثناءات" :breadcrumbs="['المبيعات', 'أوامر البيع', 'إعدادات الاعتماد']" />
    @include('sales.orders.partials.feedback')
    <x-erp.section-card title="مسؤول الاستثناءات" icon="shield-check">
        <form wire:submit="save" class="max-w-2xl space-y-4">
            <p class="text-sm leading-7">تُمنح صلاحية اعتماد الأوامر المطابقة من الأدوار والصلاحيات في M01. الاستثناء يحتاج قرارًا إضافيًا من دور المسؤول المحدد هنا مع صلاحية اعتماد الاستثناءات ونطاق الفرع. اختيار الدور لا يمنحه أي صلاحية تلقائيًا.</p>
            <flux:select label="دور مسؤول الاستثناءات" wire:model="role" required><option value="">اختر الدور</option>@foreach($roles as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }} · {{ $item['code'] }}</option>@endforeach</flux:select>
            <p class="text-sm text-amber-800">لا توجد حدود مالية أو نسب خصم افتراضية. المطابقة تحتاج مصادر أسعار وشروط معتمدة؛ غيابها يمنع الاعتماد التشغيلي.</p>
            <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">حفظ الإعدادات</flux:button>
            <flux:button :href="route('organization.roles')" wire:navigate icon="shield-check">إدارة الصلاحيات</flux:button>
        </form>
    </x-erp.section-card>
</div>
