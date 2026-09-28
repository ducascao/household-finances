<?php

namespace App\Filament\Resources\ImportRules\Pages;

use App\Domain\Import\SaveImportRule;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\ImportRules\ImportRuleResource;
use App\Models\ImportRule;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditImportRule extends EditRecord
{
    use RunsDomainActions;

    protected static string $resource = ImportRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  ImportRule  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->runDomainAction(fn () => app(SaveImportRule::class)->execute($record->household_id, $data, $record));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
