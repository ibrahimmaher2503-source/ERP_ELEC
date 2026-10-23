@props(['title', 'icon' => 'squares-2x2'])
<x-ui.panel {{ $attributes->class(['!p-0 overflow-hidden']) }}>
    <header class="erp-section-heading flex items-center gap-3 border-b border-[#E5E8EE] px-5 py-4"><span class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-700"><flux:icon :name="$icon" class="size-4" /></span><flux:heading size="lg" class="!font-bold">{{ $title }}</flux:heading></header>
    <div class="p-5">{{ $slot }}</div>
</x-ui.panel>
