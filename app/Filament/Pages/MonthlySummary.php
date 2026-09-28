<?php

namespace App\Filament\Pages;

use App\Domain\Reports\MonthlySummary as Summary;
use App\Filament\Monthly\BalanceProjectionWidget;
use App\Filament\Monthly\EvolutionChart;
use App\Filament\Monthly\ExpensesByCategoryChart;
use App\Filament\Monthly\ExpensesByCategoryTable;
use App\Filament\Monthly\MonthTotalsWidget;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Para onde foi o dinheiro no mês escolhido: entradas × saídas por competência, gastos por categoria,
 * evolução de 12 meses e saldo projetado das contas no fim do mês.
 */
class MonthlySummary extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'resumo-do-mes';

    protected static ?string $title = 'Resumo do mês';

    protected static ?string $navigationLabel = 'Resumo do mês';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 5;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('month')
                ->label('Mês')
                ->options(fn (): array => Summary::monthOptions()
                    ->mapWithKeys(fn (Carbon $month): array => [$month->format('Y-m') => ucfirst($month->locale('pt_BR')->translatedFormat('F/Y'))])
                    ->all())
                ->default(today()->format('Y-m'))
                ->selectablePlaceholder(false),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            MonthTotalsWidget::class,
            ExpensesByCategoryChart::class,
            ExpensesByCategoryTable::class,
            EvolutionChart::class,
            BalanceProjectionWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousMonth')
                ->label('Mês anterior')
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->action(fn () => $this->shiftMonth(-1)),
            Action::make('currentMonth')
                ->label('Mês atual')
                ->color('gray')
                ->action(fn () => $this->setMonth(today())),
            Action::make('nextMonth')
                ->label('Próximo mês')
                ->icon(Heroicon::OutlinedChevronRight)
                ->iconPosition('after')
                ->color('gray')
                ->action(fn () => $this->shiftMonth(1)),
        ];
    }

    private function shiftMonth(int $months): void
    {
        $current = Carbon::createFromFormat('!Y-m', (string) ($this->filters['month'] ?? today()->format('Y-m'))) ?? today();

        $this->setMonth($current->addMonthsNoOverflow($months));
    }

    private function setMonth(Carbon $month): void
    {
        $this->filters = [...($this->filters ?? []), 'month' => $month->format('Y-m')];
        $this->getFiltersForm()->fill($this->filters);
    }
}
