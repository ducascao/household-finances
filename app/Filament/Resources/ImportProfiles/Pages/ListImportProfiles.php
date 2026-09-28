<?php

namespace App\Filament\Resources\ImportProfiles\Pages;

use App\Filament\Resources\ImportProfiles\ImportProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportProfiles extends ListRecords
{
    protected static string $resource = ImportProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
