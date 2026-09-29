@php
    /** @var array{percent: float|null, status: string|null, status_label: string|null, status_color: string|null} $row */
    $row = $getRecord();
    $percent = $row['percent'];
    $color = $row['status_color'] ?? 'gray';
@endphp

@if ($percent === null)
    <span class="fc-label">sem orçamento</span>
@else
    <div class="fc-row-start" style="min-width: 14rem; gap: 0.75rem;">
        <div
            class="fc-track fc-grow"
            role="progressbar"
            aria-valuenow="{{ $percent }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ $row['status_label'] }}: {{ number_format($percent, 1, ',', '.') }}%"
        >
            <div class="fc-bar" style="width: {{ min($percent, 100) }}%; background: var(--{{ $color }}-500);"></div>
        </div>
        <span class="fc-text fc-num fc-right" style="min-width: 3.5rem;">{{ number_format($percent, 1, ',', '.') }}%</span>
        @if ($row['status'] !== 'within')
            <x-filament::badge :color="$color" size="sm">{{ $row['status_label'] }}</x-filament::badge>
        @endif
    </div>
@endif
