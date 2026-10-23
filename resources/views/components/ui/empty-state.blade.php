@props(['title', 'description' => null, 'icon' => 'inbox'])

<div {{ $attributes->class(['flex flex-col items-center gap-4 py-12 text-center']) }}>
    <flux:icon :name="$icon" class="size-10 text-zinc-400" />
    <flux:heading size="lg" level="2">{{ $title }}</flux:heading>
    @if ($description)
        <flux:text class="max-w-lg">{{ $description }}</flux:text>
    @endif
    {{ $slot }}
</div>
