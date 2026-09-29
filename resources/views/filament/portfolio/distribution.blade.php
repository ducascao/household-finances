<x-filament-widgets::widget>
    <x-filament::section heading="Distribuição por tipo">
        @php($items = $this->distribution())
        @if ($items === [])
            <p class="fc-label">Nenhuma posição aberta.</p>
        @else
            <div class="fc-stack">
                @foreach ($items as $item)
                    <div style="display: grid; align-items: center; gap: 0.75rem; grid-template-columns: 5rem 1fr 9rem 4rem;">
                        <span class="fc-text fc-strong">{{ $item['label'] }}</span>
                        <div class="fc-track fc-track-lg" role="img" aria-label="{{ $item['label'] }}: {{ number_format($item['percent'], 1, ',', '.') }}%">
                            <div class="fc-bar" style="width: {{ $item['percent'] }}%; background: #2a78d6;"></div>
                        </div>
                        <span class="fc-text fc-num fc-right">{{ $item['value'] }}</span>
                        <span class="fc-label fc-num fc-right">{{ number_format($item['percent'], 1, ',', '.') }}%</span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
