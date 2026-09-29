<x-filament-widgets::widget>
    <x-filament::section heading="Distribuição por tipo">
        @php($items = $this->distribution())
        @if ($items === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma posição aberta.</p>
        @else
            <div class="grid gap-3">
                @foreach ($items as $item)
                    <div class="grid items-center gap-3" style="grid-template-columns: 5rem 1fr 9rem 4rem;">
                        <span class="text-sm font-medium">{{ $item['label'] }}</span>
                        <div role="img" aria-label="{{ $item['label'] }}: {{ number_format($item['percent'], 1, ',', '.') }}%"
                             style="height: 0.75rem; border-radius: 9999px; background: var(--gray-200); overflow: hidden;">
                            <div style="height: 100%; width: {{ $item['percent'] }}%; background: #2a78d6; border-radius: 9999px;"></div>
                        </div>
                        <span class="text-sm tabular-nums text-right">{{ $item['value'] }}</span>
                        <span class="text-sm tabular-nums text-right text-gray-500 dark:text-gray-400">{{ number_format($item['percent'], 1, ',', '.') }}%</span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
