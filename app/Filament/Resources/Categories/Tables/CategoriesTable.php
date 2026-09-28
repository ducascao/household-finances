<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Domain\Categories\DeleteCategory;
use App\Enums\CategoryType;
use App\Models\Category;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Ordena como árvore: cada principal seguida das suas filhas.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('parent')
                ->orderBy('categories.type')
                ->orderByRaw('coalesce((select p.name from categories p where p.id = categories.parent_id), categories.name)')
                ->orderByRaw('categories.parent_id is not null')
                ->orderBy('categories.name'))
            ->columns([
                ColorColumn::make('color')
                    ->label(''),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->formatStateUsing(fn (Category $record): string => $record->parent_id === null ? $record->name : '↳ '.$record->name)
                    ->weight(fn (Category $record): ?string => $record->parent_id === null ? 'bold' : null),
                TextColumn::make('parent.name')
                    ->label('Categoria pai'),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (CategoryType $state): string => $state === CategoryType::Income ? 'success' : 'danger')
                    ->formatStateUsing(fn (CategoryType $state): string => $state->label()),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(CategoryType::options()),
            ])
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->using(function (Category $record): bool {
                        try {
                            app(DeleteCategory::class)->execute($record);
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return false;
                        }

                        return true;
                    }),
            ]);
    }
}
