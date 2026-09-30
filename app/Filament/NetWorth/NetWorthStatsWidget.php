<?php

namespace App\Filament\NetWorth;

use App\Support\MoneyFormatter;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class NetWorthStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters, SelectedView;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getDescription(): ?string
    {
        return $this->isHousehold()
            ? 'Do lar: só contas, investimentos e dívidas em contas compartilhadas.'
            : 'O que você vê: contas compartilhadas e as suas pessoais.';
    }

    protected function getStats(): array
    {
        $series = $this->history()->series($this->viewer(), $this->isHousehold(), 13);
        $now = end($series);
        $previous = count($series) >= 2 ? $series[count($series) - 2] : null;
        $yearAgo = count($series) >= 13 ? $series[0] : null;
        $delta = fn (?array $base): string => $base === null ? 'sem histórico' : MoneyFormatter::formatMinor($now['net_worth'] - $base['net_worth']);

        return [
            Stat::make('Patrimônio líquido', MoneyFormatter::formatMinor($now['net_worth']))
                ->description('contas + investimentos − dívidas, hoje'),
            Stat::make('No mês', $delta($previous))
                ->description($previous !== null ? 'desde o fim de '.$previous['month']->locale('pt_BR')->translatedFormat('F') : 'grave o histórico para comparar')
                ->color($previous !== null && $now['net_worth'] < $previous['net_worth'] ? 'danger' : 'success'),
            Stat::make('Em 12 meses', $delta($yearAgo))
                ->color($yearAgo !== null && $now['net_worth'] < $yearAgo['net_worth'] ? 'danger' : 'success'),
            Stat::make('Composição', MoneyFormatter::formatMinor($now['accounts'] + $now['investments']))
                ->description('bens; dívidas: '.MoneyFormatter::formatMinor($now['debts'])),
        ];
    }
}
