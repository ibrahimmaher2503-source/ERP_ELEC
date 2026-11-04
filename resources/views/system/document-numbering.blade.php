<div class="space-y-5">
    <x-page.heading title="ترقيم المستندات" description="حدد رقم البداية لكل نوع مستند قبل إصدار أول مستند منه في السنة" :breadcrumbs="['التنظيم', 'ترقيم المستندات']" />
    @if(session('status'))<div role="status" class="rounded border border-emerald-200 bg-emerald-50 p-3 text-emerald-800">{{ session('status') }}</div>@endif
    <flux:error name="start" />
    <x-ui.panel>
        <flux:input wire:model.live.debounce.500ms="year" label="السنة" type="number" class="max-w-40" />
        <div class="mt-4 overflow-x-auto">
            <table role="table" class="w-full min-w-[640px] text-sm">
                <thead><tr class="text-start text-slate-500"><th class="p-2 text-start">المستند</th><th class="p-2 text-start">الرقم التالي</th><th class="p-2 text-start">رقم البداية</th><th class="p-2"></th></tr></thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr wire:key="numbering-{{ $row['type'] }}" class="border-t border-slate-100">
                            <td class="p-2">{{ $row['label'] }}</td>
                            <td class="p-2"><bdi>{{ $row['example'] }}</bdi></td>
                            <td class="p-2">
                                @if($row['locked'])
                                    <span class="text-slate-500">مقفول بعد أول إصدار</span>
                                @else
                                    <flux:input wire:model="starts.{{ $row['type'] }}" inputmode="numeric" aria-label="رقم بداية {{ $row['label'] }}" class="max-w-32" />
                                @endif
                            </td>
                            <td class="p-2 text-end">@unless($row['locked'])<flux:button size="sm" wire:click="save('{{ $row['type'] }}')" wire:loading.attr="disabled">حفظ</flux:button>@endunless</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.panel>
</div>
