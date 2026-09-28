<?php

namespace App\Filament\Resources\Recurrences\Pages;

use App\Filament\Resources\Recurrences\RecurrenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecurrences extends ListRecords
{
    protected static string $resource = RecurrenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
