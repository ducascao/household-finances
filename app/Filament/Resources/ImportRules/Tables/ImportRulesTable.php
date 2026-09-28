<?php

namespace App\Filament\Resources\ImportRules\Tables;

use App\Models\ImportRule;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ImportRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category.parent'))
            ->defaultSort('position')
            ->reorderable('position')
            ->description('A primeira regra que casar vale. Arraste para mudar a ordem.')
            ->columns([
                TextColumn::make('pattern')
                    ->label('Descrição contém')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Resultado')
                    ->state(fn (ImportRule $record): string => $record->ignore ? 'Ignorar' : (string) $record->category?->fullName())
                    ->badge(fn (ImportRule $record): bool => $record->ignore)
                    ->color(fn (ImportRule $record): ?string => $record->ignore ? 'gray' : null),
                TextColumn::make('description')
                    ->label('Descrição amigável')
                    ->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
