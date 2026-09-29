<?php

namespace App\Filament\Resources\Assets\Tables;

use App\Enums\AssetType;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('account')->withCount('operations'))
            ->defaultSort('ticker')
            ->columns([
                TextColumn::make('ticker')->label('Ticker')->searchable()->sortable()->weight('bold')->placeholder('—'),
                TextColumn::make('name')->label('Nome')->searchable(),
                TextColumn::make('type')->label('Tipo')->badge()->formatStateUsing(fn (AssetType $state): string => $state->label()),
                TextColumn::make('account.name')->label('Conta'),
                TextColumn::make('operations_count')->label('Operações'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(AssetType::options()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
