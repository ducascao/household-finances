<?php

namespace App\Filament\Widgets;

use App\Domain\Accounts\AccountBalance;
use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Models\Account;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class AccountBalancesWidget extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Saldos das contas')
            ->description(function (): string {
                $accounts = AccountBalance::addToQuery(Account::query()->active())->get();
                $format = fn (array $totals): string => implode(' · ', array_map(MoneyFormatter::format(...), $totals));

                return 'Total atual: '.$format(AccountBalance::totalsByCurrency($accounts))
                    .' — projetado até o fim do mês: '.$format(AccountBalance::totalsByCurrency($accounts, projected: true));
            })
            ->query(fn (): Builder => AccountBalance::addToQuery(Account::query()->active()->orderBy('name')))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Conta'),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (AccountType $state): string => $state->label()),
                TextColumn::make('visibility')
                    ->label('Visibilidade')
                    ->badge()
                    ->color(fn (AccountVisibility $state): string => $state === AccountVisibility::Shared ? 'info' : 'gray')
                    ->formatStateUsing(fn (AccountVisibility $state): string => $state->label()),
                TextColumn::make('current_balance')
                    ->label('Saldo atual')
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn (Account $record): string => AccountBalance::of($record)->isNegative() ? 'danger' : 'success')
                    ->formatStateUsing(fn (Account $record): string => MoneyFormatter::format(AccountBalance::of($record))),
                TextColumn::make('projected_balance')
                    ->label('Projetado (fim do mês)')
                    ->alignEnd()
                    ->color(fn (Account $record): string => AccountBalance::projectedOf($record)->isNegative() ? 'danger' : 'gray')
                    ->formatStateUsing(fn (Account $record): string => MoneyFormatter::format(AccountBalance::projectedOf($record))),
            ]);
    }
}
