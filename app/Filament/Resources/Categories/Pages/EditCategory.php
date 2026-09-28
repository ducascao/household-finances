<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Domain\Categories\SaveCategory;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCategory extends EditRecord
{
    use RunsDomainActions;

    protected static string $resource = CategoryResource::class;

    /**
     * @param  Category  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->runDomainAction(fn () => app(SaveCategory::class)->execute($data, $record));
    }
}
