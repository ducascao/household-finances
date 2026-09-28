<?php

namespace App\Filament\Resources\Recurrences;

use App\Filament\Resources\Recurrences\Pages\CreateRecurrence;
use App\Filament\Resources\Recurrences\Pages\EditRecurrence;
use App\Filament\Resources\Recurrences\Pages\ListRecurrences;
use App\Filament\Resources\Recurrences\Schemas\RecurrenceForm;
use App\Filament\Resources\Recurrences\Tables\RecurrencesTable;
use App\Models\Recurrence;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RecurrenceResource extends Resource
{
    protected static ?string $model = Recurrence::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $modelLabel = 'conta fixa';

    protected static ?string $pluralModelLabel = 'contas fixas';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'description';

    public static function form(Schema $schema): Schema
    {
        return RecurrenceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecurrencesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurrences::route('/'),
            'create' => CreateRecurrence::route('/create'),
            'edit' => EditRecurrence::route('/{record}/edit'),
        ];
    }
}
