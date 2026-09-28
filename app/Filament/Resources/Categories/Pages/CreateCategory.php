<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Domain\Categories\SaveCategory;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCategory extends CreateRecord
{
    use RunsDomainActions;

    protected static string $resource = CategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->runDomainAction(fn () => app(SaveCategory::class)->execute($data));
    }
}
