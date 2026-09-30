<?php

namespace App\Filament\Widgets;

use App\Domain\Goods\GoodValue;
use App\Filament\Resources\Goods\GoodResource;
use App\Filament\Resources\Goods\RelationManagers\GoodValuationsRelationManager;
use App\Models\Good;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Lembrete: bens ainda seus cuja última avaliação (ou a aquisição) tem mais de 6 meses.
 */
class StaleGoodsWidget extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::stale() !== [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Atualize o valor dos bens')
            ->description('A última avaliação tem mais de 6 meses. Use a tabela FIPE, uma avaliação ou anúncios parecidos.')
            ->records(fn (): array => collect(self::stale())
                ->mapWithKeys(fn (Good $good): array => ['g'.$good->id => [
                    'good_id' => $good->id,
                    'name' => $good->name,
                    'type' => $good->type->label(),
                    'last' => GoodValue::lastValuation($good)?->date->format('d/m/Y') ?? 'aquisição em '.$good->acquisition_date->format('d/m/Y'),
                    'value' => MoneyFormatter::formatMinor(GoodValue::at($good, today())),
                ]])
                ->all())
            ->paginated(false)
            ->recordUrl(fn (array $record): string => GoodResource::getUrl('view', ['record' => $record['good_id']]))
            ->columns([
                TextColumn::make('name')->label('Bem')->weight('bold')->description(fn (array $record): string => $record['type']),
                TextColumn::make('last')->label('Última avaliação')->badge()->color('warning'),
                TextColumn::make('value')->label('Valor considerado')->alignEnd(),
            ])
            ->recordActions([
                GoodValuationsRelationManager::informAction(fn (mixed $record): Good => Good::findOrFail(is_array($record) ? $record['good_id'] : null))
                    ->button()
                    ->size('sm'),
            ]);
    }

    /**
     * @return list<Good>
     */
    private static function stale(): array
    {
        return Good::query()->whereNull('sale_date')->orderBy('name')->get()
            ->filter(fn (Good $good): bool => GoodValue::isStale($good))
            ->values()
            ->all();
    }
}
