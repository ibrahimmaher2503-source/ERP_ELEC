<div class="space-y-6">
    <x-page.heading :title="$record->name" description="تفاصيل الفرع وبياناته التنظيمية">
        <x-slot:actions>
            @if (app(\App\Modules\Organization\Application\OrganizationAccess::class)->allows(auth()->user(), 'M01.company_view') && \Illuminate\Support\Facades\Route::has('organization.company'))
                <flux:button :href="route('organization.company')" icon="building-office" wire:navigate>بيانات الشركة</flux:button>
            @endif
            <flux:button :href="route('organization.branches')" wire:navigate>العودة للفروع</flux:button>
        </x-slot:actions>
    </x-page.heading>
    <x-ui.panel>
        <flux:heading size="lg">معلومات الفرع</flux:heading>
        <dl class="mt-6 grid gap-6 sm:grid-cols-2">
            <x-erp.information-row label="اسم الفرع">{{ $record->name }}</x-erp.information-row>
            <x-erp.information-row label="كود الفرع"><bdi>{{ $record->code }}</bdi></x-erp.information-row>
            <x-erp.information-row label="الحالة"><x-erp.status-badge :active="$record->active" /></x-erp.information-row>
            <x-erp.information-row label="العنوان">{{ $record->address ?: 'غير مسجل' }}</x-erp.information-row>
            <x-erp.information-row label="الشركة">
                @if (app(\App\Modules\Organization\Application\OrganizationAccess::class)->allows(auth()->user(), 'M01.company_view') && \Illuminate\Support\Facades\Route::has('organization.company'))
                    <a href="{{ route('organization.company') }}" wire:navigate class="font-medium text-blue-700 hover:text-blue-800">الشركة · <bdi>{{ $record->company_id }}</bdi></a>
                @else
                    <span>الشركة · <bdi>{{ $record->company_id }}</bdi></span>
                @endif
            </x-erp.information-row>
            <x-erp.information-row label="آخر تحديث · توقيت القاهرة"><bdi>{{ \Carbon\CarbonImmutable::parse($record->updated_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</bdi></x-erp.information-row>
        </dl>
    </x-ui.panel>
</div>
