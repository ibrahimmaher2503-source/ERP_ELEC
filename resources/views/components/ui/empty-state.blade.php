@props(['title', 'description' => null, 'icon' => 'inbox'])

<div {{ $attributes->class(['erp-empty-state flex flex-col items-center gap-3 py-6 text-center']) }}>
    <flux:icon :name="$icon" class="size-8 text-zinc-400" aria-hidden="true" />
    <flux:heading size="lg" level="2">{{ $title }}</flux:heading>
    @if ($description)
        <flux:text class="max-w-lg">{{ $description }}</flux:text>
    @endif
    {{ $slot }}
</div>
