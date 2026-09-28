<?php

namespace App\Filament\Resources\ImportRules\Pages;

use App\Domain\Import\SaveImportRule;
use App\Filament\Concerns\RunsDomainActions;
use App\Filament\Resources\ImportRules\ImportRuleResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateImportRule extends CreateRecord
{
    use RunsDomainActions;

    protected static string $resource = ImportRuleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runDomainAction(fn () => app(SaveImportRule::class)->execute((int) $user->current_household_id, $data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
