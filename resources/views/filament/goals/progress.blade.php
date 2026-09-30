@php
    /** @var \App\Models\Goal $goal */
    $goal = $getRecord();
    $progress = new \App\Domain\Goals\GoalProgress($goal);
    $status = $progress->status();
    $percent = $progress->percent();
@endphp

<div class="fc-row-start" style="min-width: 16rem; gap: 0.75rem;">
    <div class="fc-track fc-grow" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"
         aria-label="{{ $status->label() }}: {{ number_format($percent, 1, ',', '.') }}%">
        <div class="fc-bar" style="width: {{ min($percent, 100) }}%; background: var(--{{ $status->color() }}-500);"></div>
    </div>
    <span class="fc-text fc-num fc-right" style="min-width: 3.5rem;">{{ number_format($percent, 1, ',', '.') }}%</span>
    <x-filament::badge :color="$status->color()" size="sm">{{ $status->label() }}</x-filament::badge>
</div>
