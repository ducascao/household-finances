<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Enums\AccountType;
use App\Enums\AssetType;
use App\Models\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('ticker')
                ->label('Ticker')
                ->helperText('Código na B3, ex.: PETR4, HGLG11, BOVA11.')
                ->required()
                ->maxLength(20),
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->label('Tipo')
                ->options(AssetType::options())
                ->required(),
            Select::make('account_id')
                ->label('Corretora')
                ->options(fn (): array => Account::query()->where('type', AccountType::Brokerage->value)->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('Conta do tipo "Corretora". Compras e vendas movimentam o saldo dela.')
                ->required(),
        ]);
    }
}
