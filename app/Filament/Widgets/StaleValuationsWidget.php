<?php

namespace App\Filament\Widgets;

use App\Domain\Investments\Portfolio;
use App\Domain\Investments\PortfolioRow;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\RelationManagers\ValuationsRelationManager;
use App\Filament\Support\Sensitive;
use App\Models\Asset;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Lembrete: renda fixa e previdência com dinheiro aplicado e último saldo com mais de 30 dias (ou nenhum).
 */
class StaleValuationsWidget extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::stale() !== [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Atualize o saldo da renda fixa e da previdência')
            ->description('O último saldo informado tem mais de 30 dias. Use o valor do extrato da corretora ou da seguradora.')
            ->records(fn (): array => collect(self::stale())
                ->mapWithKeys(fn (PortfolioRow $row): array => ['a'.$row->asset->id => [
                    'asset_id' => $row->asset->id,
                    'name' => $row->asset->name,
                    'type' => $row->asset->type->label(),
                    'last' => $row->valuation?->lastValuation?->date->format('d/m/Y') ?? 'nunca',
                    'value' => MoneyFormatter::format($row->marketValue()),
                ]])
                ->all())
            ->paginated(false)
            ->recordUrl(fn (array $record): string => AssetResource::getUrl('view', ['record' => $record['asset_id']]))
            ->columns([
                TextColumn::make('name')->label('Aplicação')->weight('bold')->description(fn (array $record): string => $record['type']),
                TextColumn::make('last')->label('Último saldo')->badge()->color('warning'),
                TextColumn::make('value')->extraAttributes(Sensitive::ATTRIBUTES, merge: true)->label('Valor considerado')->alignEnd(),
            ])
            ->recordActions([
                ValuationsRelationManager::informAction(fn (mixed $record): Asset => Asset::findOrFail(is_array($record) ? $record['asset_id'] : null))
                    ->button()
                    ->size('sm'),
            ]);
    }

    /**
     * @return list<PortfolioRow>
     */
    private static function stale(): array
    {
        return array_values(array_filter(
            app(Portfolio::class)->rows(),
            fn (PortfolioRow $row): bool => $row->isValuedByBalance() && $row->marketValue()->isPositive() && $row->isPriceStale(),
        ));
    }
}
