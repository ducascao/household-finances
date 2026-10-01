<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Enums\AccountType;
use App\Enums\AccountVisibility;
use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                    ->live()
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
                DatePicker::make('balance_date')
                    ->label('Saldo inicial em')
                    ->displayFormat('d/m/Y')
                    ->native(false)
                    ->maxDate(now())
                    ->helperText('Opcional. Saldo real no fim deste dia: lançamentos pagos até ele ficam no histórico, mas não mexem no saldo.'),
                Section::make('Cartão de crédito')
                    ->description('Compras antes do dia de fechamento caem na fatura que fecha no mês; no dia do fechamento ou depois, na seguinte.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::isCreditCard($get))
                    ->schema([
                        TextInput::make('card_closing_day')
                            ->label('Dia de fechamento')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required(fn (Get $get): bool => self::isCreditCard($get)),
                        TextInput::make('card_due_day')
                            ->label('Dia de vencimento')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required(fn (Get $get): bool => self::isCreditCard($get)),
                        MoneyInput::make('card_limit')
                            ->label('Limite'),
                    ]),
            ]);
    }

    private static function isCreditCard(Get $get): bool
    {
        $type = $get('type');

        return ($type instanceof AccountType ? $type->value : $type) === AccountType::CreditCard->value;
    }
}
