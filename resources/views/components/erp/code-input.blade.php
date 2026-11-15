@props(['label' => 'الكود', 'prefix' => 'C', 'description' => null])

{{-- Code field with a one-click "generate" button. The code is only a suggestion: the owning form still validates uniqueness on save. --}}
@php($model = $attributes->whereStartsWith('wire:model')->first())
<flux:field
    x-data="{
        make() {
            const input = $el.querySelector('input');
            if (! input || input.disabled || input.readOnly) { return; }
            const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            const bytes = crypto.getRandomValues(new Uint8Array(6));
            input.value = '{{ $prefix }}-' + Array.from(bytes, byte => alphabet[byte % alphabet.length]).join('');
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
        }
    }"
>
    <flux:label>{{ $label }}</flux:label>
    @if($description)<flux:description>{{ $description }}</flux:description>@endif
    <flux:input.group>
        <flux:input {{ $attributes }} />
        <flux:button type="button" icon="sparkles" x-on:click="make()" aria-label="توليد كود تلقائي" title="توليد كود تلقائي">توليد</flux:button>
    </flux:input.group>
    @if($model)<flux:error :name="$model" />@endif
</flux:field>
