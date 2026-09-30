<?php

namespace App\Filament\Resources\Goals;

use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Resources\Goals\Pages\ViewGoal;
use App\Filament\Resources\Goals\Schemas\GoalForm;
use App\Filament\Resources\Goals\Schemas\GoalInfolist;
use App\Filament\Resources\Goals\Tables\GoalsTable;
use App\Models\Goal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class GoalResource extends Resource
{
    protected static ?string $model = Goal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Patrimônio';

    protected static ?string $modelLabel = 'meta';

    protected static ?string $pluralModelLabel = 'metas';

    protected static ?int $navigationSort = 72;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return GoalForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GoalInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoals::route('/'),
            'create' => CreateGoal::route('/create'),
            'view' => ViewGoal::route('/{record}'),
            'edit' => EditGoal::route('/{record}/edit'),
        ];
    }
}
