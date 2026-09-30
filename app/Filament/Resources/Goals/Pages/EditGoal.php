<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Domain\Goals\SaveGoal;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\Goals\GoalResource;
use App\Models\Goal;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Goal $record
 */
class EditGoal extends EditRecord
{
    use RunsDomainActions;

    protected static string $resource = GoalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['account_ids'] = $this->record->accounts()->withoutGlobalScopes()->pluck('accounts.id')->all();
        $data['asset_ids'] = $this->record->assets()->withoutGlobalScopes()->pluck('assets.id')->all();

        return $data;
    }

    /**
     * @param  Goal  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(SaveGoal::class)->execute($user, $data, $record));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
