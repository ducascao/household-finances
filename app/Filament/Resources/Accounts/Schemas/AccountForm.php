<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->label('Tipo')
                    ->options(AccountType::options())
                    ->default(AccountType::Checking->value)
                    ->required(),
                Select::make('visibility')
                    ->label('Visibilidade')
                    ->options(AccountVisibility::options())
                    ->default(AccountVisibility::Private->value)
                    ->helperText('Pessoal: só você vê. Compartilhada: todos do lar veem e editam.')
                    ->disabled(fn (?Account $record): bool => $record !== null && Auth::user()?->cannot('changeVisibility', $record))
                    ->dehydrated()
                    ->required(),
                TextInput::make('currency')
                    ->label('Moeda')
                    ->default('BRL')
                    ->length(3)
                    ->required(),
                MoneyInput::make('initial_balance')
                    ->label('Saldo inicial')
                    ->default(0)
                    ->required(),
            ]);
    }
}
