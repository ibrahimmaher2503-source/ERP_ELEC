@props(['selectedCount' => 0, 'canBulk' => false, 'canImport' => false, 'canExport' => false])

<div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#E5E8EE] px-5 py-3">
    <p class="text-sm text-slate-600" aria-live="polite">{{ $selectedCount }} صنف محدد</p>

    <div class="flex flex-wrap items-center gap-2">
        @if ($canBulk)
            <flux:button type="button" variant="outline" icon="pencil-square" :disabled="$selectedCount === 0" wire:click="openBulk">تعديل المحدد</flux:button>
        @endif

        @if ($canImport)
            <flux:button :href="route('products.import')" variant="outline" icon="arrow-up-tray" wire:navigate>استيراد XLSX</flux:button>
        @endif

        @if ($canExport)
            <flux:dropdown>
                <flux:button type="button" variant="outline" icon="arrow-down-tray">تصدير</flux:button>
                <flux:menu>
                    <flux:menu.item icon="rectangle-stack" wire:click="startExport('all')">الكتالوج بالكامل</flux:menu.item>
                    <flux:menu.item icon="funnel" wire:click="startExport('filtered')">نتائج البحث والفلاتر الحالية</flux:menu.item>
                    <flux:menu.item icon="check" :disabled="$selectedCount === 0" wire:click="startExport('selected')">الأصناف المحددة</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        @endif

        @if ($selectedCount > 0)
            <flux:button type="button" variant="ghost" icon="x-mark" wire:click="clearSelection">مسح التحديد</flux:button>
        @endif
    </div>
</div>
