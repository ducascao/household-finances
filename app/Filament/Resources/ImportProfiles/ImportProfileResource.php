<?php

namespace App\Filament\Resources\ImportProfiles;

use App\Filament\Resources\ImportProfiles\Pages\CreateImportProfile;
use App\Filament\Resources\ImportProfiles\Pages\EditImportProfile;
use App\Filament\Resources\ImportProfiles\Pages\ListImportProfiles;
use App\Filament\Resources\ImportProfiles\Schemas\ImportProfileForm;
use App\Filament\Resources\ImportProfiles\Tables\ImportProfilesTable;
use App\Models\ImportProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ImportProfileResource extends Resource
{
    protected static ?string $model = ImportProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Importação';

    protected static ?string $modelLabel = 'perfil de CSV';

    protected static ?string $pluralModelLabel = 'perfis de CSV';

    protected static ?int $navigationSort = 53;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ImportProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportProfiles::route('/'),
            'create' => CreateImportProfile::route('/create'),
            'edit' => EditImportProfile::route('/{record}/edit'),
        ];
    }
}
