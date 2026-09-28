<?php

namespace App\Filament\Resources\Recurrences\Pages;

use App\Domain\Recurrences\CreateRecurrence as CreateRecurrenceAction;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Recurrences\RecurrenceResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRecurrence extends CreateRecord
{
    use RunsDomainActions;

    protected static string $resource = RecurrenceResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(CreateRecurrenceAction::class)->execute($user, $data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
