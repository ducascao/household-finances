<?php

namespace App\Filament\Portfolio;

use App\Domain\Investments\Portfolio;
use App\Domain\Investments\ReturnCalculator;
use App\Filament\Support\Sensitive;
use App\Support\MoneyFormatter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PortfolioTotalsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $portfolio = app(Portfolio::class);
        $totals = $portfolio->totals($portfolio->rows());
        $positive = ! $totals['result']->isNegative();
        $year = app(ReturnCalculator::class)->forPeriod(today()->subYear()->addDay(), today());

        return [
            Stat::make('Custo total', Sensitive::html(MoneyFormatter::format($totals['cost'])))
                ->description($totals['missing'] !== []
                    ? 'Fora dos totais por falta de câmbio: '.implode(', ', $totals['missing'])
                    : 'Quanto foi investido nas posições abertas (com taxas), em reais')
                ->color($totals['missing'] !== [] ? 'warning' : null),
            Stat::make('Valor de mercado', Sensitive::html(MoneyFormatter::format($totals['market'])))
                ->description('Pela última cotação de cada ativo'),
            Stat::make('Resultado não realizado', Sensitive::html(MoneyFormatter::format($totals['result'])))
                ->description($totals['percent'] !== null ? number_format($totals['percent'], 2, ',', '.').'% sobre o custo' : '—')
                ->descriptionIcon($positive ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($positive ? 'success' : 'danger'),
            Stat::make('Proventos em 12 meses', Sensitive::html(MoneyFormatter::formatMinor($year['total']->incomes)))
                ->description(Sensitive::html('Realizado em vendas: '.MoneyFormatter::formatMinor($year['total']->realized))),
        ];
    }
}
