<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>@include('partials.head', ['title' => 'غير مسموح بالوصول', 'erpLight' => true])</head>
    <body class="erp-shell">
        <main id="main-content" class="erp-error-page">
            <x-ui.panel>
                <flux:icon.lock-closed class="mx-auto mb-4 size-8 text-blue-700" aria-hidden="true" />
                <h1>غير مسموح بالوصول</h1>
                <p>تحقق من صلاحية العرض ونطاق الفرع أو المستودع مع مدير النظام، أو انتقل إلى الصفحات المتاحة لحسابك.</p>
                <flux:button variant="primary" :href="route('dashboard')">العودة إلى الرئيسية</flux:button>
            </x-ui.panel>
        </main>
    </body>
</html>
