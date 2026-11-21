@php
    use App\Modules\Sales\Presentation\SalesOrderDisplay as D;
    $compact = $compact ?? false;
    $showAll = $showAll ?? false;
    $workflowProgress = ($record->status === 'approved' || $showAll)
        ? ($progress ?? app(\App\Modules\Sales\Application\SalesOrderProgress::class)->read(auth()->id(), $record))
        : null;
@endphp
<div class="flex flex-wrap items-center gap-1.5">
    @include('sales.orders.partials.status', ['status' => $record->status])
    @if($workflowProgress)
        @php
            [$resLabel, $resTone] = D::reservation($workflowProgress['reservation'] ?? 'not_requested');
            [$delLabel, $delTone] = D::delivery($workflowProgress['delivery'] ?? 'not_started');
            [$invLabel, $invTone] = D::invoicing($workflowProgress['invoicing'] ?? 'none');
        @endphp
        <span class="orders-badge" @if($compact) data-size="sm" @endif data-tone="{{ $resTone }}">{{ $resLabel }}</span>
        <span class="orders-badge" @if($compact) data-size="sm" @endif data-tone="{{ $delTone }}">{{ $delLabel }}</span>
        <span class="orders-badge" @if($compact) data-size="sm" @endif data-tone="{{ $invTone }}">{{ $invLabel }}</span>
    @endif
</div>
