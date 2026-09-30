<?php

namespace App\Filament\Resources\Goods;

use App\Filament\Resources\Goods\Pages\CreateGood;
use App\Filament\Resources\Goods\Pages\EditGood;
use App\Filament\Resources\Goods\Pages\ListGoods;
use App\Filament\Resources\Goods\Pages\ViewGood;
use App\Filament\Resources\Goods\RelationManagers\GoodValuationsRelationManager;
use App\Filament\Resources\Goods\Schemas\GoodForm;
use App\Filament\Resources\Goods\Schemas\GoodInfolist;
use App\Filament\Resources\Goods\Tables\GoodsTable;
use App\Models\Good;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class GoodResource extends Resource
{
    protected static ?string $model = Good::class;

    protected static ?string $slug = 'bens';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static string|UnitEnum|null $navigationGroup = 'Patrimônio';

    protected static ?string $modelLabel = 'bem';

    protected static ?string $pluralModelLabel = 'bens';

    protected static ?int $navigationSort = 71;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return GoodForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GoodInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoodsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [GoodValuationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoods::route('/'),
            'create' => CreateGood::route('/create'),
            'view' => ViewGood::route('/{record}'),
            'edit' => EditGood::route('/{record}/edit'),
        ];
    }
}
