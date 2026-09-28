<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\Actions\ImportActions;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use Filament\Resources\Pages\ViewRecord;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    public function getTitle(): string
    {
        return 'Revisão da importação';
    }

    protected function getHeaderActions(): array
    {
        return [
            ImportActions::confirm(),
            ImportActions::discard(),
        ];
    }
}
