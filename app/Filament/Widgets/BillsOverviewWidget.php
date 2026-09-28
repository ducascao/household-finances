<?php

namespace App\Filament\Widgets;

use App\Domain\Transactions\BillsSummary;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BillsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $heading = 'Contas a pagar e a receber';

    protected function getStats(): array
    {
        $summary = app(BillsSummary::class);
        $overdue = $summary->overdue();
        $dueSoon = $summary->dueSoon();
        $forecast = $summary->forecastForMonth();

        return [
            Stat::make('Atrasados', self::format($overdue['totals']))
                ->description(self::count($overdue['count']))
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($overdue['count'] > 0 ? 'danger' : 'gray'),
            Stat::make('Vencendo em '.BillsSummary::DUE_SOON_DAYS.' dias', self::format($dueSoon['totals']))
                ->description(self::count($dueSoon['count']))
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($dueSoon['count'] > 0 ? 'warning' : 'gray'),
            Stat::make('Previsto no mês', 'A pagar: '.self::format($forecast['payable']))
                ->description('A receber: '.self::format($forecast['receivable']))
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->color('info'),
        ];
    }

    /**
     * @param  array<string, Money>  $totals
     */
    private static function format(array $totals): string
    {
        return $totals === [] ? 'R$ 0,00' : implode(' · ', array_map(MoneyFormatter::format(...), $totals));
    }

    private static function count(int $count): string
    {
        return match ($count) {
            0 => 'nenhum lançamento',
            1 => '1 lançamento',
            default => "{$count} lançamentos",
        };
    }
}
