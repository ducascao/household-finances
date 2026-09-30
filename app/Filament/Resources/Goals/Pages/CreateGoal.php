<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Domain\Goals\SaveGoal;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Goals\GoalResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGoal extends CreateRecord
{
    use RunsDomainActions;

    protected static string $resource = GoalResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(SaveGoal::class)->execute($user, $data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
