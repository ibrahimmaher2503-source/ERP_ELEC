<div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs" aria-label="صفحات {{ $table }}">
    <span>{{ $pagination['total'] }} سجل · صفحة {{ $pagination['page'] }} من {{ $pagination['last_page'] }}</span>
    <div class="flex gap-2"><flux:button size="sm" wire:click="goToPage('{{ $table }}',{{ max(1,$pagination['page']-1) }})" :disabled="$pagination['page']<=1" wire:loading.attr="disabled">السابق</flux:button><flux:button size="sm" wire:click="goToPage('{{ $table }}',{{ $pagination['page']+1 }})" :disabled="$pagination['page']>=$pagination['last_page']" wire:loading.attr="disabled">التالي</flux:button></div>
</div>
