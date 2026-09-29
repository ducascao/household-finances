<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Domain\Investments\SaveAsset;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAsset extends CreateRecord
{
    use RunsDomainActions;

    protected static string $resource = AssetResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(SaveAsset::class)->execute($user, $data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
