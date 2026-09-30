<?php

namespace App\Filament\Resources\Goods\Pages;

use App\Filament\Resources\Goods\GoodResource;
use App\Filament\Resources\Goods\RelationManagers\GoodValuationsRelationManager;
use App\Models\Good;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property Good $record
 */
class ViewGood extends ViewRecord
{
    protected static string $resource = GoodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GoodValuationsRelationManager::informAction(fn (mixed $record = null): Good => $this->record),
            EditAction::make(),
        ];
    }
}
