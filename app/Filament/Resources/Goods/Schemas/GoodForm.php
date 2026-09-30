<?php

namespace App\Filament\Resources\Goods\Schemas;

use App\Enums\AccountVisibility;
use App\Enums\GoodType;
use App\Filament\Forms\MoneyInput;
use App\Models\Debt;
use App\Models\Good;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class GoodForm
{
    public static function configure(Schema $schema): Schema
    {
        $shared = fn (Get $get): bool => ($get('visibility') instanceof AccountVisibility ? $get('visibility')->value : $get('visibility')) === AccountVisibility::Shared->value;

        return $schema->components([
            Section::make('Bem')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')->label('Nome')->placeholder('Apartamento, carro…')->required()->maxLength(255),
                    Select::make('type')->label('Tipo')->options(GoodType::options())->default(GoodType::Property->value)->required(),
                    Select::make('visibility')->label('Visibilidade')->options(AccountVisibility::options())
                        ->default(AccountVisibility::Shared->value)->live()->required()
                        ->disabled(fn (?Good $record): bool => $record !== null && $record->owner_id !== Auth::id())
                        ->dehydrated()
                        ->helperText('Compartilhado entra no patrimônio do lar. Pessoal: só você vê.'),
                    Select::make('debt_id')->label('Financiamento (opcional)')
                        ->options(fn (Get $get): array => Debt::query()
                            ->when($shared($get), fn ($query) => $query->whereHas('paymentAccount', fn ($q) => $q->where('visibility', AccountVisibility::Shared->value)))
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('Dívida usada para comprar o bem: mostra o valor líquido (valor − saldo devedor).'),
                    DatePicker::make('acquisition_date')->label('Data de aquisição')->displayFormat('d/m/Y')->native(false)->required(),
                    MoneyInput::make('acquisition_value')->label('Valor de aquisição')->required(),
                    DatePicker::make('sale_date')->label('Data de venda')->displayFormat('d/m/Y')->native(false)
                        ->helperText('Vendido: deixa de contar no patrimônio a partir desta data.'),
                    MoneyInput::make('sale_value')->label('Valor de venda'),
                    Textarea::make('notes')->label('Observações')->columnSpanFull(),
                ]),
        ]);
    }
}
