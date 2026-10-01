<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Domain\Accounts\AccountBalance;
use App\Domain\Accounts\ArchiveAccount;
use App\Domain\Accounts\SetOpeningBalance;
use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => AccountBalance::addToQuery($query->with('owner')))
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
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (Account $record): string => MoneyFormatter::format($record->initial_balance))
                    ->description(fn (Account $record): ?string => $record->balance_date !== null ? 'em '.$record->balance_date->format('d/m/Y') : null),
                TextColumn::make('current_balance')
                    ->label('Saldo atual')
                    ->alignEnd()
                    ->color(fn (Account $record): string => AccountBalance::of($record)->isNegative() ? 'danger' : 'success')
                    ->formatStateUsing(fn (Account $record): string => MoneyFormatter::format(AccountBalance::of($record)))
                    ->description(fn (Account $record): ?string => $record->balance_date !== null ? 'desde '.$record->balance_date->format('d/m/Y') : null),
                TextColumn::make('projected_balance')
                    ->label('Projetado (fim do mês)')
                    ->alignEnd()
                    ->color(fn (Account $record): string => AccountBalance::projectedOf($record)->isNegative() ? 'danger' : 'gray')
                    ->formatStateUsing(fn (Account $record): string => MoneyFormatter::format(AccountBalance::projectedOf($record))),
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
                Action::make('setOpeningBalance')
                    ->label('Ajustar saldo')
                    ->icon(Heroicon::OutlinedScale)
                    ->color('gray')
                    ->modalHeading(fn (Account $record): string => 'Ajustar saldo — '.$record->name)
                    ->modalDescription('Informe o saldo real da conta no fim do dia escolhido (o do app ou do extrato do banco). Lançamentos pagos até esse dia continuam no histórico, mas deixam de mexer no saldo.')
                    ->authorize(fn (Account $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->schema([
                        MoneyInput::make('balance')->label('Saldo real')->required(),
                        DatePicker::make('date')->label('No fim do dia')->displayFormat('d/m/Y')->native(false)->default(now())->maxDate(now())->required(),
                    ])
                    ->action(function (Account $record, array $data, Action $action): void {
                        /** @var User $user */
                        $user = auth()->user();

                        try {
                            app(SetOpeningBalance::class)->execute($user, $record, (int) $data['balance'], Carbon::parse($data['date']));
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();

                            return;
                        }

                        Notification::make()->success()->title('Saldo ajustado.')->send();
                    }),
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
