@props(['label'])
<div {{ $attributes->class(['min-w-0 border-b border-slate-100 py-2 last:border-0']) }}>
    <dt class="text-xs text-slate-500">{{ $label }}</dt>
    <dd class="mt-1 break-words text-sm font-medium leading-6">{{ $slot }}</dd>
</div>
