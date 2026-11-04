@blaze(fold: true, unsafe: [
    'name', 'placeholder', 'prefix', 'invalid', 'size', 'clear', 'close',
    'selectedSuffix', 'searchable', 'clearable', 'button', 'input', 'trigger', 'search', 'empty', 'multiple',
    'label', 'badge', 'description', 'description:trailing', 'searchModel',
    'label:badge', 'label:aside', 'label:trailing',
    'error:name', 'error:bag', 'error:message', 'error:icon', 'error:nested', 'error:deep',
])

@props(['variant' => 'default', 'searchModel' => null])

<flux:with-field :$attributes>
    @if ($variant === 'default')
        <erp-select{!! $searchModel ? ' data-search-model="'.e($searchModel).'"' : '' !!}>
            @if($searchModel)
                <div data-erp-select-fallback class="mb-2"><flux:input wire:model.live.debounce.300ms="{{ $searchModel }}" type="search" aria-label="البحث في الخيارات" placeholder="اكتب الاسم أو الكود للبحث…" icon="magnifying-glass" /></div>
            @endif
            <flux:delegate-component component="select.variants.default">{{ $slot }}</flux:delegate-component>
            <div data-erp-select-ui wire:ignore hidden></div>
        </erp-select>
    @else
        <flux:delegate-component :component="'select.variants.' . $variant">{{ $slot }}</flux:delegate-component>
    @endif
</flux:with-field>
