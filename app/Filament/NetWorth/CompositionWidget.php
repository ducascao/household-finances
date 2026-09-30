<?php

namespace App\Filament\NetWorth;

use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Composição atual: contas, investimentos por tipo, bens e dívidas, com o peso de cada item.
 */
class CompositionWidget extends TableWidget
{
    use InteractsWithPageFilters, SelectedView;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Composição hoje')
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('group')->label('Grupo')->badge()
                    ->color(fn (array $record): string => $record['group'] === 'Dívidas' ? 'danger' : match ($record['group']) {
                        'Investimentos' => 'info', 'Bens' => 'warning', default => 'gray'
                    }),
                TextColumn::make('name')->label('Item'),
                TextColumn::make('value')->label('Valor')->alignEnd()->weight('bold')
                    ->color(fn (array $record): ?string => $record['group'] === 'Dívidas' ? 'danger' : null),
                TextColumn::make('share')->label('Peso')->alignEnd(),
            ]);
    }

    /**
     * @return array<string, array{group: string, name: string, value: string, share: string}>
     */
    private function rows(): array
    {
        $now = $this->history()->current($this->viewer(), $this->isHousehold());
        $assets = max(1, $now->accounts() + $now->investments() + $now->goods());
        $rows = [];

        foreach (['Contas' => $now->accountItems, 'Investimentos' => $now->investmentItems, 'Bens' => $now->goodItems, 'Dívidas' => $now->debtItems] as $group => $items) {
            arsort($items);

            foreach ($items as $name => $value) {
                $rows[$group.'|'.$name] = [
                    'group' => $group,
                    'name' => $name,
                    'value' => MoneyFormatter::formatMinor($group === 'Dívidas' ? -$value : $value),
                    'share' => number_format($value / $assets * 100, 1, ',', '.').'% dos ativos',
                ];
            }
        }

        return $rows;
    }
}
