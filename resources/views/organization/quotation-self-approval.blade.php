<x-ui.panel class="mt-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-bold text-[#202B3D]">سياسة الاعتماد الذاتي للمستندات والعمليات</h2>
            <p class="mt-1 text-xs text-slate-600">
                الاعتماد المستقل بين شخصين مفعّل افتراضيًا. التفعيل هنا يتيح لصاحب الصلاحية اعتماد مستنده في النطاقات المحددة فقط، ولا يمنح أي صلاحية جديدة، ويُسجَّل كل اعتماد ذاتي صراحةً في سجل التدقيق (<bdi>self_approved: true</bdi>).
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button type="button" size="xs" wire:click="enableAll">تحديد الكل</flux:button>
            <flux:button type="button" size="xs" variant="ghost" wire:click="disableAll">إلغاء الكل (اعتماد مستقل)</flux:button>
        </div>
    </div>

    <form wire:submit="save" class="mt-4 space-y-4">
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($definitions as $scope => [$label, $description])
                <div wire:key="self-approval-{{ $targetType }}-{{ $targetId }}-{{ $scope }}" class="rounded-lg border border-slate-200 p-3">
                    <flux:checkbox wire:model="scopes.{{ $scope }}" :label="$label" />
                    <p class="mt-1 text-xs text-slate-500">{{ $description }}</p>
                </div>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary" size="sm" wire:loading.attr="disabled">حفظ السياسة</flux:button>
            @if(session('selfApprovalStatus'))
                <span class="text-sm text-emerald-700" role="status">{{ session('selfApprovalStatus') }}</span>
            @endif
        </div>
    </form>
    <x-ui.validation-summary />
    <flux:error name="selfApproval" />
</x-ui.panel>
