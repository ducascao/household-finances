@php
    /** @var array{percent: float|null, status: string|null, status_label: string|null, status_color: string|null} $row */
    $row = $getRecord();
    $percent = $row['percent'];
    $color = $row['status_color'] ?? 'gray';
@endphp

@if ($percent === null)
    <span class="text-sm text-gray-500 dark:text-gray-400">sem orçamento</span>
@else
    <div class="flex items-center gap-3" style="min-width: 14rem;">
        <div
            role="progressbar"
            aria-valuenow="{{ $percent }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ $row['status_label'] }}: {{ number_format($percent, 1, ',', '.') }}%"
            style="flex: 1; height: 0.5rem; border-radius: 9999px; background: var(--gray-200); overflow: hidden;"
        >
            <div style="height: 100%; width: {{ min($percent, 100) }}%; border-radius: 9999px; background: var(--{{ $color }}-500);"></div>
        </div>
        <span class="text-sm tabular-nums" style="min-width: 3.5rem; text-align: right;">{{ number_format($percent, 1, ',', '.') }}%</span>
        @if ($row['status'] !== 'within')
            <x-filament::badge :color="$color" size="sm">{{ $row['status_label'] }}</x-filament::badge>
        @endif
    </div>
@endif
