@props(['categories', 'selectedId' => null, 'parentId' => null])

@php($nodes = $categories->where('parent_id', $parentId))

<div class="space-y-1">
    @foreach ($nodes as $category)
        @php($hasChildren = $categories->contains(fn ($item) => (string) $item->parent_id === (string) $category->id))
        <div wire:key="category-node-{{ $category->id }}">
            <button type="button" wire:click="$set('categoryFilter', '{{ $category->id }}')" @class(['flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-start text-sm', 'bg-blue-50 font-semibold text-blue-800' => (string) $selectedId === (string) $category->id, 'text-slate-600 hover:bg-slate-50' => (string) $selectedId !== (string) $category->id]) aria-pressed="{{ (string) $selectedId === (string) $category->id ? 'true' : 'false' }}">
                <span class="flex min-w-0 items-center gap-2 truncate">
                    <flux:icon :name="$hasChildren ? 'folder' : 'cube'" class="size-4 shrink-0" />
                    <span class="truncate">{{ $category->name }}</span>
                </span>
                <span class="ms-2 shrink-0 text-xs">{{ $category->products_count }}</span>
            </button>
            @if ($hasChildren)
                <div class="ms-4 border-s border-slate-200 ps-2">
                    <x-products.category-tree :categories="$categories" :selected-id="$selectedId" :parent-id="$category->id" />
                </div>
            @endif
        </div>
    @endforeach
</div>
