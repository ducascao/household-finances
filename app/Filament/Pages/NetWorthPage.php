<?php

namespace App\Filament\Pages;

use App\Filament\NetWorth\CompositionWidget;
use App\Filament\NetWorth\HistoryTableWidget;
use App\Filament\NetWorth\NetWorthChart;
use App\Filament\NetWorth\NetWorthStatsWidget;
use BackedEnum;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Patrimônio líquido: evolução mensal e composição, na visão da pessoa ou do lar (só o compartilhado).
 */
class NetWorthPage extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'patrimonio';

    protected static ?string $title = 'Patrimônio';

    protected static ?string $navigationLabel = 'Patrimônio líquido';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Patrimônio';

    protected static ?int $navigationSort = 70;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            ToggleButtons::make('view')
                ->label('Visão')
                ->options(['me' => 'O que eu vejo', 'household' => 'Do lar (compartilhado)'])
                ->default('me')
                ->inline(),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            NetWorthStatsWidget::class,
            NetWorthChart::class,
            CompositionWidget::class,
            HistoryTableWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
