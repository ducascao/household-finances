<?php

namespace App\Filament\Monthly;

use App\Domain\Reports\MonthlySummary;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Filament\Support\Sensitive;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Tabela dos gastos: categoria principal e, abaixo, as subcategorias. Clicar abre os lançamentos da categoria no mês.
 */
class ExpensesByCategoryTable extends TableWidget
{
    use InteractsWithPageFilters, SelectedMonth;

    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Detalhe por categoria')
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->emptyStateHeading('Nenhuma despesa neste mês')
            ->recordUrl(fn (array $record): string => TransactionResource::getUrl('index', [
                'filters' => [
                    'category_id' => ['value' => (string) $record['category_id']],
                    'competence' => ['value' => $this->month()->format('Y-m')],
                ],
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Categoria')
                    ->weight(fn (array $record): ?string => $record['is_root'] ? 'bold' : null),
                TextColumn::make('total')
                    ->extraAttributes(Sensitive::ATTRIBUTES, merge: true)
                    ->label('Valor')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
                TextColumn::make('percent')
                    ->label('% das saídas')
                    ->alignEnd()
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '' : number_format($state, 1, ',', '.').'%'),
            ]);
    }

    /**
     * @return array<string, array{category_id: int, name: string, total: int, percent: float|null, is_root: bool}>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (app(MonthlySummary::class)->expensesByCategory($this->month()) as $root) {
            $rows['c'.$root['category']->id] = [
                'category_id' => $root['category']->id,
                'name' => $root['category']->name,
                'total' => $root['total'],
                'percent' => $root['percent'],
                'is_root' => true,
            ];

            foreach ($root['children'] as $child) {
                $rows['c'.$child['category']->id] = [
                    'category_id' => $child['category']->id,
                    'name' => '↳ '.$child['category']->name,
                    'total' => $child['total'],
                    'percent' => null,
                    'is_root' => false,
                ];
            }
        }

        return $rows;
    }
}
