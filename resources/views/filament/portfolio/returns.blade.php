<x-filament-panels::page>
    @php
        $total = $this->result()['total'];
        $percent = $total->percent();
        $cdi = $this->cdi();
        $ofCdi = ($percent !== null && $cdi !== null && $cdi > 0) ? $percent / $cdi * 100 : null;
        $fmt = fn (?float $value, string $suffix = '%') => $value === null ? '—' : number_format($value, 2, ',', '.').$suffix;
    @endphp

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ $this->periodLabel() }}. Método Dietz modificado: considera quando cada compra ou venda aconteceu.
        Valor dos ativos pela última cotação até a data (sem cotação, pelo preço médio).
    </p>

    <div class="grid gap-4 md:grid-cols-4">
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Carteira</p>
            <p class="text-2xl font-semibold tabular-nums" style="color: {{ ($percent ?? 0) < 0 ? 'var(--danger-600)' : 'var(--success-600)' }}">{{ $fmt($percent) }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">resultado {{ $this->money($total->result()) }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">CDI no período</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $fmt($cdi) }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($cdi === null)
                    sem dados do CDI (rode app:fetch-cdi)
                @elseif ($missing = $this->cdiMissingUntil())
                    <span style="color: var(--warning-600)">só a partir de {{ $missing->format('d/m/Y') }} — carregue o histórico com app:fetch-cdi --from=…</span>
                @else
                    acumulado diário
                @endif
            </p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">% do CDI</p>
            <p class="text-2xl font-semibold tabular-nums">{{ $fmt($ofCdi) }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">rentabilidade ÷ CDI</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Composição do resultado</p>
            <p class="text-sm tabular-nums">Valorização: <strong>{{ $this->money($total->appreciation()) }}</strong></p>
            <p class="text-sm tabular-nums">Proventos: <strong>{{ $this->money($total->incomes) }}</strong></p>
            <p class="text-sm tabular-nums">Realizado em vendas: <strong>{{ $this->money($total->realized) }}</strong></p>
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
