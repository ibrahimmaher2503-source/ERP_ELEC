<x-ui.panel>
    <flux:heading>تاريخ إصلاح نفس المعدة</flux:heading>
    @forelse($equipmentHistory as $prior)
        <p class="mt-2"><a class="text-blue-700 underline" href="{{ route('workshops.intakes.show',$prior['id']) }}">{{ $prior['number'] }}</a> · {{ $prior['status'] }}</p>
    @empty<p class="mt-2">لم تحدد هوية مستلمة للمعدة بعد.</p>@endforelse
</x-ui.panel>
