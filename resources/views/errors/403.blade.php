<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>@include('partials.head', ['title' => 'غير مسموح بالوصول', 'erpLight' => true])</head>
    @php($reason = isset($exception) ? trim((string) $exception->getMessage()) : '')
    <body class="erp-shell">
        <main id="main-content" class="erp-error-page">
            <x-ui.panel>
                <flux:icon.lock-closed class="mx-auto mb-4 size-8 text-blue-700" aria-hidden="true" />
                <h1>غير مسموح بالوصول</h1>
                @if ($reason !== '')
                    <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-900">{{ $reason }}</p>
                @else
                    <p>لا تملك الصلاحية أو النطاق المطلوب لعرض هذه الصفحة أو تنفيذ هذا الإجراء.</p>
                @endif
                <p class="mt-2 text-xs text-slate-500">يتم منح الصلاحيات ونطاقات الفروع والمستودعات عبر مدير النظام أو صاحب صلاحية إدارة الأدوار والتفويض الأمني (M01.security_admin) من شاشة المستخدمون ← تفاصيل المستخدم ← الأدوار ونطاقات الوصول.</p>
                <div class="mt-4">
                    <flux:button variant="primary" :href="route('dashboard')">العودة إلى الرئيسية</flux:button>
                </div>
            </x-ui.panel>
        </main>
    </body>
</html>
