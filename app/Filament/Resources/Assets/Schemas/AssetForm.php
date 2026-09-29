<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Enums\AccountType;
use App\Enums\AssetType;
use App\Enums\Indexer;
use App\Models\Account;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AssetForm
{
    public static function configure(Schema $schema): Schema
    {
        $byBalance = fn (Get $get): bool => AssetType::tryFrom((string) ($get('type') instanceof AssetType ? $get('type')->value : $get('type')))?->isValuedByBalance() ?? false;

        return $schema->columns(2)->components([
            Select::make('type')
                ->label('Tipo')
                ->options(AssetType::options())
                ->live()
                ->required(),
            TextInput::make('name')
                ->label('Nome')
                ->helperText(fn (Get $get): string => $byBalance($get) ? 'Ex.: CDB Banco X 2028, Tesouro IPCA+ 2035, VGBL Seguradora Y.' : 'Ex.: Petrobras PN.')
                ->required()
                ->maxLength(255),
            TextInput::make('ticker')
                ->label('Ticker')
                ->helperText('Código na B3, ex.: PETR4, HGLG11, BOVA11.')
                ->visible(fn (Get $get): bool => ! $byBalance($get))
                ->required(fn (Get $get): bool => ! $byBalance($get))
                ->maxLength(20),
            Select::make('account_id')
                ->label(fn (Get $get): string => $byBalance($get) ? 'Conta' : 'Corretora')
                ->options(fn (Get $get): array => Account::query()
                    ->whereIn('type', $byBalance($get)
                        ? [AccountType::Brokerage->value, AccountType::Checking->value, AccountType::Savings->value]
                        : [AccountType::Brokerage->value])
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->helperText(fn (Get $get): string => $byBalance($get)
                    ? 'De onde saem os aportes e para onde voltam os resgates (corretora, conta corrente ou poupança).'
                    : 'Conta do tipo "Corretora". Compras e vendas movimentam o saldo dela.')
                ->required(),
            TextInput::make('issuer')
                ->label('Emissor / seguradora')
                ->visible($byBalance)
                ->maxLength(255),
            Select::make('indexer')
                ->label('Indexador')
                ->options(Indexer::options())
                ->visible($byBalance),
            TextInput::make('rate')
                ->label('Taxa')
                ->placeholder('110% do CDI, IPCA + 6%…')
                ->visible($byBalance)
                ->maxLength(60),
            DatePicker::make('maturity_date')
                ->label('Vencimento')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->visible($byBalance),
        ]);
    }
}
