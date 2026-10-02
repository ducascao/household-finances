<?php

namespace App\Filament\Monthly;

use App\Domain\Reports\MonthlySummary;
use App\Filament\Support\Sensitive;
use App\Support\MoneyFormatter;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MonthTotalsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters, SelectedMonth;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return 'Entradas × saídas — '.self::monthLabel($this->month());
    }

    protected function getDescription(): ?string
    {
        $others = app(MonthlySummary::class)->otherCurrencies($this->month());

        return 'Por competência, sem transferências. Inclui os previstos.'
            .($others !== [] ? ' Lançamentos em '.implode(', ', $others).' ficam fora dos totais até a conversão de moeda.' : '');
    }

    protected function getStats(): array
    {
        $totals = app(MonthlySummary::class)->totals($this->month());
        $split = fn (array $values): string => 'realizado '.MoneyFormatter::formatMinor($values['paid']).' · previsto '.MoneyFormatter::formatMinor($values['scheduled']);

        return [
            Stat::make('Entradas', Sensitive::html(MoneyFormatter::formatMinor($totals['income']['total'])))
                ->description(Sensitive::html($split($totals['income']))),
            Stat::make('Saídas', Sensitive::html(MoneyFormatter::formatMinor($totals['expense']['total'])))
                ->description(Sensitive::html($split($totals['expense']))),
            Stat::make('Resultado', Sensitive::html(MoneyFormatter::formatMinor($totals['result'])))
                ->description($totals['result'] >= 0 ? 'sobra no mês' : 'falta no mês')
                ->descriptionIcon($totals['result'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($totals['result'] >= 0 ? 'success' : 'danger'),
        ];
    }
}
