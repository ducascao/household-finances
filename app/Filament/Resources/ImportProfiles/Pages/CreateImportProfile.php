<?php

namespace App\Filament\Resources\ImportProfiles\Pages;

use App\Domain\Import\SaveImportProfile;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\ImportProfiles\ImportProfileResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateImportProfile extends CreateRecord
{
    use RunsDomainActions;

    protected static string $resource = ImportProfileResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(SaveImportProfile::class)->execute($user, $data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
