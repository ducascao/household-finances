<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Domain\Accounts\ArchiveAccount;
use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Models\Account;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('owner'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (AccountType $state): string => $state->label()),
                TextColumn::make('visibility')
                    ->label('Visibilidade')
                    ->badge()
                    ->color(fn (AccountVisibility $state): string => $state === AccountVisibility::Shared ? 'info' : 'gray')
                    ->formatStateUsing(fn (AccountVisibility $state): string => $state->label()),
                TextColumn::make('owner.name')
                    ->label('Dono'),
                TextColumn::make('initial_balance')
                    ->label('Saldo inicial')
                    ->alignEnd()
                    ->formatStateUsing(fn (Account $record): string => MoneyFormatter::format($record->initial_balance)),
                TextColumn::make('archived_at')
                    ->label('Arquivada em')
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(AccountType::options()),
                TernaryFilter::make('archived')
                    ->label('Arquivadas')
                    ->placeholder('Só ativas')
                    ->trueLabel('Só arquivadas')
                    ->falseLabel('Todas')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->whereNull('archived_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('archive')
                    ->label(fn (Account $record): string => $record->isArchived() ? 'Reativar' : 'Arquivar')
                    ->icon(fn (Account $record) => $record->isArchived() ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->authorize(fn (Account $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (Account $record) => app(ArchiveAccount::class)->execute($record, ! $record->isArchived())),
            ]);
    }
}
