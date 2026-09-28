<?php

namespace App\Filament\Resources\ImportBatches;

use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Filament\Resources\ImportBatches\RelationManagers\LinesRelationManager;
use App\Filament\Resources\ImportBatches\Schemas\ImportBatchInfolist;
use App\Filament\Resources\ImportBatches\Tables\ImportBatchesTable;
use App\Models\ImportBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Importação';

    protected static ?string $modelLabel = 'importação';

    protected static ?string $pluralModelLabel = 'importações';

    protected static ?int $navigationSort = 51;

    public static function infolist(Schema $schema): Schema
    {
        return ImportBatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportBatches::route('/'),
            'view' => ViewImportBatch::route('/{record}'),
        ];
    }
}
