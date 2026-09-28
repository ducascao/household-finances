<?php

namespace App\Filament\Resources\ImportRules;

use App\Filament\Resources\ImportRules\Pages\CreateImportRule;
use App\Filament\Resources\ImportRules\Pages\EditImportRule;
use App\Filament\Resources\ImportRules\Pages\ListImportRules;
use App\Filament\Resources\ImportRules\Schemas\ImportRuleForm;
use App\Filament\Resources\ImportRules\Tables\ImportRulesTable;
use App\Models\ImportRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ImportRuleResource extends Resource
{
    protected static ?string $model = ImportRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Importação';

    protected static ?string $modelLabel = 'regra de importação';

    protected static ?string $pluralModelLabel = 'regras de importação';

    protected static ?int $navigationSort = 52;

    protected static ?string $recordTitleAttribute = 'pattern';

    public static function form(Schema $schema): Schema
    {
        return ImportRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRules::route('/'),
            'create' => CreateImportRule::route('/create'),
            'edit' => EditImportRule::route('/{record}/edit'),
        ];
    }
}
