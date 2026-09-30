<?php

namespace App\Filament\NetWorth;

use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class HistoryTableWidget extends TableWidget
{
    use InteractsWithPageFilters, SelectedView;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Histórico mensal')
            ->description('Fotografia do último dia de cada mês; o mês atual é calculado agora.')
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->emptyStateHeading('Sem histórico ainda')
            ->columns([
                TextColumn::make('month')->label('Mês'),
                TextColumn::make('accounts')->label('Contas')->alignEnd(),
                TextColumn::make('investments')->label('Investimentos')->alignEnd(),
                TextColumn::make('debts')->label('Dívidas')->alignEnd()->color('danger'),
                TextColumn::make('net_worth')->label('Patrimônio líquido')->alignEnd()->weight('bold'),
                TextColumn::make('change')->label('Variação')->alignEnd()
                    ->color(fn (array $record): ?string => $record['change_raw'] === null ? null : ($record['change_raw'] < 0 ? 'danger' : 'success')),
            ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $series = $this->history()->series($this->viewer(), $this->isHousehold());
        $rows = [];
        $previous = null;

        foreach ($series as $point) {
            $change = $previous !== null ? $point['net_worth'] - $previous : null;
            $rows[$point['month']->format('Y-m')] = [
                'month' => ucfirst($point['month']->locale('pt_BR')->translatedFormat('F/Y')).($point['live'] ? ' (hoje)' : ''),
                'accounts' => MoneyFormatter::formatMinor($point['accounts']),
                'investments' => MoneyFormatter::formatMinor($point['investments']),
                'debts' => MoneyFormatter::formatMinor(-$point['debts']),
                'net_worth' => MoneyFormatter::formatMinor($point['net_worth']),
                'change' => $change !== null ? MoneyFormatter::formatMinor($change) : '—',
                'change_raw' => $change,
            ];
            $previous = $point['net_worth'];
        }

        return array_reverse($rows, true);
    }
}
