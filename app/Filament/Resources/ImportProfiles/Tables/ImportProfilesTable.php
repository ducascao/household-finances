<?php

namespace App\Filament\Resources\ImportProfiles\Tables;

use App\Models\ImportProfile;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ImportProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('account'))
            ->columns([
                TextColumn::make('account.name')
                    ->label('Conta'),
                TextColumn::make('name')
                    ->label('Perfil'),
                TextColumn::make('columns')
                    ->label('Colunas')
                    ->state(fn (ImportProfile $record): string => "data {$record->date_column} · descrição {$record->description_column} · "
                        .($record->amount_column !== null ? "valor {$record->amount_column}" : "débito {$record->debit_column} / crédito {$record->credit_column}")),
                TextColumn::make('date_format')
                    ->label('Data'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
