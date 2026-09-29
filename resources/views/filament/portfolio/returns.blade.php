<x-filament-panels::page>
    @php
        $total = $this->result()['total'];
        $percent = $total->percent();
        $cdi = $this->cdi();
        $ofCdi = ($percent !== null && $cdi !== null && $cdi > 0) ? $percent / $cdi * 100 : null;
        $fmt = fn (?float $value, string $suffix = '%') => $value === null ? '—' : number_format($value, 2, ',', '.').$suffix;
    @endphp

    <p class="fc-label">
        {{ $this->periodLabel() }}. Método Dietz modificado: considera quando cada compra ou venda aconteceu.
        Valor dos ativos pela última cotação até a data (sem cotação, pelo preço médio).
    </p>

    <div class="fc-cards">
        <x-filament::section>
            <p class="fc-label">Carteira</p>
            <p class="fc-value {{ ($percent ?? 0) < 0 ? 'fc-negative' : 'fc-positive' }}">{{ $fmt($percent) }}</p>
            <p class="fc-hint">resultado {{ $this->money($total->result()) }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="fc-label">CDI no período</p>
            <p class="fc-value">{{ $fmt($cdi) }}</p>
            <p class="fc-hint">
                @if ($cdi === null)
                    sem dados do CDI (rode app:fetch-cdi)
                @elseif ($missing = $this->cdiMissingUntil())
                    <span class="fc-warning">só a partir de {{ $missing->format('d/m/Y') }} — carregue o histórico com app:fetch-cdi --from=…</span>
                @else
                    acumulado diário
                @endif
            </p>
        </x-filament::section>
        <x-filament::section>
            <p class="fc-label">% do CDI</p>
            <p class="fc-value">{{ $fmt($ofCdi) }}</p>
            <p class="fc-hint">rentabilidade ÷ CDI</p>
        </x-filament::section>
        <x-filament::section>
            <p class="fc-label">Composição do resultado</p>
            <div class="fc-stack fc-num" style="gap: 0.25rem; margin-top: 0.5rem;">
                <div class="fc-row fc-text"><span>Valorização</span><span class="fc-strong">{{ $this->money($total->appreciation()) }}</span></div>
                <div class="fc-row fc-text"><span>Proventos</span><span class="fc-strong">{{ $this->money($total->incomes) }}</span></div>
                <div class="fc-row fc-text"><span>Realizado em vendas</span><span class="fc-strong">{{ $this->money($total->realized) }}</span></div>
            </div>
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
