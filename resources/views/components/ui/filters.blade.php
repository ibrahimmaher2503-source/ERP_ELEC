@props(['id', 'target', 'reset' => [], 'clear' => null])
@php($hasFilters = trim((string) $slot) !== '')

<section id="{{ $id }}" wire:key="{{ $id }}" data-erp-filters {{ $attributes->class(['erp-filter-disclosure']) }} x-data="{
    open: false,
    clearing: false,
    defaults: @js($reset),
    get activeCount() {
        return Object.entries(this.defaults).filter(([key, value]) => String(this.$wire.$get(key) ?? '') !== String(value ?? '')).length;
    },
    async resetFilters() {
        if (this.clearing) return;
        this.clearing = true;
        const previous = Object.fromEntries(Object.keys(this.defaults).map(key => [key, this.$wire.$get(key)]));
        try {
            @if($clear)
                await this.$wire.$call(@js($clear));
            @else
                Object.entries(this.defaults).forEach(([key, value]) => this.$wire.$set(key, value, false));
                await this.$wire.$commit();
            @endif
            await this.$nextTick();
            [...this.$el.querySelectorAll('.erp-filter-search input, .erp-filter-toggle, .erp-filter-body .erp-select-trigger, .erp-filter-body input, .erp-filter-body select')].find(el => el.getClientRects().length)?.focus();
        } catch {
            Object.entries(previous).forEach(([key, value]) => this.$wire.$set(key, value, false));
        } finally {
            this.clearing = false;
        }
    }
}" data-open="true" :data-open="open.toString()" aria-label="البحث والتصفية">
    @isset($search)
        <div class="erp-filter-search">{{ $search }}</div>
    @endisset
    @if($hasFilters)
    <button type="button" class="erp-filter-toggle" data-erp-filter-toggle @click="open = !open" :aria-expanded="open.toString()" aria-controls="{{ $id }}-body">
        <flux:icon.funnel class="size-4" aria-hidden="true" />
        <span>{{ isset($search) ? 'الفلاتر' : 'البحث والتصفية' }}</span>
        <span class="erp-filter-count" x-cloak x-show="activeCount > 0" x-text="activeCount" aria-label="عدد الفلاتر النشطة"></span>
        <flux:icon.chevron-down class="ms-auto size-4" aria-hidden="true" />
    </button>
    <div id="{{ $id }}-body" class="erp-filter-body">
        {{ $slot }}
    </div>
    @endif
    <div class="erp-filter-status">
        <p>تُحدَّث النتائج تلقائيًا <span x-cloak x-show="activeCount > 0">· <span x-text="activeCount"></span> فلاتر نشطة</span></p>
        @if($reset)
            <button type="button" class="erp-filter-reset" data-erp-filter-reset x-cloak x-show="activeCount > 0" @click="resetFilters()" :disabled="clearing" wire:loading.attr="disabled" wire:target="{{ $target }}">مسح الفلاتر</button>
        @endif
    </div>
    <p wire:loading.delay wire:target="{{ $target }}" role="status" class="erp-filter-loading">جارٍ تحديث النتائج…</p>
</section>
