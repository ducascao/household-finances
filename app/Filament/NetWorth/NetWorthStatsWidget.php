<?php

namespace App\Filament\NetWorth;

use App\Filament\Support\Sensitive;
use App\Support\MoneyFormatter;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

class NetWorthStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters, SelectedView;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getDescription(): ?string
    {
        return $this->isHousehold()
            ? 'Do lar: só contas, investimentos, bens e dívidas compartilhados.'
            : 'O que você vê: itens compartilhados e os seus pessoais.';
    }

    protected function getStats(): array
    {
        $series = $this->history()->series($this->viewer(), $this->isHousehold(), 13);
        $now = end($series);
        $previous = count($series) >= 2 ? $series[count($series) - 2] : null;
        $yearAgo = count($series) >= 13 ? $series[0] : null;
        $delta = fn (?array $base): string|HtmlString => $base === null ? 'sem histórico' : Sensitive::html(MoneyFormatter::formatMinor($now['net_worth'] - $base['net_worth']));

        return [
            Stat::make('Patrimônio líquido', Sensitive::html(MoneyFormatter::formatMinor($now['net_worth'])))
                ->description('contas + investimentos + bens − dívidas, hoje'),
            Stat::make('No mês', $delta($previous))
                ->description($previous !== null ? 'desde o fim de '.$previous['month']->locale('pt_BR')->translatedFormat('F') : 'grave o histórico para comparar')
                ->color($previous !== null && $now['net_worth'] < $previous['net_worth'] ? 'danger' : 'success'),
            Stat::make('Em 12 meses', $delta($yearAgo))
                ->color($yearAgo !== null && $now['net_worth'] < $yearAgo['net_worth'] ? 'danger' : 'success'),
            Stat::make('Composição', Sensitive::html(MoneyFormatter::formatMinor($now['accounts'] + $now['investments'] + $now['goods'])))
                ->description(Sensitive::html('ativos (bens: '.MoneyFormatter::formatMinor($now['goods']).'); dívidas: '.MoneyFormatter::formatMinor($now['debts']))),
        ];
    }
}
