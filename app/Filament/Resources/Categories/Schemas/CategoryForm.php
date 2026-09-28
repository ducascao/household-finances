<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Enums\CategoryType;
use App\Models\Category;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CategoryForm
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
                    ->options(CategoryType::options())
                    ->default(CategoryType::Expense->value)
                    ->live()
                    ->required(),
                Select::make('parent_id')
                    ->label('Categoria pai')
                    ->placeholder('Nenhuma (categoria principal)')
                    ->helperText('No máximo 2 níveis: só categorias principais podem ser pai.')
                    ->options(fn (Get $get, ?Category $record): array => Category::query()
                        ->whereNull('parent_id')
                        ->where('type', $get('type'))
                        ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                ColorPicker::make('color')
                    ->label('Cor'),
            ]);
    }
}
