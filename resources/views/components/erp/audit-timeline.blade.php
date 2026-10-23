@props(['events'])

@php
    $formatValue = fn ($value) => match (true) {
        is_bool($value) => $value ? 'نعم' : 'لا',
        is_array($value) => collect($value)->filter(fn ($item) => is_scalar($item) || $item === null)->map(fn ($item) => $item === null ? '—' : (string) $item)->implode('، ') ?: '—',
        is_scalar($value) => (string) $value,
        default => '—',
    };
@endphp

@forelse ($events as $event)
    @php
        $before = collect(data_get($event, 'before', []));
        $after = collect(data_get($event, 'after', []));
        $fields = $before->keys()->merge($after->keys())->unique();
        $occurredAt = data_get($event, 'occurred_at');
        $outcome = data_get($event, 'outcome');
        $outcomeLabel = match ($outcome) {
            'succeeded' => 'نجح',
            'failed' => 'فشل',
            default => 'غير معروف',
        };
    @endphp
    <article wire:key="audit-event-{{ data_get($event, 'id', $loop->index) }}" class="relative border-s-2 border-slate-200 pb-5 ps-4 last:border-transparent last:pb-0">
        <span aria-hidden="true" class="absolute -start-[5px] top-1 size-2 rounded-full bg-slate-400"></span>
        <div class="rounded-lg border border-slate-100 bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0"><h3 class="font-semibold text-slate-800">{{ data_get($event, 'label', 'حدث تدقيق') }}</h3><p class="mt-1 text-xs text-slate-500">بواسطة: {{ data_get($event, 'actor') ?: 'مستخدم غير متاح' }}</p></div>
                <span @class(['shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold', 'bg-emerald-50 text-emerald-800' => $outcome === 'succeeded', 'bg-red-50 text-red-700' => $outcome === 'failed', 'bg-amber-50 text-amber-800' => ! in_array($outcome, ['succeeded', 'failed'], true)])>{{ $outcomeLabel }}</span>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                @if ($occurredAt)<time datetime="{{ \Carbon\CarbonImmutable::parse($occurredAt)->utc()->toIso8601String() }}">{{ \Carbon\CarbonImmutable::parse($occurredAt)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }} · توقيت القاهرة</time>@else<span>وقت الحدث غير معروف</span>@endif
                @if (data_get($event, 'scope'))<span>النطاق: {{ data_get($event, 'scope') }}</span>@endif
            </div>
            @if ($fields->isNotEmpty())
                <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-md bg-slate-50 p-3"><dt class="mb-2 text-xs font-semibold text-slate-500">قبل</dt><dd class="space-y-2">@foreach ($fields as $field)<div class="flex flex-wrap justify-between gap-2 text-sm"><span class="text-slate-500">{{ $field }}</span><span class="font-medium">{{ $formatValue($before->get($field)) }}</span></div>@endforeach</dd></div>
                    <div class="rounded-md bg-slate-50 p-3"><dt class="mb-2 text-xs font-semibold text-slate-500">بعد</dt><dd class="space-y-2">@foreach ($fields as $field)<div class="flex flex-wrap justify-between gap-2 text-sm"><span class="text-slate-500">{{ $field }}</span><span class="font-medium">{{ $formatValue($after->get($field)) }}</span></div>@endforeach</dd></div>
                </dl>
            @endif
        </div>
    </article>
@empty
    <x-ui.empty-state title="لا توجد أحداث تدقيق" description="لا توجد أحداث فعلية مسجلة لهذا الحساب." />
@endforelse
