@php
    [$label, $tone] = \App\Modules\Sales\Presentation\SalesOrderDisplay::status((string) $status);
@endphp
<span class="orders-badge" data-tone="{{ $tone }}">{{ $label }}</span>
