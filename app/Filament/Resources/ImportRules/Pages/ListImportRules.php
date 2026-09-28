<?php

namespace App\Filament\Resources\ImportRules\Pages;

use App\Filament\Resources\ImportRules\ImportRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportRules extends ListRecords
{
    protected static string $resource = ImportRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
