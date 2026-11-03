@php
    [$label, $tone] = match ($status) {
        'draft' => ['مسودة', 'slate'],
        'pending_approval' => ['بانتظار المراجعة', 'amber'],
        'cancelled' => ['ملغي', 'red'],
        'approved' => ['معتمد', 'green'],
        default => ['حالة غير معروفة', 'slate'],
    };
@endphp
<span @class([
    'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold',
    'bg-slate-100 text-slate-700' => $tone === 'slate',
    'bg-amber-50 text-amber-800 ring-1 ring-amber-200' => $tone === 'amber',
    'bg-red-50 text-red-700 ring-1 ring-red-200' => $tone === 'red',
    'bg-green-50 text-green-800 ring-1 ring-green-200' => $tone === 'green',
])>{{ $label }}</span>
