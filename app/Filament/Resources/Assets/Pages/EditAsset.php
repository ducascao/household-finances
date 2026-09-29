<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Domain\Investments\SaveAsset;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAsset extends EditRecord
{
    use RunsDomainActions;

    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  Asset  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(SaveAsset::class)->execute($user, $data, $record));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
