<?php

namespace App\Filament\Resources\ImportRules\Schemas;

use App\Filament\Resources\Transactions\Schemas\TransactionForm;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ImportRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('pattern')
                ->label('Descrição contém')
                ->helperText('Não diferencia maiúsculas nem acentos.')
                ->required()
                ->maxLength(255),
            Toggle::make('ignore')
                ->label('Ignorar linhas que casarem')
                ->helperText('Ex.: "Pagamento recebido" no extrato do cartão (já registrado como transferência).')
                ->live(),
            Select::make('category_id')
                ->label('Categoria')
                ->options(fn (): array => TransactionForm::categoryOptions())
                ->searchable()
                ->hidden(fn (Get $get): bool => (bool) $get('ignore'))
                ->required(fn (Get $get): bool => ! $get('ignore')),
            TextInput::make('description')
                ->label('Descrição amigável')
                ->helperText('Opcional: substitui a descrição do banco.')
                ->hidden(fn (Get $get): bool => (bool) $get('ignore'))
                ->maxLength(255),
        ];
    }
}
