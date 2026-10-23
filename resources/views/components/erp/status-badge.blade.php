@props(['active'])
<span @class(['inline-flex items-center gap-1 rounded px-2.5 py-1 text-xs font-semibold', 'bg-emerald-50 text-emerald-800' => $active, 'bg-red-50 text-red-700' => ! $active])><span aria-hidden="true">●</span> {{ $active ? 'نشط' : 'غير نشط' }}</span>
