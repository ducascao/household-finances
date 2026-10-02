<?php

namespace App\Filament\Monthly;

use App\Domain\Reports\MonthlySummary;
use App\Filament\Support\Sensitive;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Saldo de cada conta no fim do mês escolhido: hoje + previstos até o fim do mês.
 */
class BalanceProjectionWidget extends TableWidget
{
    use InteractsWithPageFilters, SelectedMonth;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $isPast = $this->month()->copy()->endOfMonth()->lt(today());

        return $table
            ->heading('Saldo das contas no fim de '.mb_strtolower(self::monthLabel($this->month())))
            ->description($isPast
                ? 'Mês encerrado: saldo real no último dia do mês.'
                : 'Saldo de hoje mais os previstos (inclusive atrasados) que vencem até o fim do mês.')
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Conta')
                    ->weight(fn (array $record): ?string => $record['is_total'] ? 'bold' : null),
                TextColumn::make('today')
                    ->extraAttributes(Sensitive::ATTRIBUTES, merge: true)
                    ->label('Saldo hoje')
                    ->alignEnd()
                    ->formatStateUsing(fn (array $record): string => MoneyFormatter::format(Money::ofMinor($record['today'], $record['currency']))),
                TextColumn::make('scheduled')
                    ->extraAttributes(Sensitive::ATTRIBUTES, merge: true)
                    ->label($isPast ? 'Diferença até hoje' : 'Previstos até o fim do mês')
                    ->alignEnd()
                    ->formatStateUsing(fn (array $record): string => MoneyFormatter::format(Money::ofMinor($record['scheduled'], $record['currency']))),
                TextColumn::make('projected')
                    ->extraAttributes(Sensitive::ATTRIBUTES, merge: true)
                    ->label('Saldo no fim do mês')
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn (array $record): string => $record['projected'] < 0 ? 'danger' : 'success')
                    ->formatStateUsing(fn (array $record): string => MoneyFormatter::format(Money::ofMinor($record['projected'], $record['currency']))),
            ]);
    }

    /**
     * @return array<string, array{name: string, currency: string, today: int, scheduled: int, projected: int, is_total: bool}>
     */
    private function rows(): array
    {
        $rows = [];
        $totals = [];

        foreach (app(MonthlySummary::class)->projection($this->month()) as $row) {
            $currency = $row['account']->currency;
            $rows['a'.$row['account']->id] = [
                'name' => $row['account']->name,
                'currency' => $currency,
                'today' => $row['today'],
                'scheduled' => $row['scheduled'],
                'projected' => $row['projected'],
                'is_total' => false,
            ];

            $totals[$currency] ??= ['today' => 0, 'scheduled' => 0, 'projected' => 0];
            $totals[$currency]['today'] += $row['today'];
            $totals[$currency]['scheduled'] += $row['scheduled'];
            $totals[$currency]['projected'] += $row['projected'];
        }

        foreach ($totals as $currency => $total) {
            $rows['total-'.$currency] = ['name' => 'Total '.$currency, 'currency' => $currency, ...$total, 'is_total' => true];
        }

        return $rows;
    }
}
