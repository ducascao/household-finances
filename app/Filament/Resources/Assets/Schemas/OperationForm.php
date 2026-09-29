<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Enums\AssetOperationType;
use App\Enums\AssetType;
use App\Filament\Forms\MoneyInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

class OperationForm
{
    /**
     * @return list<Field>
     */
    public static function fields(AssetType $assetType): array
    {
        $type = fn (Get $get): ?AssetOperationType => AssetOperationType::tryFrom((string) ($get('type') instanceof AssetOperationType ? $get('type')->value : $get('type')));
        $isTrade = fn (Get $get): bool => $type($get)?->isTrade() ?? false;
        $isCashFlow = fn (Get $get): bool => $type($get)?->isCashFlow() ?? false;
        $isSplit = fn (Get $get): bool => ! $isTrade($get) && ! $isCashFlow($get);

        return [
            Select::make('type')
                ->label('Operação')
                ->options(AssetOperationType::optionsFor($assetType))
                ->default($assetType->isValuedByBalance() ? AssetOperationType::Contribution->value : AssetOperationType::Buy->value)
                ->live()
                ->required(),
            DatePicker::make('date')
                ->label('Data')
                ->displayFormat('d/m/Y')
                ->native(false)
                ->default(now())
                ->required(),
            MoneyInput::make('amount')
                ->label(fn (Get $get): string => $type($get) === AssetOperationType::Withdrawal ? 'Valor líquido recebido' : 'Valor aplicado')
                ->visible($isCashFlow)
                ->required($isCashFlow),
            TextInput::make('quantity')
                ->label('Quantidade')
                ->helperText('Aceita fração: 10,5')
                ->visible($isTrade)
                ->required($isTrade),
            TextInput::make('unit_price')
                ->label('Preço unitário (R$)')
                ->helperText('Até 8 casas: 38,4567')
                ->visible($isTrade)
                ->required($isTrade),
            MoneyInput::make('fees')
                ->label('Taxas e emolumentos')
                ->default(0)
                ->helperText('Entram no preço médio (compra) ou saem do valor recebido (venda).')
                ->visible($isTrade),
            TextInput::make('factor')
                ->label('Proporção')
                ->helperText(fn (Get $get): string => $type($get) === AssetOperationType::Split
                    ? 'Desdobramento 1 → N: informe N (ex.: 2 = cada ação vira 2).'
                    : 'Grupamento N → 1: informe N (ex.: 10 = cada 10 ações viram 1).')
                ->visible($isSplit)
                ->required($isSplit),
            Textarea::make('notes')
                ->label('Observações')
                ->columnSpanFull(),
        ];
    }
}
