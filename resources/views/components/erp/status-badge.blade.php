@props(['active'])
<span @class(['inline-flex items-center gap-1 rounded px-2.5 py-1 text-xs font-semibold', 'erp-status--success' => $active, 'erp-status--neutral' => ! $active])><span aria-hidden="true">●</span> {{ $active ? 'نشط' : 'غير نشط' }}</span>
