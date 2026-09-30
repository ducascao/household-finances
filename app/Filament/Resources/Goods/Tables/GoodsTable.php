<?php

namespace App\Filament\Resources\Goods\Tables;

use App\Domain\Goods\GoodValue;
use App\Models\Good;
use App\Support\MoneyFormatter;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GoodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->emptyStateHeading('Nenhum bem cadastrado')
            ->emptyStateDescription('Cadastre imóveis, veículos e outros bens para incluí-los no patrimônio.')
            ->columns([
                TextColumn::make('name')->label('Bem')->weight('bold')->searchable()
                    ->description(fn (Good $record): string => $record->type->label().' · '.$record->visibility->label()),
                TextColumn::make('value')->label('Valor atual')->alignEnd()
                    ->state(fn (Good $record): string => $record->sale_date !== null ? 'vendido' : MoneyFormatter::formatMinor(GoodValue::at($record, today()))),
                TextColumn::make('last')->label('Última avaliação')
                    ->state(fn (Good $record): string => GoodValue::lastValuation($record)?->date->format('d/m/Y') ?? 'aquisição')
                    ->icon(fn (Good $record): ?string => GoodValue::isStale($record) ? 'heroicon-m-exclamation-triangle' : null)
                    ->iconColor('warning'),
                TextColumn::make('net')->label('Valor líquido')->alignEnd()->weight('bold')
                    ->state(fn (Good $record): string => $record->sale_date !== null ? '—' : MoneyFormatter::formatMinor(GoodValue::net($record)))
                    ->description(fn (Good $record): ?string => $record->debt_id !== null && $record->sale_date === null ? 'descontado o financiamento' : null),
            ])
            ->filters([
                TernaryFilter::make('sold')
                    ->label('Vendidos')
                    ->placeholder('Só atuais')
                    ->trueLabel('Só vendidos')
                    ->falseLabel('Todos')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('sale_date'),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->whereNull('sale_date'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
