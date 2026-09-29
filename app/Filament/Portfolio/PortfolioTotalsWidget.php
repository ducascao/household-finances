<?php

namespace App\Filament\Portfolio;

use App\Domain\Investments\Portfolio;
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

        return [
            Stat::make('Custo total', MoneyFormatter::format($totals['cost']))
                ->description('Quanto foi investido nas posições abertas (com taxas)'),
            Stat::make('Valor de mercado', MoneyFormatter::format($totals['market']))
                ->description('Pela última cotação de cada ativo'),
            Stat::make('Resultado não realizado', MoneyFormatter::format($totals['result']))
                ->description($totals['percent'] !== null ? number_format($totals['percent'], 2, ',', '.').'% sobre o custo' : '—')
                ->descriptionIcon($positive ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($positive ? 'success' : 'danger'),
        ];
    }
}
