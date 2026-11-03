@props(['label'])

<div {{ $attributes->class(['erp-responsive-table']) }} role="region" aria-label="{{ $label }}" tabindex="0">
    {{ $slot }}
</div>
