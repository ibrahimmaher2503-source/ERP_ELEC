@php
    $accounting = app(\App\Modules\Accounting\Application\AccountingAccess::class);
    $active = $accounting->activated();
    $canSetup = isset($accounting->navigation(auth()->id())['setup']);
@endphp
@unless($active)
<flux:callout icon="exclamation-triangle" variant="warning" role="status" class="mb-1">
    <flux:callout.heading>الترحيل المالي لم يُفعَّل بعد</flux:callout.heading>
    <flux:callout.text>الأرصدة والتقارير تظهر صفرًا أو فارغة لأنه لا توجد قيود مرحّلة. أكمل خطوات التجهيز ليبدأ النظام في ترحيل الفواتير والتحصيل.@if($canSetup) <a href="{{ route('accounting.setup') }}" wire:navigate class="font-semibold underline">افتح خطوات التجهيز</a>@endif</flux:callout.text>
</flux:callout>
@endunless
