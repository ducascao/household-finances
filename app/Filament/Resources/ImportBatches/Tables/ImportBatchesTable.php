<?php

namespace App\Filament\Resources\ImportBatches\Tables;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ImportBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account', 'user'])->withCount('lines'))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (ImportBatch $record): string => route('filament.app.resources.import-batches.view', $record))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Enviado em')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('account.name')
                    ->label('Conta'),
                TextColumn::make('file_name')
                    ->label('Arquivo'),
                TextColumn::make('lines_count')
                    ->label('Linhas'),
                TextColumn::make('user.name')
                    ->label('Por'),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (ImportBatchStatus $state): string => $state->label())
                    ->color(fn (ImportBatchStatus $state): string => match ($state) {
                        ImportBatchStatus::Reviewing => 'warning',
                        ImportBatchStatus::Completed => 'success',
                        ImportBatchStatus::Discarded => 'gray',
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label(fn (ImportBatch $record): string => $record->isReviewing() ? 'Revisar' : 'Ver'),
            ]);
    }
}
