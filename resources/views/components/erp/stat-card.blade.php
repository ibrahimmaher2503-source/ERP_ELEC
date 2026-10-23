@props(['label', 'value', 'icon', 'tone' => 'blue'])

<x-ui.panel class="erp-stat-card !p-5">
    <div class="flex items-center justify-between gap-3">
        <div><p class="text-xs font-medium text-slate-600">{{ $label }}</p><p class="mt-3 text-3xl font-bold tabular-nums">{{ $value }}</p></div>
        <div @class(['flex size-12 shrink-0 items-center justify-center rounded-lg', 'bg-blue-50 text-blue-600' => $tone === 'blue', 'bg-emerald-50 text-emerald-600' => in_array($tone, ['emerald', 'green', 'teal']), 'bg-orange-50 text-orange-600' => in_array($tone, ['orange', 'amber']), 'bg-red-50 text-red-600' => $tone === 'red', 'bg-violet-50 text-violet-600' => $tone === 'purple'])><flux:icon :name="$icon" class="size-6" /></div>
    </div>
</x-ui.panel>
