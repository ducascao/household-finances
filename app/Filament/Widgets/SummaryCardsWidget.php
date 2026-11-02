<?php

namespace App\Filament\Widgets;

use App\Domain\Reports\DashboardSummary;
use App\Domain\Reports\MonthlySummary as MonthlySummaryReport;
use App\Filament\Pages\MonthlySummary;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Sensitive;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Topo do Painel no estilo Organizze: saldo geral, receitas e despesas do mês e cartões.
 */
class SummaryCardsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected function getStats(): array
    {
        $summary = app(DashboardSummary::class);
        $balance = $summary->balance();
        $month = $summary->month();
        $cards = $summary->cards();
        $brl = fn (int $minor): string => MoneyFormatter::formatMinor($minor, MonthlySummaryReport::CURRENCY);
        $cardTotal = $cards['totals'][MonthlySummaryReport::CURRENCY] ?? Money::zero(MonthlySummaryReport::CURRENCY);
        $otherCards = array_diff_key($cards['totals'], [MonthlySummaryReport::CURRENCY => true]);

        return [
            Stat::make('Saldo geral', Sensitive::html(MoneyFormatter::format($balance['current'])))
                ->description(Sensitive::html('fim do mês: '.MoneyFormatter::format($balance['projected']).self::others($balance['others'])))
                ->icon(Heroicon::OutlinedWallet)
                ->extraAttributes(['class' => $balance['current']->isNegative() ? 'fc-tone-danger fc-tone-value' : 'fc-tone-primary'])
                ->url(AccountResource::getUrl('index')),
            Stat::make('Receitas do mês', Sensitive::html($brl($month['income']['total'])))
                ->description(Sensitive::html('recebido '.$brl($month['income']['paid']).' · a receber '.$brl($month['income']['scheduled'])))
                ->icon(Heroicon::OutlinedArrowUpCircle)
                ->extraAttributes(['class' => 'fc-tone-success fc-tone-value'])
                ->url(MonthlySummary::getUrl()),
            Stat::make('Despesas do mês', Sensitive::html($brl($month['expense']['total'])))
                ->description(Sensitive::html('pago '.$brl($month['expense']['paid']).' · a pagar '.$brl($month['expense']['scheduled'])))
                ->icon(Heroicon::OutlinedArrowDownCircle)
                ->extraAttributes(['class' => 'fc-tone-danger fc-tone-value'])
                ->url(MonthlySummary::getUrl()),
            Stat::make('Cartões', Sensitive::html(MoneyFormatter::format($cardTotal)))
                ->description($cards['count'] === 0
                    ? 'nenhuma fatura em aberto'
                    : Sensitive::html(self::cardCount($cards['count']).' · vence '.$cards['next_due']?->format('d/m').self::others($otherCards)))
                ->icon(Heroicon::OutlinedCreditCard)
                ->extraAttributes(['class' => 'fc-tone-warning'])
                ->url(InvoiceResource::getUrl('index')),
        ];
    }

    /**
     * @param  array<string, Money>  $others
     */
    private static function others(array $others): string
    {
        return $others === [] ? '' : ' · além de '.implode(' · ', array_map(MoneyFormatter::format(...), $others));
    }

    private static function cardCount(int $count): string
    {
        return $count === 1 ? '1 fatura atual' : "{$count} faturas atuais";
    }
}
