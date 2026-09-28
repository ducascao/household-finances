<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\Actions\ImportActions;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use Filament\Resources\Pages\ListRecords;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportActions::upload(),
        ];
    }
}
