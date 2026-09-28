<?php

namespace App\Filament\Resources\ImportProfiles\Pages;

use App\Domain\Import\SaveImportProfile;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\ImportProfiles\ImportProfileResource;
use App\Models\ImportProfile;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditImportProfile extends EditRecord
{
    use RunsDomainActions;

    protected static string $resource = ImportProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  ImportProfile  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(SaveImportProfile::class)->execute($user, $data, $record));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
