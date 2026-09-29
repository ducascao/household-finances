<?php

namespace App\Filament\Widgets;

use App\Domain\Budgets\BudgetReport;
use App\Domain\Budgets\BudgetRow;
use App\Enums\BudgetStatus;
use App\Filament\Pages\Budgets;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Categorias com orçamento estourado no mês corrente (só aparece quando há alguma).
 */
class BudgetAlertsWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::report(BudgetStatus::Exceeded) !== [];
    }

    public function table(Table $table): Table
    {
        $attention = count(self::report(BudgetStatus::Attention));

        return $table
            ->heading('Orçamento estourado neste mês')
            ->description($attention > 0 ? "Mais {$attention} categoria(s) acima de 80% do orçado." : null)
            ->records(fn (): array => collect(self::report(BudgetStatus::Exceeded))
                ->mapWithKeys(fn (BudgetRow $row): array => ['c'.$row->category->id => [
                    'name' => $row->category->fullName(),
                    'planned' => (int) $row->planned,
                    'committed' => $row->committed(),
                    'over' => -(int) $row->available(),
                    'percent' => (float) $row->percent(),
                ]])
                ->all())
            ->paginated(false)
            ->recordUrl(fn (): string => Budgets::getUrl())
            ->columns([
                TextColumn::make('name')
                    ->label('Categoria'),
                TextColumn::make('planned')
                    ->label('Orçado')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
                TextColumn::make('committed')
                    ->label('Realizado + previsto')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
                TextColumn::make('over')
                    ->label('Acima do orçado')
                    ->alignEnd()
                    ->color('danger')
                    ->formatStateUsing(fn (int $state): string => MoneyFormatter::formatMinor($state)),
                TextColumn::make('percent')
                    ->label('Uso')
                    ->alignEnd()
                    ->badge()
                    ->color('danger')
                    ->icon('heroicon-m-exclamation-triangle')
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1, ',', '.').'%'),
            ]);
    }

    /**
     * @return list<BudgetRow>
     */
    private static function report(BudgetStatus $status): array
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->current_household_id === null) {
            return [];
        }

        return app(BudgetReport::class)->withStatus($user->current_household_id, today(), $status);
    }
}
