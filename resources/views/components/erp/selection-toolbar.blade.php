@props(['selectedCount' => 0, 'canBulk' => false, 'canImport' => false, 'canExport' => false])

<div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#E5E8EE] px-5 py-3">
    <p class="text-sm text-slate-600" aria-live="polite">{{ $selectedCount }} صنف محدد</p>

    <div class="flex flex-wrap items-center gap-2">
        @if ($canBulk)
            <flux:button type="button" variant="outline" icon="pencil-square" :disabled="$selectedCount === 0" wire:click="openBulk">تعديل المحدد</flux:button>
        @endif

        <div class="erp-toolbar-secondary">
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
        @if ($canImport || $canExport || $selectedCount > 0)
            <div class="erp-toolbar-overflow">
                <flux:dropdown>
                    <flux:button type="button" variant="outline" icon="ellipsis-horizontal">المزيد</flux:button>
                    <flux:menu>
                        @if ($canImport)<flux:menu.item :href="route('products.import')" icon="arrow-up-tray" wire:navigate>استيراد XLSX</flux:menu.item>@endif
                        @if ($canExport)
                            <flux:menu.item icon="rectangle-stack" wire:click="startExport('all')">تصدير الكتالوج بالكامل</flux:menu.item>
                            <flux:menu.item icon="funnel" wire:click="startExport('filtered')">تصدير نتائج البحث والفلاتر</flux:menu.item>
                            <flux:menu.item icon="check" :disabled="$selectedCount === 0" wire:click="startExport('selected')">تصدير الأصناف المحددة</flux:menu.item>
                        @endif
                        @if ($selectedCount > 0)<flux:menu.item icon="x-mark" wire:click="clearSelection">مسح التحديد</flux:menu.item>@endif
                    </flux:menu>
                </flux:dropdown>
            </div>
        @endif
    </div>
</div>
